<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Catalog\Models\Station;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Printing\PrintBridge;
use App\Domain\Printing\PrintDispatcher;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Which printers exist and what each one prints. The device a printer is plugged into is set on the print station page. */
class PrinterController extends Controller
{
    public function __construct(private OutletContext $outlet) {}

    public function index(PrintBridge $bridge): Response
    {
        return Inertia::render('admin/printers', [
            'printers' => Printer::query()->where('outlet_id', $this->outlet->id())->orderBy('id')->get()->map(fn (Printer $p) => [
                'id' => $p->id, 'name' => $p->name, 'kind' => $p->kind, 'station_id' => $p->station_id,
                'paper_width' => $p->paper_width, 'copies' => $p->copies, 'is_active' => $p->is_active, 'host' => $p->host, 'port' => $p->port,
            ])->all(),
            'stations' => Station::query()->where('outlet_id', $this->outlet->id())->orderBy('sort')->get(['id', 'name'])->all(),
            'bridge' => ['has_token' => $bridge->hasToken(), 'online' => $bridge->online(), 'last_seen' => $bridge->lastSeen()?->toIso8601String(), 'url' => url('/')],
            'failed' => PrintJob::query()->where('outlet_id', $this->outlet->id())->where('status', 'failed')->whereIn('printer_id', Printer::query()->whereNotNull('host')->select('id'))
                ->latest('id')->limit(10)->get()->map(fn (PrintJob $j) => [
                    'id' => $j->id, 'printer' => Printer::query()->whereKey($j->printer_id)->value('name'), 'kind' => $j->kind, 'reference' => $j->reference,
                    'error' => $j->error, 'at' => $j->updated_at?->toIso8601String(),
                ])->all(),
            'kinds' => collect(Printer::KINDS)->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])->values()->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $printer = Printer::create($this->data($request) + ['outlet_id' => $this->outlet->id()]);
        PrintDispatcher::forgetRouting($this->outlet->id());
        Audit::log('printer.created', $printer, ['name' => $printer->name, 'kind' => $printer->kind]);

        return back()->with('success', 'Printer ditambahkan.');
    }

    public function update(Request $request, Printer $printer): RedirectResponse
    {
        abort_unless($printer->outlet_id === $this->outlet->id(), 404);
        $printer->update($this->data($request));
        PrintDispatcher::forgetRouting($this->outlet->id());
        Audit::log('printer.updated', $printer, ['name' => $printer->name, 'kind' => $printer->kind]);

        return back()->with('success', 'Printer disimpan.');
    }

    public function destroy(Printer $printer): RedirectResponse
    {
        abort_unless($printer->outlet_id === $this->outlet->id(), 404);
        $printer->delete();
        PrintDispatcher::forgetRouting($this->outlet->id());
        Audit::log('printer.deleted', null, ['name' => $printer->name]);

        return back()->with('success', 'Printer dihapus.');
    }

    /** Puts a failed network-printer job back in the queue for the bridge. */
    public function retryJob(PrintJob $job): RedirectResponse
    {
        abort_unless($job->outlet_id === $this->outlet->id() && $job->status === 'failed', 404);
        $job->update(['status' => 'pending', 'error' => null, 'created_at' => now()]);

        return back()->with('success', 'Dicetak ulang lewat jembatan cetak.');
    }

    /** Makes a new bridge token. It is shown once; the old one stops working. */
    public function bridgeToken(PrintBridge $bridge): JsonResponse
    {
        $token = $bridge->issue();
        Audit::log('printer.bridge_token_issued', null, []);

        return response()->json(['token' => $token]);
    }

    /** @return array<string, mixed> */
    private function data(Request $request): array
    {
        $data = $request->validate([
            'name' => 'required|string|max:60',
            'kind' => ['required', Rule::in(array_keys(Printer::KINDS))],
            'station_id' => ['nullable', 'integer', Rule::exists('stations', 'id')->where('outlet_id', $this->outlet->id())],
            'paper_width' => ['required', Rule::in([58, 80])],
            'copies' => 'required|integer|min:1|max:5',
            'host' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$/'],
            'port' => 'nullable|integer|min:1|max:65535',
            'is_active' => 'boolean',
        ]);
        $data['station_id'] = $data['kind'] === 'ticket' ? ($data['station_id'] ?? null) : null;
        $data['host'] = ($data['host'] ?? '') === '' ? null : $data['host'];
        $data['port'] = (int) ($data['port'] ?? 9100);
        $data['is_active'] = (bool) ($data['is_active'] ?? true);

        return $data;
    }
}
