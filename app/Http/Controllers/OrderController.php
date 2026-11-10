<?php

namespace App\Http\Controllers;

use App\Models\Course;
use App\Models\Order;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;
use Throwable;

class OrderController extends Controller
{
    /**
     * Create internal order and Razorpay order.
     */
    public function store(Request $request, RazorpayService $razorpay)
    {
        $validated = $request->validate([
            'course_id' => ['required', 'integer', 'exists:courses,id'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if (
            !$idempotencyKey ||
            strlen($idempotencyKey) < 8 ||
            strlen($idempotencyKey) > 100
        ) {
            return response()->json([
                'success' => false,
                'message' => 'A valid Idempotency-Key header is required.',
            ], 422);
        }

        $userId = $request->user()->id;

        // Check for an existing order with the same idempotency key.
        $existingOrder = Order::where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existingOrder) {
            return $this->existingOrderResponse(
                $existingOrder,
                $validated['course_id']
            );
        }

        // Always retrieve course price from the database.
        $course = Course::findOrFail($validated['course_id']);

        // Convert INR to paise without floating-point arithmetic.
        $price = (string) $course->price;

        if (!preg_match('/^\d+(\.\d{1,2})?$/', $price)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid course price configuration.',
            ], 500);
        }

        [$rupees, $fraction] = array_pad(
            explode('.', $price, 2),
            2,
            ''
        );

        $amountInPaise = ((int) $rupees * 100)
            + (int) str_pad($fraction, 2, '0');

        if ($amountInPaise <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Course price must be greater than zero.',
            ], 422);
        }

        $order = null;

        try {
            // Create internal order in MySQL,to maintain ACID property
            $order = DB::transaction(function () use (
                $userId,
                $course,
                $price,
                $idempotencyKey
            ) {
                $receipt = 'rcpt_' . str_replace(
                    '-',
                    '',
                    (string) Str::uuid()
                );

                $order = new Order();

                $order->user_id = $userId;
                $order->course_id = $course->id;
                $order->order_number = 'ORD-' . strtoupper(
                    Str::random(20)
                );
                $order->amount = $price;
                $order->currency = 'INR';
                $order->status = 'pending';
                $order->receipt = $receipt;
                $order->idempotency_key = $idempotencyKey;

                $order->save();

                return $order;
            });

        } catch (QueryException $exception) {

            // Handle simultaneous duplicate requests.
            $existingOrder = Order::where('user_id', $userId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existingOrder) {
                return $this->existingOrderResponse(
                    $existingOrder,
                    $validated['course_id']
                );
            }

            throw $exception;
        }

        try {
            // Call Razorpay outside the MySQL transaction.
            $gatewayOrder = $razorpay->createOrder(
                $amountInPaise,
                $order->receipt,
                [
                    'order_number' => $order->order_number,
                    'course_id' => (string) $course->id,
                    'user_id' => (string) $userId,
                ]
            );

            // Save Razorpay order reference.
            DB::transaction(function () use ($order, $gatewayOrder) {

                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedOrder->razorpay_order_id = $gatewayOrder['id'];
                $lockedOrder->save();
            });

            return response()->json([
                'success' => true,
                'message' => 'Payment order created successfully.',
                'data' => [
                    'order_number' => $order->order_number,
                    'razorpay_order_id' => $gatewayOrder['id'],
                    'amount' => $gatewayOrder['amount'],
                    'currency' => $gatewayOrder['currency'],
                    'key' => config('services.razorpay.key'),
                ],
            ], 201);

        } catch (Throwable $exception) {

            report($exception);

            // Preserve the internal order for audit and recovery.
            DB::transaction(function () use ($order) {

                $lockedOrder = Order::whereKey($order->id)
                    ->lockForUpdate()->first();

                if ($lockedOrder && !$lockedOrder->razorpay_order_id) {
                    $lockedOrder->status = 'failed';
                    $lockedOrder->failure_reason =
                        'Razorpay order creation failed.';

                    $lockedOrder->save();
                }
            });

            return response()->json([
                'success' => false,
                'message' => 'Unable to initiate payment. Please try again.',
                'order_number' => $order->order_number,
            ], 502);
        }
    }

    /**
     * Verify Razorpay Checkout signature.
     *
     * This does NOT mark the order as paid.
     * Final confirmation happens through the webhook.
     */
    public function verifyPayment(
        Request $request,
        string $orderNumber,
        RazorpayService $razorpay
    ) {
        $validated = $request->validate([
            'razorpay_order_id' => ['required', 'string'],
            'razorpay_payment_id' => ['required', 'string'],
            'razorpay_signature' => ['required', 'string'],
        ]);

        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if (
            $order->status === 'paid' &&
            $order->razorpay_order_id === $validated['razorpay_order_id']
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Payment has already been confirmed.',
            ]);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'This order is no longer awaiting payment.',
            ], 409);
        }

        if (
            $order->razorpay_order_id !==
            $validated['razorpay_order_id']
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Razorpay order mismatch.',
            ], 422);
        }

        $verified = $razorpay->verifyPaymentSignature(
            $validated['razorpay_order_id'],
            $validated['razorpay_payment_id'],
            $validated['razorpay_signature']
        );

        if (!$verified) {
            return response()->json([
                'success' => false,
                'message' => 'Payment signature verification failed.',
            ], 422);
        }

        // Payment capture and enrolment are handled by webhook processing.
        return response()->json([
            'success' => true,
            'message' => 'Payment signature verified. Awaiting confirmation.',
            'order_number' => $order->order_number,
        ], 202);
    }

    public function status(Request $request, string $orderNumber)
    {
        $order = Order::where('order_number', $orderNumber)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'status' => $order->status,
            'paid_at' => $order->paid_at?->toISOString(),
        ]);
    }

    /**
     * Return an existing order for an idempotent request.
     */
    private function existingOrderResponse(
        Order $order,
        int $requestedCourseId
    ) {
        if ((int) $order->course_id !== $requestedCourseId) {
            return response()->json([
                'success' => false,
                'message' => 'Idempotency key cannot be reused for another course.',
            ], 409);
        }

        if ($order->razorpay_order_id) {

            return response()->json([
                'success' => true,
                'message' => 'Existing payment order returned.',
                'data' => [
                    'order_number' => $order->order_number,
                    'razorpay_order_id' => $order->razorpay_order_id,
                    'amount' => (int) round(
                        (float) $order->amount * 100
                    ),
                    'currency' => $order->currency,
                    'key' => config('services.razorpay.key'),
                ],
            ], 200);
        }

        return response()->json([
            'success' => false,
            'message' => 'This order is being processed or requires recovery.',
            'order_number' => $order->order_number,
        ], 409);
    }
}
