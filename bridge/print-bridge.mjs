// Print bridge: runs on an always-on device in the cafe (mini PC, Raspberry Pi, laptop) with Node 18 or newer.
// It asks the POS server for the queued jobs of network printers and sends them to the printers by IP (raw ESC/POS, port 9100).
//
//   node print-bridge.mjs --url https://pos.example.com --token pb_xxxxx
//
// URL and token can also come from PRINT_BRIDGE_URL and PRINT_BRIDGE_TOKEN.
import net from 'node:net';
import { kitchenEscPos, receiptEscPos } from '../resources/js/lib/escpos.ts';

const arg = (name) => {
    const i = process.argv.indexOf(`--${name}`);

    return i > -1 ? process.argv[i + 1] : undefined;
};
const base = (arg('url') ?? process.env.PRINT_BRIDGE_URL ?? '').replace(
    /\/+$/,
    '',
);
const token = arg('token') ?? process.env.PRINT_BRIDGE_TOKEN ?? '';
const every = Number(arg('every') ?? 3) * 1000;

if (!base || !token) {
    console.error(
        'Pakai: node print-bridge.mjs --url https://alamat-pos --token pb_xxxxx',
    );
    process.exit(1);
}

const log = (...a) => console.log(new Date().toLocaleTimeString('id-ID'), ...a);

async function call(method, path, body) {
    const res = await fetch(`${base}/bridge/print/${path}`, {
        method,
        headers: {
            Authorization: `Bearer ${token}`,
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: AbortSignal.timeout(15000),
    });

    if (res.status === 401) {
        throw new Error(
            'Token ditolak server. Buat token baru di Pengaturan > Printer.',
        );
    }

    if (!res.ok) {
        throw new Error(`Server membalas ${res.status}`);
    }

    return res.json();
}

/** ESC/POS text is single-byte; anything outside it would print as garbage, so show a question mark instead. */
const bytes = (parts) =>
    Buffer.from(parts.join('').replace(/[^\x00-\xff]/g, '?'), 'latin1');

function send(host, port, data) {
    return new Promise((resolve, reject) => {
        const socket = net.connect({ host, port });
        socket.setTimeout(8000, () =>
            socket.destroy(new Error('Printer tidak merespons (timeout).')),
        );
        socket.once('error', (e) =>
            reject(
                new Error(
                    `Tidak bisa terhubung ke ${host}:${port} (${e.code ?? e.message})`,
                ),
            ),
        );
        socket.once('connect', () => socket.end(data, () => resolve()));
        socket.once('close', () => resolve());
    });
}

async function print(job) {
    const { printer, payload } = job;
    const render = job.kind === 'ticket' ? kitchenEscPos : receiptEscPos;
    const copies = Math.max(1, Number(payload.copies ?? 1));
    const data = bytes(
        Array.from({ length: copies }, () =>
            render(payload, printer.paper_width),
        ).flat(),
    );
    let last;

    for (let attempt = 1; attempt <= 3; attempt++) {
        try {
            await send(printer.host, printer.port, data);

            return;
        } catch (e) {
            last = e;
            await new Promise((r) => setTimeout(r, 1500));
        }
    }

    throw last;
}

let busy = false;

async function tick() {
    if (busy) {
        return;
    }

    busy = true;

    try {
        const { jobs } = await call('GET', 'jobs');

        for (const job of jobs) {
            try {
                await print(job);
                await call('POST', `jobs/${job.id}/done`, { ok: true });
                log(
                    `OK   ${job.printer.name}: ${job.kind} ${job.reference ?? ''}`,
                );
            } catch (e) {
                await call('POST', `jobs/${job.id}/done`, {
                    ok: false,
                    error: String(e.message).slice(0, 190),
                });
                log(`GAGAL ${job.printer.name}: ${e.message}`);
            }
        }
    } catch (e) {
        log('Server tidak terjangkau:', e.message);

        if (String(e.message).startsWith('Token ditolak')) {
            process.exit(2);
        }
    } finally {
        busy = false;
    }
}

log(`Jembatan cetak aktif. Server: ${base}, cek tiap ${every / 1000} detik.`);
setInterval(tick, every);
void tick();
