import { Head } from '@inertiajs/react';
import { Printer, RefreshCw } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Field, PageHeader, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useRealtime } from '@/hooks/use-realtime';
import { kitchenEscPos, receiptEscPos } from '@/lib/escpos';
import type { PaperWidth, TicketData } from '@/lib/escpos';
import { api } from '@/lib/api';
import {
    bluetoothPrinterName,
    bluetoothSupported,
    pairBluetoothPrinter,
    printRaw,
} from '@/lib/print';
import type { Connection, PrintMethod } from '@/lib/print';
import type { Receipt } from '@/types/pos';

type PrinterRow = {
    id: number;
    name: string;
    kind: string;
    paper_width: number;
    station: string | null;
};
type Job = {
    id: number;
    printer_id: number;
    kind: string;
    reference: string | null;
    payload: Record<string, unknown>;
};
type Recent = {
    id: number;
    printer_id: number;
    kind: string;
    reference: string | null;
    status: string;
    error: string | null;
};
type Cfg = { enabled: boolean; method: PrintMethod; qz: string };

const KEY = 'pos.station';
const KIND: Record<string, string> = {
    receipt: 'Struk',
    bill: 'Bill',
    ticket: 'Tiket pesanan',
};

function loadCfg(): Record<number, Cfg> {
    try {
        return JSON.parse(localStorage.getItem(KEY) ?? '{}');
    } catch {
        return {};
    }
}

function render(job: Job, paper: PaperWidth): string[] {
    return job.kind === 'ticket'
        ? kitchenEscPos(job.payload as unknown as TicketData, paper)
        : receiptEscPos(job.payload as unknown as Receipt, paper);
}

export default function PrintStation({
    outletId,
    printers,
}: {
    outletId: number;
    printers: PrinterRow[];
}) {
    const [cfg, setCfg] = useState<Record<number, Cfg>>(loadCfg);
    const [pending, setPending] = useState(0);
    const [recent, setRecent] = useState<Recent[]>([]);
    const busy = useRef(false);
    const cfgRef = useRef(cfg);
    const [, rerender] = useState(0);

    const get = (id: number): Cfg =>
        cfg[id] ?? { enabled: false, method: 'bluetooth', qz: '' };
    const patch = (id: number, p: Partial<Cfg>) =>
        setCfg((c) => {
            const next = { ...c, [id]: { ...get(id), ...p } };

            try {
                localStorage.setItem(KEY, JSON.stringify(next));
            } catch {
                // ignore
            }

            return next;
        });

    useEffect(() => {
        cfgRef.current = cfg;
    }, [cfg]);

    const serve = useCallback(async () => {
        if (busy.current) {
            return;
        }

        const ids = printers
            .filter((p) => cfgRef.current[p.id]?.enabled)
            .map((p) => p.id);

        if (ids.length === 0) {
            return;
        }

        busy.current = true;

        try {
            const res = await api<{ pending: Job[]; recent: Recent[] }>(
                'GET',
                `/print-station/jobs?printers=${ids.join(',')}`,
            );
            setRecent(res.recent);
            setPending(res.pending.length);

            for (const job of res.pending) {
                const printer = printers.find((p) => p.id === job.printer_id);
                const c = cfgRef.current[job.printer_id];

                if (!printer || !c) {
                    continue;
                }

                const conn: Connection = {
                    method: c.method,
                    qz: c.qz,
                    key: String(printer.id),
                };
                const copies = Math.max(
                    1,
                    Number((job.payload as { copies?: number }).copies ?? 1),
                );

                try {
                    for (let i = 0; i < copies; i++) {
                        await printRaw(
                            render(job, printer.paper_width as PaperWidth),
                            conn,
                        );
                    }

                    await api('POST', `/print-station/jobs/${job.id}/done`, {
                        ok: true,
                    });
                } catch (e) {
                    await api('POST', `/print-station/jobs/${job.id}/done`, {
                        ok: false,
                        error:
                            e instanceof Error
                                ? e.message.slice(0, 190)
                                : 'Gagal mencetak',
                    });
                }
            }
        } catch {
            // network hiccup; next poll retries
        } finally {
            busy.current = false;
        }
    }, [printers]);

    useRealtime(
        [
            {
                channel: `outlet.${outletId}.print`,
                events: ['PrintJobCreated'],
            },
        ],
        () => void serve(),
        () => void serve(),
    );

    useEffect(() => {
        void serve();
        const id = window.setInterval(() => void serve(), 4000);

        return () => window.clearInterval(id);
    }, [serve]);

    // Keep the screen on while this page is the print station.
    useEffect(() => {
        let lock: { release: () => Promise<void> } | null = null;
        const w = navigator as unknown as {
            wakeLock?: { request: (t: 'screen') => Promise<typeof lock> };
        };
        w.wakeLock
            ?.request('screen')
            .then((l) => (lock = l))
            .catch(() => undefined);

        return () => void lock?.release();
    }, []);

    const test = async (p: PrinterRow) => {
        const c = get(p.id);

        try {
            await printRaw(
                [
                    '\x1b@',
                    '\x1ba\x01',
                    `TES ${p.name}\n`,
                    `${KIND[p.kind] ?? p.kind} - ${p.paper_width} mm\n`,
                    '\n\n\n',
                ],
                { method: c.method, qz: c.qz, key: String(p.id) },
            );
            toast.success('Tes terkirim.');
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Gagal mencetak.');
        }
    };

    const active = printers.filter((p) => get(p.id).enabled).length;

    return (
        <>
            <Head title="Stasiun Cetak" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Stasiun Cetak"
                    subtitle="Biarkan halaman ini terbuka di perangkat yang tersambung ke printer. Pesanan, bill, dan struk yang masuk antrean dicetak otomatis."
                />
                <p
                    className="rounded-lg bg-muted p-3 text-sm"
                    role="status"
                    aria-live="polite"
                >
                    {active === 0
                        ? 'Centang printer yang tersambung ke perangkat ini.'
                        : `Melayani ${active} printer · antrean ${pending}`}
                </p>

                {printers.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        Belum ada printer. Tambahkan di Admin &gt; Printer.
                    </p>
                )}

                <div className="grid gap-3 md:grid-cols-2">
                    {printers.map((p) => {
                        const c = get(p.id);

                        return (
                            <section
                                key={p.id}
                                className="grid gap-2 rounded-xl border bg-card p-3"
                            >
                                <div className="flex items-center gap-2">
                                    <Printer className="size-5 text-primary" />
                                    <div className="flex-1">
                                        <p className="font-semibold">
                                            {p.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {KIND[p.kind] ?? p.kind}
                                            {p.station
                                                ? ` · ${p.station}`
                                                : ''}{' '}
                                            · {p.paper_width} mm
                                        </p>
                                    </div>
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-5"
                                        checked={c.enabled}
                                        onChange={(e) =>
                                            patch(p.id, {
                                                enabled: e.target.checked,
                                            })
                                        }
                                    />
                                    Printer ini tersambung ke perangkat ini
                                </label>
                                {c.enabled && (
                                    <>
                                        <Field label="Cara cetak">
                                            <Select
                                                value={c.method}
                                                onChange={(e) =>
                                                    patch(p.id, {
                                                        method: e.target
                                                            .value as PrintMethod,
                                                    })
                                                }
                                            >
                                                <option value="bluetooth">
                                                    Bluetooth langsung (BLE)
                                                </option>
                                                <option value="rawbt">
                                                    RawBT (Android)
                                                </option>
                                                <option value="qz">
                                                    QZ Tray (komputer)
                                                </option>
                                            </Select>
                                        </Field>
                                        {c.method === 'bluetooth' &&
                                            (bluetoothSupported() ? (
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    className="h-11"
                                                    onClick={async () => {
                                                        try {
                                                            await pairBluetoothPrinter(
                                                                String(p.id),
                                                            );
                                                            rerender(
                                                                (n) => n + 1,
                                                            );
                                                        } catch (e) {
                                                            toast.error(
                                                                e instanceof
                                                                    Error
                                                                    ? e.message
                                                                    : 'Gagal menghubungkan.',
                                                            );
                                                        }
                                                    }}
                                                >
                                                    {bluetoothPrinterName(
                                                        String(p.id),
                                                    )
                                                        ? `Terhubung: ${bluetoothPrinterName(String(p.id))} · ganti`
                                                        : 'Hubungkan printer Bluetooth'}
                                                </Button>
                                            ) : (
                                                <p className="text-sm text-destructive">
                                                    Browser ini tidak mendukung
                                                    Bluetooth langsung.
                                                </p>
                                            ))}
                                        {c.method === 'qz' && (
                                            <Field label="Nama printer di komputer">
                                                <Input
                                                    className="h-11"
                                                    value={c.qz}
                                                    onChange={(e) =>
                                                        patch(p.id, {
                                                            qz: e.target.value,
                                                        })
                                                    }
                                                />
                                            </Field>
                                        )}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-11"
                                            onClick={() => void test(p)}
                                        >
                                            Cetak tes
                                        </Button>
                                    </>
                                )}
                            </section>
                        );
                    })}
                </div>

                {recent.length > 0 && (
                    <section>
                        <h2 className="mb-2 flex items-center gap-2 font-semibold">
                            Riwayat terakhir
                            <RefreshCw className="size-4 text-muted-foreground" />
                        </h2>
                        <ul className="grid gap-1 text-sm">
                            {recent.map((j) => (
                                <li
                                    key={j.id}
                                    className="flex items-center justify-between gap-2 rounded-lg border bg-card p-2"
                                >
                                    <span>
                                        {KIND[j.kind] ?? j.kind} · {j.reference}{' '}
                                        ·{' '}
                                        {printers.find(
                                            (p) => p.id === j.printer_id,
                                        )?.name ?? ''}
                                    </span>
                                    {j.status === 'printed' ? (
                                        <span className="text-emerald-700">
                                            Tercetak
                                        </span>
                                    ) : (
                                        <span className="flex items-center gap-2 text-destructive">
                                            {j.error ?? 'Gagal'}
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={async () => {
                                                    await api(
                                                        'POST',
                                                        `/print-station/jobs/${j.id}/retry`,
                                                    );
                                                    void serve();
                                                }}
                                            >
                                                Coba lagi
                                            </Button>
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}
