<?php

namespace App\Http\Controllers;

use App\Domain\Payment\Online\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Payment gateway callback. Authenticity is checked by the gateway's own signature, never by a login. */
class QrisWebhookController extends Controller
{
    public function __invoke(string $provider, Request $request, OnlinePaymentService $service): JsonResponse
    {
        abort_unless(in_array($provider, ['midtrans', 'fake'], true), 404);
        $ok = $service->handleNotification($provider, $request->json()->all() ?: $request->all());

        return response()->json(['ok' => $ok], $ok ? 200 : 403);
    }
}
