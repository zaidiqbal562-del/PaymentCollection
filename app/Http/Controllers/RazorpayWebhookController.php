<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRazorpayWebhookEvent;
use App\Models\WebhookEvent;
use App\Services\RazorpayService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use JsonException;

class RazorpayWebhookController extends Controller
{
    public function __invoke(Request $request, RazorpayService $razorpay)
    {
        $signature = $request->header('X-Razorpay-Signature');
        $eventId = $request->header('X-Razorpay-Event-Id');
        $rawPayload = $request->getContent();

        if (! $signature || ! $razorpay->verifyWebhookSignature($rawPayload, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], 401);
        }

        if (! $eventId || strlen($eventId) > 255) {
            return response()->json(['message' => 'A valid Razorpay event ID is required.'], 422);
        }

        try {
            $payload = json_decode($rawPayload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        if (! is_array($payload) || ! is_string($payload['event'] ?? null)) {
            return response()->json(['message' => 'Invalid webhook payload.'], 400);
        }

        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'event_id' => $eventId,
            'event_type' => $payload['event'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'status' => 'received',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $event = WebhookEvent::where('event_id', $eventId)->firstOrFail();

        if (! $event->processed_at) {
            ProcessRazorpayWebhookEvent::dispatch($event->id);
        }

        return response()->json([
            'success' => true,
            'duplicate' => $inserted === 0,
        ], $inserted === 1 ? 202 : 200);
    }
}
