<?php

namespace App\Services;

use Razorpay\Api\Api;
use RuntimeException;
use Throwable;

class RazorpayService
{
    protected Api $razorpay;

    public function __construct()
    {
        $key = config('services.razorpay.key');
        $secret = config('services.razorpay.secret');

        if (empty($key) || empty($secret)) {
            throw new RuntimeException(
                'Razorpay API credentials are not configured.'
            );
        }

        $this->razorpay = new Api($key, $secret);
    }

    /**
     * Create an order on Razorpay.
     */
    public function createOrder(
        int $amountInPaise,
        string $receipt,
        array $notes = []
    ): array {
        $order = $this->razorpay->order->create([
            'amount' => $amountInPaise,
            'currency' => 'INR',
            'receipt' => $receipt,
            'notes' => $notes,
        ]);

        return [
            'id' => (string) $order['id'],
            'amount' => (int) $order['amount'],
            'currency' => (string) $order['currency'],
            'status' => (string) $order['status'],
            'receipt' => (string) $order['receipt'],
        ];
    }

    /**
     * Verify Razorpay Checkout signature.
     */
    public function verifyPaymentSignature(
        string $orderId,
        string $paymentId,
        string $signature
    ): bool {
        try {
            $this->razorpay->utility->verifyPaymentSignature([
                'razorpay_order_id' => $orderId,
                'razorpay_payment_id' => $paymentId,
                'razorpay_signature' => $signature,
            ]);

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * Verify Razorpay Webhook signature.
     */
    public function verifyWebhookSignature(
        string $payload,
        string $signature
    ): bool {
        try {
            $webhookSecret = config(
                'services.razorpay.webhook_secret'
            );

            if (empty($webhookSecret)) {
                return false;
            }

            $this->razorpay->utility->verifyWebhookSignature(
                $payload,
                $signature,
                $webhookSecret
            );

            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
