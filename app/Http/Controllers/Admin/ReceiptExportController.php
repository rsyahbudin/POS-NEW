<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Ordering\Models\Order;
use App\Domain\Reporting\Jobs\ExportReceiptsPdfJob;
use App\Domain\Reporting\Models\ReceiptExport;
use App\Domain\Reporting\ReceiptArchive;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReceiptExportController extends Controller
{
    public function index(OutletContext $outlet): Response
    {
        $first = Order::query()->where('outlet_id', $outlet->id())->min('business_date');
        $from = $first !== null ? (int) substr((string) $first, 0, 4) : (int) now()->year;

        return Inertia::render('admin/receipt-exports', [
            'years' => range((int) now()->year, min($from, (int) now()->year)),
            'current' => ['year' => (int) now()->year, 'month' => (int) now()->month],
            'rows' => ReceiptExport::query()->where('outlet_id', $outlet->id())->with('user:id,name')->latest('id')->limit(30)->get()
                ->map(fn (ReceiptExport $e) => [
                    'id' => $e->id, 'year' => $e->year, 'month' => $e->month, 'status' => $e->status, 'receipts' => $e->receipts,
                    'total' => $e->total, 'size' => $e->size, 'error' => $e->error, 'by' => $e->user?->name,
                    'at' => $e->created_at?->toIso8601String(),
                ])->all(),
        ]);
    }

    public function preview(Request $request, OutletContext $outlet, ReceiptArchive $archive): JsonResponse
    {
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12']]);

        return response()->json($archive->preview($outlet->id(), (int) $d['year'], (int) $d['month']));
    }

    public function store(Request $request, OutletContext $outlet, ReceiptArchive $archive): RedirectResponse
    {
        $d = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'month' => ['required', 'integer', 'between:1,12']]);
        $year = (int) $d['year'];
        $month = (int) $d['month'];

        if ($archive->preview($outlet->id(), $year, $month)['count'] === 0) {
            return back()->with('error', 'Tidak ada transaksi lunas di bulan itu.');
        }
        $busy = ReceiptExport::query()->where('outlet_id', $outlet->id())->where('year', $year)->where('month', $month)->whereIn('status', ['pending', 'running'])->exists();
        if ($busy) {
            return back()->with('error', 'PDF bulan itu sedang dibuat.');
        }

        $export = ReceiptExport::query()->create(['outlet_id' => $outlet->id(), 'user_id' => $request->user()?->id, 'year' => $year, 'month' => $month]);
        Audit::log('receipts.export_requested', $export, ['period' => sprintf('%04d-%02d', $year, $month)]);
        ExportReceiptsPdfJob::dispatch($export->id);

        return back()->with('success', 'PDF struk sedang dibuat.');
    }

    public function download(OutletContext $outlet, ReceiptExport $export): StreamedResponse
    {
        $path = $export->path;
        abort_unless($export->outlet_id === $outlet->id() && $export->status === 'done' && $path !== null, 404);
        abort_unless(Storage::disk(ReceiptArchive::DISK)->exists($path), 404);
        Audit::log('receipts.exported', $export, ['period' => sprintf('%04d-%02d', $export->year, $export->month)]);

        return Storage::disk(ReceiptArchive::DISK)->download($path, $export->filename());
    }

    public function destroy(OutletContext $outlet, ReceiptExport $export, ReceiptArchive $archive): RedirectResponse
    {
        abort_unless($export->outlet_id === $outlet->id() && ! in_array($export->status, ['pending', 'running'], true), 404);
        $archive->delete($export);

        return back()->with('success', 'Berkas dihapus.');
    }
}
