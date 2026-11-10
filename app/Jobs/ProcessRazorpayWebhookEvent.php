<?php

namespace App\Jobs;

use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\WebhookEvent;
use App\Notifications\EnrollmentConfirmed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessRazorpayWebhookEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [5, 15, 30, 60];

    public function __construct(public readonly int $eventId) {}

    public function handle(): void
    {
        DB::transaction(function (): ?array {
            $event = WebhookEvent::whereKey($this->eventId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($event->processed_at) {
                return null;
            }

            if (! in_array($event->event_type, [
                'payment.authorized',
                'payment.failed',
                'payment.captured',
                'order.paid',
            ], true)) {
                $this->finish($event, 'ignored');

                return null;
            }

            $gatewayPayment = data_get($event->payload, 'payload.payment.entity');
            if (! is_array($gatewayPayment)) {
                $this->finish($event, 'rejected');

                return null;
            }

            $paymentId = $gatewayPayment['id'] ?? null;
            $gatewayOrderId = $gatewayPayment['order_id'] ?? null;
            $gatewayAmount = $gatewayPayment['amount'] ?? null;
            $currency = $gatewayPayment['currency'] ?? null;

            if (
                ! is_string($paymentId) || $paymentId === '' ||
                ! is_string($gatewayOrderId) || $gatewayOrderId === '' ||
                (! is_int($gatewayAmount) && ! (
                    is_string($gatewayAmount) && ctype_digit($gatewayAmount)
                )) || (int) $gatewayAmount <= 0 || (int) $gatewayAmount > 9999999999 ||
                ! is_string($currency)
            ) {
                $this->finish($event, 'rejected');

                return null;
            }

            $order = Order::where('razorpay_order_id', $gatewayOrderId)
                ->lockForUpdate()
                ->first();

            if (! $order) {
                $this->finish($event, 'rejected');

                return null;
            }

            $attempt = Payment::where('razorpay_payment_id', $paymentId)
                ->lockForUpdate()
                ->first();

            if ($attempt && (int) $attempt->order_id !== (int) $order->id) {
                $this->finish($event, 'rejected');

                return null;
            }

            $isCaptured = ($gatewayPayment['status'] ?? null) === 'captured'
                || ($gatewayPayment['captured'] ?? false) === true;
            $amountMatches = $this->toMinorUnits($order->amount) === (int) $gatewayAmount;
            $currencyMatches = strtoupper($order->currency) === strtoupper($currency);
            $paymentStatus = match ($event->event_type) {
                'payment.authorized' => 'authorized',
                'payment.failed' => 'failed',
                default => $isCaptured ? 'captured' : 'rejected',
            };
            $failureCode = $gatewayPayment['error_code'] ?? null;
            $failureDescription = $gatewayPayment['error_description'] ?? null;

            if ($isCaptured && ! $amountMatches) {
                $failureCode = 'order_amount_mismatch';
                $failureDescription = 'Captured amount does not match the internal order.';
            } elseif ($isCaptured && ! $currencyMatches) {
                $failureCode = 'order_currency_mismatch';
                $failureDescription = 'Captured currency does not match the internal order.';
            }

            $gatewayData = array_intersect_key($gatewayPayment, array_flip([
                'id', 'order_id', 'amount', 'currency', 'status', 'method',
                'captured', 'error_code', 'error_description',
            ]));

            $attempt ??= new Payment;
            $alreadyCaptured = $attempt->status === 'captured';
            $attempt->fill([
                'order_id' => $order->id,
                'razorpay_payment_id' => $paymentId,
                'method' => $gatewayPayment['method'] ?? null,
                'amount' => $this->fromMinorUnits((int) $gatewayAmount),
                'currency' => strtoupper($currency),
                'status' => $alreadyCaptured || $isCaptured ? 'captured' : $paymentStatus,
                'failure_code' => $failureCode,
                'failure_description' => $failureDescription,
                'captured_at' => $isCaptured
                    ? ($attempt->captured_at ?? now())
                    : $attempt->captured_at,
                'gateway_response' => $gatewayData,
            ]);
            $attempt->save();

            if (! $amountMatches || ! $currencyMatches) {
                $this->finish($event, 'rejected');

                return null;
            }

            if (in_array($event->event_type, ['payment.authorized', 'payment.failed'], true)) {
                $this->finish($event, 'processed');

                return null;
            }

            if (! $isCaptured) {
                $this->finish($event, 'rejected');

                return null;
            }

            if ($order->status === 'paid' && $order->payment_id !== $paymentId) {
                $this->finish($event, 'rejected');

                return null;
            }

            if ($order->status !== 'pending' && $order->status !== 'paid') {
                $this->finish($event, 'rejected');

                return null;
            }

            $newlyPaid = $order->status !== 'paid';
            if ($newlyPaid) {
                $order->status = 'paid';
                $order->payment_id = $paymentId;
                $order->paid_at = now();
                $order->failure_reason = null;
                $order->save();
            }

            $enrollment = CourseEnrollment::firstOrCreate([
                'user_id' => $order->user_id,
                'course_id' => $order->course_id,
            ], ['order_id' => $order->id]);

            $this->finish($event, 'processed');

            if ($enrollment->wasRecentlyCreated || $newlyPaid) {
                $order->user->notify(new EnrollmentConfirmed(
                    $order->course->title,
                    $order->order_number
                ));
            }

            return null;
        });
    }

    private function finish(WebhookEvent $event, string $status): void
    {
        $event->status = $status;
        $event->processed_at = now();
        $event->save();
    }

    private function toMinorUnits(string $amount): int
    {
        [$rupees, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $rupees * 100) + (int) str_pad($fraction, 2, '0');
    }

    private function fromMinorUnits(int $amount): string
    {
        return intdiv($amount, 100).'.'.str_pad((string) ($amount % 100), 2, '0', STR_PAD_LEFT);
    }
}
