import { Head, Link } from '@inertiajs/react';
import { Printer, Volume2, VolumeX, WifiOff } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useRealtime } from '@/hooks/use-realtime';
import { useSound } from '@/hooks/use-sound';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { ageMinutes, timeId } from '@/lib/format';
import { printKitchenTicket } from '@/lib/print';
import { cn } from '@/lib/utils';

type Item = {
    id: number;
    name: string;
    qty: number;
    note: string | null;
    modifiers: string[];
    status: 'new' | 'preparing' | 'ready' | 'voided';
};
type Ticket = {
    round_id: number;
    order_number: string;
    label: string;
    source: 'pos' | 'qr';
    since: string;
    done: boolean;
    items: Item[];
};

type Props = {
    station: { id: number; name: string };
    stations: { id: number; name: string }[];
    tickets: Ticket[];
    outletId: number;
    thresholds: { warn: number; danger: number };
};

const NEXT: Record<string, string> = {
    new: 'Mulai dibuat',
    preparing: 'Tandai siap',
    ready: 'Siap ✔',
};
const AUTOPRINT = 'pos.kds.autoprint';

export default function KdsPage({
    station,
    stations,
    tickets: initial,
    outletId,
    thresholds,
}: Props) {
    const [tickets, setTickets] = useState(initial);
    const [now, setNow] = useState(Date.now());
    const [auto, setAuto] = useState(() => {
        try {
            return localStorage.getItem(AUTOPRINT) === '1';
        } catch {
            return false;
        }
    });
    const sound = useSound();
    const seen = useRef(new Set(initial.map((x) => x.round_id)));

    // Kitchen screens are dark by default so they stay readable (PRD 6.1).
    useEffect(() => {
        const root = document.documentElement;
        const had = root.classList.contains('dark');
        root.classList.add('dark');

        return () => {
            if (!had) {
                root.classList.remove('dark');
            }
        };
    }, []);

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 15000);

        return () => window.clearInterval(id);
    }, []);

    const refresh = useCallback(async () => {
        try {
            const res = await api<{ tickets: Ticket[] }>(
                'GET',
                `/kds/${station.id}/tickets`,
            );
            const fresh = res.tickets.filter(
                (x) => !seen.current.has(x.round_id),
            );

            if (fresh.length > 0) {
                sound.beep('order');

                for (const f of fresh) {
                    seen.current.add(f.round_id);

                    if (auto) {
                        void printKitchenTicket({
                            label: f.label,
                            number: f.order_number,
                            time: timeId(f.since),
                            items: f.items.filter((i) => i.status !== 'voided'),
                        });
                    }
                }
            }

            setTickets(res.tickets);
        } catch {
            // keep last good data
        }
    }, [station.id, sound, auto]);

    const { online } = useRealtime(
        [
            {
                channel: `outlet.${outletId}.kitchen.${station.id}`,
                events: ['OrderRoundConfirmed', 'OrderItemStatusChanged'],
            },
        ],
        () => void refresh(),
        refresh,
    );

    const advance = async (item: Item) => {
        if (item.status === 'ready' || item.status === 'voided') {
            return;
        }

        // optimistic: show the tap immediately, server confirms
        setTickets((ts) =>
            ts.map((tk) => ({
                ...tk,
                items: tk.items.map((i) =>
                    i.id === item.id
                        ? {
                              ...i,
                              status:
                                  item.status === 'new' ? 'preparing' : 'ready',
                          }
                        : i,
                ),
            })),
        );

        try {
            await api('POST', `/kds/items/${item.id}/advance`);
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        }

        void refresh();
    };

    return (
        <>
            <Head title={`KDS · ${station.name}`} />
            <div
                className="flex min-h-dvh flex-col bg-background text-foreground"
                onPointerDown={sound.unlock}
            >
                <header className="flex flex-wrap items-center gap-2 border-b px-3 py-2">
                    <Link href="/" className="font-semibold">
                        KDS
                    </Link>
                    <nav className="flex gap-1" aria-label={t('Stasiun')}>
                        {stations.map((s) => (
                            <Button
                                key={s.id}
                                asChild
                                size="sm"
                                variant={
                                    s.id === station.id ? 'default' : 'outline'
                                }
                                className="h-11"
                            >
                                <Link href={`/kds/${s.id}`}>{s.name}</Link>
                            </Button>
                        ))}
                    </nav>
                    {!online && (
                        <span
                            role="status"
                            className="flex items-center gap-1 rounded bg-red-900 px-2 py-0.5 text-xs text-red-50"
                        >
                            <WifiOff className="size-3" /> Offline
                        </span>
                    )}
                    <div className="ml-auto flex items-center gap-2">
                        <label className="flex items-center gap-1 text-xs">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={auto}
                                onChange={(e) => {
                                    setAuto(e.target.checked);
                                    try {
                                        localStorage.setItem(
                                            AUTOPRINT,
                                            e.target.checked ? '1' : '0',
                                        );
                                    } catch {
                                        /* ignore */
                                    }
                                }}
                            />
                            <Printer className="size-4" /> {t('Cetak otomatis')}
                        </label>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-11"
                            aria-label={
                                sound.prefs.muted
                                    ? t('Nyalakan bunyi')
                                    : t('Matikan bunyi')
                            }
                            onClick={() => {
                                sound.unlock();
                                sound.setPrefs((p) => ({
                                    ...p,
                                    muted: !p.muted,
                                }));
                            }}
                        >
                            {sound.prefs.muted ? <VolumeX /> : <Volume2 />}
                        </Button>
                    </div>
                </header>

                {!sound.unlocked && !sound.prefs.muted && (
                    <div
                        role="status"
                        className="fixed inset-x-3 bottom-3 z-50 flex items-center justify-between gap-2 rounded-xl bg-sky-900 px-3 py-2 text-sm text-sky-50 shadow-lg"
                    >
                        <span>
                            {t(
                                'Bunyi notifikasi belum aktif. Ketuk di mana saja atau tombol ini.',
                            )}
                        </span>
                        <Button
                            type="button"
                            size="sm"
                            className="h-10"
                            onClick={() => {
                                sound.unlock();
                                sound.beep('order');
                            }}
                        >
                            {t('Aktifkan bunyi')}
                        </Button>
                    </div>
                )}

                <main className="grid flex-1 content-start gap-3 p-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    {tickets.length === 0 && (
                        <p className="col-span-full py-16 text-center text-lg text-muted-foreground">
                            {t('Tidak ada pesanan.')}
                        </p>
                    )}
                    {tickets.map((tk) => {
                        const age = ageMinutes(tk.since, now);
                        const tone = tk.done
                            ? 'border-emerald-600/60 opacity-70'
                            : age >= thresholds.danger
                              ? 'border-red-500 bg-red-950/60'
                              : age >= thresholds.warn
                                ? 'border-amber-400 bg-amber-950/50'
                                : 'border-border';

                        return (
                            <article
                                key={tk.round_id}
                                className={cn(
                                    'rounded-xl border-2 bg-card p-3',
                                    tone,
                                )}
                                aria-label={`${tk.label} ${tk.order_number}`}
                            >
                                <header className="mb-2 flex items-start justify-between gap-2">
                                    <div>
                                        <h2 className="text-xl font-bold">
                                            {tk.label}
                                        </h2>
                                        <p className="text-xs text-muted-foreground">
                                            {tk.order_number} ·{' '}
                                            {timeId(tk.since)}{' '}
                                            {tk.source === 'qr' && (
                                                <span className="rounded bg-violet-600 px-1 text-white">
                                                    QR
                                                </span>
                                            )}
                                        </p>
                                    </div>
                                    <span
                                        className={cn(
                                            'rounded-lg px-2 py-1 text-lg font-bold tabular-nums',
                                            age >= thresholds.danger
                                                ? 'bg-red-600 text-white'
                                                : age >= thresholds.warn
                                                  ? 'bg-amber-400 text-black'
                                                  : 'bg-muted',
                                        )}
                                        aria-label={`${age} ${t('menit')}`}
                                    >
                                        {age}′
                                    </span>
                                </header>
                                <ul className="grid gap-2">
                                    {tk.items.map((it) => (
                                        <li key={it.id}>
                                            <button
                                                type="button"
                                                disabled={
                                                    it.status === 'ready' ||
                                                    it.status === 'voided'
                                                }
                                                onClick={() => advance(it)}
                                                aria-label={`${it.qty} ${it.name}: ${t(NEXT[it.status] ?? '')}`}
                                                className={cn(
                                                    'min-h-14 w-full rounded-lg border p-2 text-left',
                                                    it.status === 'preparing' &&
                                                        'border-amber-400 bg-amber-950/40',
                                                    it.status === 'ready' &&
                                                        'border-emerald-500 bg-emerald-950/40',
                                                    it.status === 'voided' &&
                                                        'border-red-500 bg-red-950/40',
                                                )}
                                            >
                                                <span
                                                    className={cn(
                                                        'text-lg font-semibold',
                                                        it.status ===
                                                            'voided' &&
                                                            'line-through',
                                                    )}
                                                >
                                                    {it.qty}× {it.name}
                                                </span>
                                                {it.status === 'voided' && (
                                                    <span className="ml-2 rounded bg-red-600 px-1.5 text-xs font-bold text-white">
                                                        VOID
                                                    </span>
                                                )}
                                                {it.modifiers.length > 0 && (
                                                    <span className="block text-sm text-sky-300">
                                                        +{' '}
                                                        {it.modifiers.join(
                                                            ', ',
                                                        )}
                                                    </span>
                                                )}
                                                {it.note && (
                                                    <span className="block text-sm text-amber-300 italic">
                                                        “{it.note}”
                                                    </span>
                                                )}
                                                {it.status !== 'voided' && (
                                                    <span className="mt-1 block text-xs opacity-80">
                                                        {it.status === 'new'
                                                            ? t('Baru')
                                                            : it.status ===
                                                                'preparing'
                                                              ? t('Dibuat')
                                                              : t('Siap')}{' '}
                                                        · {t(NEXT[it.status])}
                                                    </span>
                                                )}
                                            </button>
                                        </li>
                                    ))}
                                </ul>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="mt-2 h-10"
                                    onClick={async () => {
                                        const ok = await printKitchenTicket({
                                            label: tk.label,
                                            number: tk.order_number,
                                            time: timeId(tk.since),
                                            items: tk.items.filter(
                                                (i) => i.status !== 'voided',
                                            ),
                                        });
                                        toast[ok ? 'success' : 'error'](
                                            ok
                                                ? t('Tiket dicetak.')
                                                : t('Printer belum diatur.'),
                                        );
                                    }}
                                >
                                    <Printer /> {t('Cetak tiket')}
                                </Button>
                            </article>
                        );
                    })}
                </main>
            </div>
        </>
    );
}
