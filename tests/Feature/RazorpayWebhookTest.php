<?php

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\EnrollmentConfirmed;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    config()->set('services.razorpay.key', 'test-key');
    config()->set('services.razorpay.secret', 'test-secret');
    config()->set('services.razorpay.webhook_secret', 'webhook-secret');
    Notification::fake();
});

it('rejects webhook requests with an invalid signature', function () {
    $response = $this->call(
        'POST',
        '/api/webhooks/razorpay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => 'invalid',
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_invalid_signature',
        ],
        '{"event":"payment.captured"}'
    );

    $response->assertUnauthorized();
    $this->assertDatabaseCount('webhook_events', 0);
});

it('confirms and enrolls a matching captured payment only once', function () {
    $user = User::factory()->create();
    $course = Course::create([
        'title' => 'Laravel Payments',
        'description' => 'Payment course',
        'price' => '120.00',
    ]);
    $order = Order::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'order_number' => 'ORD-TEST-1',
        'amount' => '120.00',
        'currency' => 'INR',
        'status' => 'pending',
        'receipt' => 'rcpt_test_1',
        'razorpay_order_id' => 'order_test_1',
    ]);
    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => ['payment' => ['entity' => [
            'id' => 'pay_test_1',
            'order_id' => 'order_test_1',
            'amount' => 12000,
            'currency' => 'INR',
            'status' => 'captured',
            'captured' => true,
            'method' => 'card',
        ]]],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $payload, 'webhook-secret');

    $sendWebhook = fn () => $this->call(
        'POST',
        '/api/webhooks/razorpay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_payment_captured_1',
        ],
        $payload
    );

    $sendWebhook()->assertAccepted();
    $sendWebhook()->assertOk()->assertJson(['duplicate' => true]);

    expect($order->fresh()->status)->toBe('paid')
        ->and($order->fresh()->payment_id)->toBe('pay_test_1')
        ->and(Payment::where('razorpay_payment_id', 'pay_test_1')->count())->toBe(1)
        ->and(CourseEnrollment::where('user_id', $user->id)->where('course_id', $course->id)->count())->toBe(1);

    $this->assertDatabaseCount('webhook_events', 1);
    Notification::assertSentToTimes($user, EnrollmentConfirmed::class, 1);
});

it('records but does not confirm a captured payment with the wrong amount', function () {
    $user = User::factory()->create();
    $course = Course::create([
        'title' => 'Laravel Payments',
        'description' => 'Payment course',
        'price' => '120.00',
    ]);
    $order = Order::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'order_number' => 'ORD-TEST-2',
        'amount' => '120.00',
        'currency' => 'INR',
        'status' => 'pending',
        'receipt' => 'rcpt_test_2',
        'razorpay_order_id' => 'order_test_2',
    ]);
    $payload = json_encode([
        'event' => 'payment.captured',
        'payload' => ['payment' => ['entity' => [
            'id' => 'pay_test_2',
            'order_id' => 'order_test_2',
            'amount' => 11900,
            'currency' => 'INR',
            'status' => 'captured',
            'captured' => true,
        ]]],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $payload, 'webhook-secret');

    $this->call(
        'POST',
        '/api/webhooks/razorpay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_payment_mismatch_1',
        ],
        $payload
    )->assertAccepted();

    expect($order->fresh()->status)->toBe('pending')
        ->and(Payment::where('razorpay_payment_id', 'pay_test_2')->value('status'))->toBe('captured')
        ->and(Payment::where('razorpay_payment_id', 'pay_test_2')->value('failure_code'))->toBe('order_amount_mismatch')
        ->and(CourseEnrollment::count())->toBe(0);
});

it('records failed payment attempts without changing the order or enrolling the user', function () {
    $user = User::factory()->create();
    $course = Course::create([
        'title' => 'Laravel Payments',
        'description' => 'Payment course',
        'price' => '120.00',
    ]);
    $order = Order::create([
        'user_id' => $user->id,
        'course_id' => $course->id,
        'order_number' => 'ORD-TEST-3',
        'amount' => '120.00',
        'currency' => 'INR',
        'status' => 'pending',
        'receipt' => 'rcpt_test_3',
        'razorpay_order_id' => 'order_test_3',
    ]);
    $payload = json_encode([
        'event' => 'payment.failed',
        'payload' => ['payment' => ['entity' => [
            'id' => 'pay_test_3',
            'order_id' => 'order_test_3',
            'amount' => 12000,
            'currency' => 'INR',
            'status' => 'failed',
            'error_code' => 'BAD_REQUEST_ERROR',
            'error_description' => 'Payment declined',
        ]]],
    ], JSON_THROW_ON_ERROR);
    $signature = hash_hmac('sha256', $payload, 'webhook-secret');

    $this->call(
        'POST',
        '/api/webhooks/razorpay',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_RAZORPAY_SIGNATURE' => $signature,
            'HTTP_X_RAZORPAY_EVENT_ID' => 'evt_payment_failed_1',
        ],
        $payload
    )->assertAccepted();

    expect($order->fresh()->status)->toBe('pending')
        ->and(Payment::where('razorpay_payment_id', 'pay_test_3')->value('status'))->toBe('failed')
        ->and(Payment::where('razorpay_payment_id', 'pay_test_3')->value('failure_code'))->toBe('BAD_REQUEST_ERROR')
        ->and(CourseEnrollment::count())->toBe(0);
});

it('keeps payment status polling private to the owning user', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $course = Course::create([
        'title' => 'Private course',
        'description' => 'Private order',
        'price' => '10.00',
    ]);
    $order = Order::create([
        'user_id' => $owner->id,
        'course_id' => $course->id,
        'order_number' => 'ORD-PRIVATE-1',
        'amount' => '10.00',
        'currency' => 'INR',
        'status' => 'pending',
        'receipt' => 'rcpt_private_1',
    ]);

    $this->actingAs($otherUser)
        ->getJson('/orders/'.$order->order_number.'/status')
        ->assertNotFound();
});
