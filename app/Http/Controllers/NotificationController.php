<?php

namespace App\Http\Controllers;

use App\Domain\Notification\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private Notifier $notifier) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->notifier->feed($this->actor($request)));
    }

    public function read(Request $request, int $id): JsonResponse
    {
        $this->notifier->markRead($this->actor($request), $id);

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $this->notifier->markAllRead($this->actor($request));

        return response()->json(['ok' => true]);
    }
}
