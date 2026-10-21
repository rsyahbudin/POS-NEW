<?php

namespace App\Http\Controllers\Print;

use App\Domain\Catalog\Models\Station;
use App\Domain\Ordering\Models\Order;
use App\Domain\Payment\Models\Bill;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\PrintDispatcher;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A device (phone, tablet or laptop) with printers attached opens this page, says which printers it serves,
 * and prints their queued jobs. Jobs wait in the database until some device picks them up.
 */
class PrintStationController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function show(Request $request): Response
    {
        $this->authorizeStation($request);

        return Inertia::render('print-station', [
            'outletId' => $this->outlet->id(),
            'printers' => Printer::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->orderBy('id')->get()->map(fn (Printer $p) => [
                'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'paper_width' => $p->paper_width,
                'station' => $p->station_id ? Station::query()->whereKey($p->station_id)->value('name') : null,
            ])->all(),
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $this->authorizeStation($request);
        $ids = array_values(array_filter(array_map('intval', explode(',', (string) $request->query('printers', '')))));
        $base = PrintJob::query()->where('outlet_id', $this->outlet->id())->whereIn('printer_id', $ids);

        return response()->json([
            'pending' => (clone $base)->where('status', 'pending')->orderBy('id')->limit(20)->get()
                ->map(fn (PrintJob $j) => ['id' => $j->id, 'printer_id' => $j->printer_id, 'kind' => $j->kind, 'reference' => $j->reference, 'payload' => $j->payload])->all(),
            'recent' => (clone $base)->where('status', '!=', 'pending')->latest('id')->limit(10)->get()
                ->map(fn (PrintJob $j) => ['id' => $j->id, 'printer_id' => $j->printer_id, 'kind' => $j->kind, 'reference' => $j->reference, 'status' => $j->status, 'error' => $j->error])->all(),
        ]);
    }

    public function done(PrintJob $job, Request $request): JsonResponse
    {
        $this->authorizeStation($request);
        abort_unless($job->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['ok' => 'required|boolean', 'error' => 'nullable|string|max:200']);

        $job->update($data['ok']
            ? ['status' => 'printed', 'printed_at' => now(), 'error' => null, 'attempts' => $job->attempts + 1]
            : ['status' => 'failed', 'error' => $data['error'] ?? 'Gagal mencetak', 'attempts' => $job->attempts + 1]);

        return response()->json(['ok' => true]);
    }

    public function retry(PrintJob $job, Request $request): JsonResponse
    {
        $this->authorizeStation($request);
        abort_unless($job->outlet_id === $this->outlet->id(), 404);
        $job->update(['status' => 'pending', 'error' => null]);

        return response()->json(['ok' => true]);
    }

    /** Reprint a paid bill's receipt on the receipt printer (labelled COPY, audited). */
    public function reprintReceipt(Bill $bill, Request $request, PrintDispatcher $dispatcher): JsonResponse
    {
        abort_unless($this->actor($request)->can('receive-payment'), 403);
        $queued = $dispatcher->receipt($bill, copy: true);
        Audit::log('receipt.reprinted', $bill, ['order' => $bill->order->number, 'bill' => $bill->id, 'to_printer' => true]);

        return response()->json(['queued' => $queued]);
    }

    /** Print the guest's bill (before payment) on the bill printer. */
    public function printBill(Order $order, Request $request, PrintDispatcher $dispatcher): JsonResponse
    {
        abort_unless($this->actor($request)->can('create-order'), 403);

        return response()->json(['queued' => $dispatcher->bill($order)]);
    }

    private function authorizeStation(Request $request): void
    {
        $u = $request->user();
        abort_unless($u && ($u->can('receive-payment') || $u->can('create-order') || $u->can('update-kitchen-status') || $u->can('manage-settings')), 403);
    }
}
