<?php

namespace App\Console\Commands;

use App\Domain\Ordering\Events\ProductAvailabilityChanged;
use Illuminate\Console\Command;
use Throwable;

/** Diagnoses "Offline" in the POS: config, Reverb process, and an actual broadcast. */
class RealtimeCheck extends Command
{
    protected $signature = 'pos:realtime-check';

    protected $description = 'Check WebSocket (Reverb) configuration and connectivity.';

    public function handle(): int
    {
        $ok = true;
        $check = function (bool $pass, string $label, string $fix = '') use (&$ok): void {
            $this->line(($pass ? '  [OK]   ' : '  [GAGAL] ').$label);
            if (! $pass) {
                $ok = false;
                if ($fix !== '') {
                    $this->line("         -> {$fix}");
                }
            }
        };

        $check(config('broadcasting.default') === 'reverb', 'BROADCAST_CONNECTION=reverb', 'Set BROADCAST_CONNECTION=reverb di .env lalu php artisan config:cache');
        $check((string) config('reverb.apps.apps.0.key') !== '', 'REVERB_APP_KEY terisi', 'Isi REVERB_APP_ID/KEY/SECRET di .env (php artisan reverb:install)');
        $check((string) config('reverb.apps.apps.0.secret') !== '', 'REVERB_APP_SECRET terisi');

        $host = (string) config('broadcasting.connections.reverb.options.host');
        $port = (int) config('broadcasting.connections.reverb.options.port');
        $this->line("  Server mengirim event ke {$host}:{$port} (".config('broadcasting.connections.reverb.options.scheme').')');
        $socket = @fsockopen($host ?: '127.0.0.1', $port ?: 8080, $errno, $err, 2);
        $check($socket !== false, 'Reverb menerima koneksi di port tersebut', 'Jalankan: php artisan reverb:start (di server: Supervisor program pos-reverb). Cek REVERB_HOST/PORT/SCHEME.');
        if ($socket) {
            fclose($socket);
        }

        try {
            event(new ProductAvailabilityChanged(0, 0, true));
            $check(true, 'Broadcast uji berhasil dikirim');
        } catch (Throwable $e) {
            $check(false, 'Broadcast uji gagal: '.$e->getMessage(), 'Periksa key/secret dan REVERB_HOST/PORT/SCHEME (server -> Reverb).');
        }

        $this->newLine();
        $this->line('Browser terhubung lewat <host halaman>:'.(app()->isProduction() ? '443 (wss) path /app' : (string) config('pos.realtime.local_port')).'.');
        $this->line('Di production pastikan Nginx mem-proxy /app ke 127.0.0.1:'.config('reverb.servers.reverb.port').' (lihat docs/deploy/nginx.conf).');

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
