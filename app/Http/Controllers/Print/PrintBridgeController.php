<?php

namespace App\Http\Controllers\Print;

use App\Domain\Notification\Notifier;
use App\Domain\Printing\Models\Printer;
use App\Domain\Printing\Models\PrintJob;
use App\Domain\Settings\OutletContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** What the print bridge in the cafe calls: queued jobs of network printers, and the result of each one. */
class PrintBridgeController extends Controller
{
    public function __construct(private OutletContext $outlet, private Notifier $notifier) {}

    /** A ticket that waited this long is no use to the kitchen any more; it is parked as failed so the manager can reprint it on purpose. */
    private const STALE_MINUTES = 30;

    public function jobs(): JsonResponse
    {
        $parked = PrintJob::query()->where('outlet_id', $this->outlet->id())->where('status', 'pending')->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
            ->whereIn('printer_id', Printer::query()->whereNotNull('host')->select('id'))
            ->update(['status' => 'failed', 'error' => 'Kedaluwarsa: jembatan cetak tidak aktif saat dicetak.']);
        if ($parked > 0) {
            $this->notifier->notify(
                $this->outlet->id(), 'print.expired', "{$parked} cetakan tidak sempat keluar", 'Jembatan cetak sempat mati, jadi cetakan lama tidak dicetak. Cetak ulang yang masih perlu dari halaman Printer.',
                '/admin/printers', 'warning', ['manage-printers', 'receive-payment'],
            );
        }

        $printers = Printer::query()->where('outlet_id', $this->outlet->id())->where('is_active', true)->whereNotNull('host')->get()->keyBy('id');

        $jobs = PrintJob::query()->where('outlet_id', $this->outlet->id())->whereIn('printer_id', $printers->keys())->where('status', 'pending')
            ->orderBy('id')->limit(20)->get();

        return response()->json(['jobs' => $jobs->map(function (PrintJob $j) use ($printers) {
            $p = $printers->get($j->printer_id);

            return $p === null ? null : [
                'id' => $j->id, 'kind' => $j->kind, 'reference' => $j->reference, 'payload' => $j->payload,
                'printer' => ['id' => $p->id, 'name' => $p->name, 'host' => $p->host, 'port' => $p->port, 'paper_width' => $p->paper_width],
            ];
        })->filter()->values()->all()]);
    }

    public function done(PrintJob $job, Request $request): JsonResponse
    {
        abort_unless($job->outlet_id === $this->outlet->id(), 404);
        $data = $request->validate(['ok' => 'required|boolean', 'error' => 'nullable|string|max:200']);

        $job->update($data['ok']
            ? ['status' => 'printed', 'printed_at' => now(), 'error' => null, 'attempts' => $job->attempts + 1]
            : ['status' => 'failed', 'error' => $data['error'] ?? 'Gagal mencetak', 'attempts' => $job->attempts + 1]);

        // A failed ticket is something staff must act on; the alert goes away on the next ticket that prints from the same printer.
        $key = "print.failed:{$job->printer_id}";
        if ($data['ok']) {
            $this->notifier->resolve($job->outlet_id, $key);
        } else {
            $name = Printer::query()->whereKey($job->printer_id)->value('name');
            $this->notifier->notify(
                $job->outlet_id, 'print.failed', "Gagal cetak di {$name}", trim("{$job->kind} {$job->reference}: ".($data['error'] ?? 'Gagal mencetak').'. Cek kertas, kabel, dan daya printer, lalu cetak ulang.'),
                '/admin/printers', 'danger', ['manage-printers', 'receive-payment', 'create-order'], null, $key,
            );
        }

        return response()->json(['ok' => true]);
    }
}
