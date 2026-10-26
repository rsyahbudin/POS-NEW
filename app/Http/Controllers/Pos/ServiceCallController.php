<?php

namespace App\Http\Controllers\Pos;

use App\Domain\Floor\Models\ServiceCall;
use App\Domain\Floor\ServiceCalls;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff side of "Panggil waiter": the open calls for this screen, and marking one as answered. */
class ServiceCallController extends Controller
{
    public function __construct(private ServiceCalls $calls, private OutletContext $outlet) {}

    public function index(Request $request): JsonResponse
    {
        $screen = $request->query('to') === 'kitchen' ? 'kitchen' : 'pos';

        return response()->json(['calls' => $this->calls->pending($screen)]);
    }

    public function done(ServiceCall $call, Request $request): JsonResponse
    {
        abort_unless($call->outlet_id === $this->outlet->id(), 404);
        $this->calls->resolve($call, $this->actor($request));

        return response()->json(['ok' => true]);
    }
}
