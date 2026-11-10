# Razorpay Payment Integration

This document describes the payment integration as implemented in this Laravel application: how an order is created, how Razorpay Checkout is opened, what the checkout signature verifies, how the webhook is authenticated and processed, how local payment and enrollment records are updated, and how to operate and troubleshoot the queue.

## At A Glance

The payment flow has two separate confirmation steps:

1. The browser sends the Checkout result to the application. The application verifies Razorpay's checkout signature and responds that the signature is valid.
2. Razorpay sends a signed webhook to the application. A queued job validates the event against the local order, records the payment attempt, and, for a matching captured payment, marks the order paid and enrolls the user.

**A valid Checkout signature is not the final paid state.** The order's authoritative `paid` state is set by webhook processing. The webhook endpoint queues processing and can return before the payment/order records are updated.

## Components

- `app/Http/Controllers/OrderController.php`: creates internal and Razorpay orders, verifies the Checkout signature, and serves the authenticated order status endpoint.
- `app/Services/RazorpayService.php`: wraps the Razorpay SDK for order creation and Checkout/webhook signature verification.
- `app/Http/Controllers/RazorpayWebhookController.php`: validates and stores incoming webhook events, deduplicates event IDs, and dispatches processing jobs.
- `app/Jobs/ProcessRazorpayWebhookEvent.php`: processes supported payment events and performs payment, order, enrollment, and notification updates.
- `resources/views/courses/index.blade.php`: launches Razorpay Checkout and polls the application for order status.
- `config/services.php`: maps the Razorpay environment variables into Laravel configuration.
- `config/queue.php`: configures the queue driver. The current `.env` uses the database queue.

## Configuration

Configure these values in the application's environment (for example, `.env`) and keep the values out of source control:

```dotenv
RAZORPAY_KEY_ID=...
RAZORPAY_KEY_SECRET=...
RAZORPAY_WEBHOOK_SECRET=...
QUEUE_CONNECTION=database
```

`RAZORPAY_KEY_ID` is returned to the browser as the Checkout key. `RAZORPAY_KEY_SECRET` is used by the SDK for authenticated Razorpay API operations and Checkout signature verification. `RAZORPAY_WEBHOOK_SECRET` must match the webhook secret configured for this endpoint in the Razorpay Dashboard; it is used only for webhook signature verification. Do not substitute the API key secret for the webhook secret unless they were deliberately configured to be the same value.

The webhook endpoint is:

```text 
POST /api/webhooks/razorpay
```

Configure that URL and the intended payment events in the Razorpay Dashboard. The application recognizes `payment.authorized`, `payment.failed`, `payment.captured`, and `order.paid`. The webhook request must include `X-Razorpay-Signature` and `X-Razorpay-Event-Id` headers. The signature is calculated against the exact raw request body, so middleware, proxies, or manual reserialization must not alter the body before Laravel verifies it.

After changing environment configuration, refresh Laravel's cached configuration if the application uses config caching:

```bash
php artisan config:clear
```
*
## End-To-End Flow
*
### 1. Create an order

The signed-in browser sends `POST /orders` with a JSON body containing `course_id` and an `Idempotency-Key` header. The route requires authentication and is throttled to 10 requests per minute.

Example request:

```http
POST /orders
Accept: application/json
Content-Type: application/json
Idempotency-Key: 2bd9b3c2-3f18-47b6-9c2c-d3d70d4a27b0
X-CSRF-TOKEN: <session CSRF token>

{"course_id": 42}
```

The server validates the course, reads its price from the database (the client does not choose the amount), converts the decimal INR price to paise using integer arithmetic, and creates a local `orders` row with `pending` status. It then creates the Razorpay order and stores the returned Razorpay order ID on the local order. The Razorpay API call is intentionally outside the database transaction.

Successful first creation returns HTTP `201`; reusing the same idempotency key for the same course returns the existing Razorpay order with HTTP `200`. The response has this shape:

```json
{
  "success": true,
  "message": "Payment order created successfully.",
  "data": {
    "order_number": "ORD-...",
    "razorpay_order_id": "order_...",
    "amount": 12000,
    "currency": "INR",
    "key": "rzp_..."
  }
}
```

`amount` is in paise (for example, INR 120.00 is `12000`). The browser receives the public key ID, not the API secret. An invalid/missing idempotency key returns `422`; reusing a key for another course returns `409`. If Razorpay order creation fails, the application reports the exception, marks the local order failed when no Razorpay order was saved, and returns `502` with the local `order_number`. A retry with that same key may return `409` because the existing local order has no Razorpay order reference; that order needs operational recovery or a new idempotency key.

### 2. Open Razorpay Checkout

The course page loads Razorpay Checkout's JavaScript SDK and initializes it with the server response's `key`, `amount`, `currency`, and `razorpay_order_id`. The browser does not submit a trusted amount or create a Razorpay order directly. If Checkout is dismissed, the page allows a retry using the same idempotency key stored in `sessionStorage`.

On successful Checkout, Razorpay calls the browser handler with fields including `razorpay_order_id`, `razorpay_payment_id`, and `razorpay_signature`.

### 3. Verify the Checkout signature

The browser posts that result to:

```text
POST /orders/{orderNumber}/verify-payment
```

This route requires the authenticated owner of the order, validates the required fields, checks that the supplied Razorpay order ID matches the local order, and calls the Razorpay SDK to verify the Checkout signature using the API key secret. The route is throttled to 10 requests per minute.

Possible responses include:

- `200`: the order was already confirmed as paid.
- `202`: the signature is valid; webhook confirmation is still pending.
- `409`: the order is no longer pending.
- `422`: invalid input, Razorpay order mismatch, or signature verification failure.
- `404`: no matching order belongs to the authenticated user.

The handler does **not** create a `payments` row, set `orders.status` to `paid`, or enroll the user. This prevents a browser callback alone from being treated as final settlement. After a successful `202`, the browser polls the status endpoint.

### 4. Receive and authenticate a webhook

The public webhook route accepts the raw JSON request and reads `X-Razorpay-Signature` and `X-Razorpay-Event-Id`. It verifies the HMAC using the configured webhook secret. Signature verification happens before JSON parsing or database insertion; missing/invalid signature returns HTTP `401` and does not create an event row.

After verification, the controller requires a non-empty event ID no longer than 255 characters (`422` otherwise), valid JSON (`400` otherwise), and a string `event` property (`400` otherwise). It stores the event ID, event type, full decoded payload, and initial status `received` in `webhook_events`. The event ID has a unique constraint, making delivery idempotent.

A newly stored event is dispatched to `ProcessRazorpayWebhookEvent` and the endpoint returns HTTP `202`:

```json
{"success": true, "duplicate": false}
```

A duplicate event ID returns HTTP `200` with `duplicate: true`. If that event is not yet marked processed, the controller dispatches it again; the job also checks `processed_at` under a row lock, so repeated deliveries do not repeat successful fulfillment.

A `202` means **accepted for asynchronous processing**, not that the order was paid. This endpoint currently does not write a log line for each response; use `webhook_events`, `jobs`, and `failed_jobs` to inspect processing.

### 5. Process the event in the queue

The job locks the webhook event and exits if it has already been processed. It ignores event types outside the supported list and expects the payment object at `payload.payment.entity`. For supported events, it extracts the Razorpay payment ID, Razorpay order ID, amount in paise, currency, capture state, method, and any failure details.

Before fulfillment, the job:

- Finds and locks the local order using `razorpay_order_id`.
- Rejects a payment ID already associated with a different local order.
- Compares the webhook amount (paise) with the local order amount converted to paise.
- Compares currency case-insensitively.
- Upserts a `payments` row keyed by the unique Razorpay payment ID and saves a limited gateway response snapshot.

For a captured payment with an amount or currency mismatch, the payment attempt is still recorded, with `failure_code` set to `order_amount_mismatch` or `order_currency_mismatch`. The webhook event is marked `rejected`; the order remains unpaid and the user is not enrolled.

For `payment.authorized` and `payment.failed`, the payment attempt is recorded with its corresponding status and the event is marked `processed`, but the order remains pending. A failed payment attempt does not permanently fail the order, allowing another attempt. For capture/paid events, the job requires a captured payment, matching amount/currency, and an order in `pending` or `paid` state. It then:

1. Saves the payment as `captured` and records `captured_at`.
2. Changes a pending order to `paid`, sets its `payment_id` and `paid_at`, and clears `failure_reason`.
3. Creates the `course_enrollments` row if one does not already exist.
4. Marks the webhook event `processed`.
5. Queues the `EnrollmentConfirmed` email when the enrollment was newly created or the order was newly paid.

A repeated event for the same already-paid order and payment does not create a second enrollment. A paid order cannot be taken over by a different payment ID. Events that do not meet the conditions above are marked `rejected` and do not enroll the user.

The job retries up to five times with backoff delays of 5, 15, 30, and 60 seconds. A job that exhausts retries should be investigated in `failed_jobs`; an event left as `received` indicates it was not successfully finalized.

### 6. Poll order status

After Checkout signature verification, the course page requests:

```text
GET /orders/{orderNumber}/status
```

This endpoint requires authentication, only returns an order owned by the current user, and is throttled to 30 requests per minute. It returns:

```json
{
  "success": true,
  "status": "pending",
  "paid_at": null
}
```

The browser polls up to 30 times at 2-second intervals (about one minute). On `paid`, it clears the idempotency key and shows enrollment confirmation. On `failed`, it enables retry. If the order remains pending for the full polling window, the browser says processing is ongoing and allows the user to check again. In particular, a `payment.failed` webhook only fails that payment attempt; the order stays pending, so the browser may poll until timeout and the user can attempt payment again.

## Data Model And Statuses

- `orders`: local business order. Important fields include `order_number`, `razorpay_order_id`, `amount` (decimal INR), `currency`, `status`, `payment_id`, `paid_at`, `receipt`, and `failure_reason`. Normal lifecycle is `pending` to `paid`; order creation can mark it `failed` if Razorpay order creation fails.
- `payments`: individual gateway payment attempt. Important fields include `razorpay_payment_id`, `order_id`, `amount` (decimal INR), `currency`, `status`, `method`, `failure_code`, `failure_description`, `captured_at`, and `gateway_response`. Multiple attempts may exist for an order.
- `webhook_events`: one row per Razorpay event ID, including event type, full payload, processing `status`, and `processed_at`. Typical statuses are `received`, `processed`, `ignored`, and `rejected`.
- `course_enrollments`: enrollment relation, unique per user/course and per order.

The order is the source for access/fulfillment state. A payment row with status `captured` is not, by itself, sufficient proof that the order passed all amount/currency and order-state checks; confirm `orders.status` and the related enrollment too.

## Queue Operations

The current configuration uses `QUEUE_CONNECTION=database`, so the web request stores a job in the `jobs` table but does not process it. Run a worker continuously in production (typically under a process manager such as systemd or Supervisor):

```bash
php artisan queue:work --tries=5
```

For a one-time local drain of queued jobs:

```bash
php artisan queue:work --stop-when-empty --tries=5
```

Run the worker with the same application environment and database/queue configuration as the web process. If the endpoint returns `202` but the order stays pending, first check whether a worker is alive and whether jobs are accumulating. Do not repeatedly replay webhook events as a substitute for a worker; Razorpay retries and the event-ID/processed guards are for delivery idempotency, not queue availability.

## Troubleshooting Checklist

### Endpoint returns `401`

- Confirm the request includes `X-Razorpay-Signature`.
- Confirm `RAZORPAY_WEBHOOK_SECRET` exactly matches the secret for this webhook in the Razorpay Dashboard.
- Make sure the signature is checked against the exact raw request body and that no proxy has changed the body.
- A `401` occurs before the event is persisted, so there will be no corresponding `webhook_events` row.

### Endpoint returns `400` or `422`

- `400` means invalid JSON or a missing/non-string `event` field.
- `422` means the event ID header is missing or invalid.
- These validation failures happen before queue dispatch.

### Endpoint returns `202`, but the order remains pending

1. Look up the event in `webhook_events` by event ID. `received` with a null `processed_at` means processing has not finalized.
2. Check the `jobs` table and confirm a Laravel queue worker is running against the same database and queue name.
3. Check `failed_jobs` and application logs for exceptions after the job is consumed.
4. If the event is `rejected`, inspect its payment's `failure_code` and `failure_description`, and compare the payload's order ID, amount, and currency with the local order.
5. Confirm the event type is one of the supported types and that the payload contains `payload.payment.entity` with a captured payment state for a successful fulfillment.

### Checkout says signature verified, but no paid status appears

This is expected until the webhook's queued job runs. The Checkout signature authenticates the Checkout result; it is not the final order update. Ensure the webhook is configured, reaching this endpoint, and the database queue worker is running.

### A payment was captured but the order was rejected

Check for an amount/currency mismatch, missing or unknown Razorpay order ID, a payment ID linked to another order, a non-captured payload, or an order already finalized with another payment. The job deliberately does not enroll on these mismatches. Reconcile such a payment through an authorized operational process; do not manually mark orders paid without validating the Razorpay payment and local amount/order.

## Current Diagnostic Snapshot

During the investigation that produced this guide, the database contained six webhook events with status `received`, six pending database-queue jobs, and zero failed jobs. No `queue:work`/`queue:listen` process was found. This indicates the endpoint had accepted and persisted the requests, but background processing had not started. The Laravel log file was empty at that time. This snapshot can change after a worker processes the queue; use live database state when troubleshooting later.

The focused webhook feature tests could not run in that environment because PHP reported `could not find driver` for the in-memory SQLite connection. Install/enable the PHP SQLite PDO extension before rerunning them.

## Security And Operational Notes

- Never expose `RAZORPAY_KEY_SECRET` or `RAZORPAY_WEBHOOK_SECRET` to the browser, logs, screenshots, or source control. The Checkout key ID is public by design.
- Always calculate amounts on the server from the persisted course price and verify the webhook amount/currency against the internal order.
- Keep the webhook route public to Razorpay, but protect it with signature verification and event-ID deduplication. Checkout/order/status routes remain behind user authentication.
- Keep a supervised queue worker running and monitor queue age, failed jobs, and webhook events that remain `received`.
- The webhook table stores the complete incoming payload for audit/troubleshooting. Apply appropriate access controls, retention, and data-handling practices to that table.
