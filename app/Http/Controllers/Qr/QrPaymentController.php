<?php

namespace App\Http\Controllers\Qr;

use App\Domain\Floor\Models\Table;
use App\Domain\Ordering\Exceptions\OrderException;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Models\OnlinePayment;
use App\Domain\Payment\Online\OnlinePaymentService;
use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;

/** Public, table-scoped: a guest asks for the QRIS code of the table's bill and watches it turn paid. */
class QrPaymentController extends Controller
{
    public function __construct(private OnlinePaymentService $service) {}

    public function start(string $token): JsonResponse
    {
        abort_unless($this->service->enabled(), 404);
        $table = Table::query()->where('qr_token', $token)->first();
        abort_unless($table !== null && $table->qr_enabled, 404);
        $order = Order::query()->where('table_id', $table->id)->whereIn('status', ['open', 'partially_paid'])->latest('id')->first();
        if ($order === null) {
            throw new OrderException('Belum ada pesanan untuk dibayar.');
        }

        return response()->json($this->present($this->service->start($order)), 201);
    }

    public function status(string $token, string $publicId): JsonResponse
    {
        $op = $this->find($token, $publicId);

        return response()->json($this->present($this->service->refresh($op)));
    }

    /** Test mode only: lets whoever is trying the QR flow finish the payment themselves. Never available in production. */
    public function simulate(string $token, string $publicId): JsonResponse
    {
        $op = $this->find($token, $publicId);
        abort_unless($this->canSimulate($op), 404);
        $this->service->simulatePaid($op);

        return response()->json($this->present($this->service->refresh($op)));
    }

    private function canSimulate(OnlinePayment $op): bool
    {
        return $op->provider === 'fake' && ! app()->isProduction();
    }

    private function find(string $token, string $publicId): OnlinePayment
    {
        $table = Table::query()->where('qr_token', $token)->firstOrFail();

        return OnlinePayment::query()->where('public_id', $publicId)->whereHas('order', fn ($q) => $q->where('table_id', $table->id))->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function present(OnlinePayment $op): array
    {
        $showQr = $op->status === 'pending' && $op->qr_string !== null;
        $svg = null;
        if ($showQr) {
            $svg = 'data:image/svg+xml;base64,'.base64_encode((new Writer(new ImageRenderer(new RendererStyle(320, 1), new SvgImageBackEnd)))->writeString((string) $op->qr_string));
        }

        return [
            'public_id' => $op->public_id, 'status' => $op->status, 'amount' => $op->amount,
            'expires_at' => $op->expires_at?->toIso8601String(), 'qr' => $svg, 'simulated' => $op->provider === 'fake', 'can_simulate' => $op->status === 'pending' && $this->canSimulate($op),
        ];
    }
}
