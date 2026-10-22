<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Payment\Models\OnlinePayment;
use App\Domain\Payment\Online\OnlinePaymentService;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class OnlinePaymentController extends Controller
{
    public function index(OutletContext $outlet, OnlinePaymentService $service): Response
    {
        $rows = OnlinePayment::query()->where('outlet_id', $outlet->id())->with('order:id,number')->latest('id')->limit(200)->get();

        return Inertia::render('admin/online-payments', [
            'enabled' => $service->enabled(),
            'webhook' => url('/webhooks/qris/midtrans'),
            'rows' => $rows->map(fn (OnlinePayment $p) => [
                'id' => $p->id, 'at' => $p->created_at?->toIso8601String(), 'order' => $p->order->number, 'provider' => $p->provider, 'amount' => $p->amount,
                'status' => $p->status, 'applied' => $p->applied_at !== null, 'error' => $p->error, 'ref' => $p->provider_ref,
                'simulatable' => $p->provider === 'fake' && $p->status === 'pending',
            ])->all(),
        ]);
    }

    public function retry(OnlinePayment $onlinePayment, OnlinePaymentService $service): RedirectResponse
    {
        $service->apply($onlinePayment);
        $onlinePayment->refresh();

        return $onlinePayment->applied_at !== null ? back()->with('success', 'Pembayaran tercatat.') : back()->with('error', $onlinePayment->error ?? 'Belum bisa dicatat.');
    }

    public function simulate(OnlinePayment $onlinePayment, OnlinePaymentService $service): RedirectResponse
    {
        abort_unless($onlinePayment->provider === 'fake', 404);
        $service->simulatePaid($onlinePayment);

        return back()->with('success', 'Simulasi: pembayaran diterima.');
    }
}
