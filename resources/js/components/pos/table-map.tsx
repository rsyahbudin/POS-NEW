import { Clock, Users } from 'lucide-react';
import { useState } from 'react';
import { t } from '@/lang';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Floor, FloorTable } from '@/types/pos';

type Status = FloorTable['status'];

const STATUS: Record<
    Status,
    { label: string; stripe: string; card: string; dot: string }
> = {
    empty: {
        label: 'Kosong',
        stripe: 'bg-emerald-500',
        card: 'bg-card hover:border-primary/50',
        dot: 'bg-emerald-500',
    },
    occupied: {
        label: 'Terisi',
        stripe: 'bg-amber-500',
        card: 'bg-amber-50/70 border-amber-200 dark:bg-amber-950/20 dark:border-amber-900',
        dot: 'bg-amber-500',
    },
    awaiting_payment: {
        label: 'Menunggu Bayar',
        stripe: 'bg-rose-500',
        card: 'bg-rose-50/70 border-rose-200 dark:bg-rose-950/20 dark:border-rose-900',
        dot: 'bg-rose-500',
    },
};

function since(iso: string | null | undefined): string | null {
    if (!iso) {
        return null;
    }

    const m = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (m < 1) {
        return 'baru';
    }

    if (m < 60) {
        return `${m} mnt`;
    }

    return `${Math.floor(m / 60)} j ${m % 60} mnt`;
}

export function TableMap({
    floor,
    selectedId,
    onSelect,
}: {
    floor: Floor;
    selectedId: number | null;
    onSelect: (t: FloorTable) => void;
}) {
    const [only, setOnly] = useState<Status | 'all'>('all');
    const all = floor.flatMap((a) => a.tables);
    const count = (s: Status) => all.filter((x) => x.status === s).length;
    const pills: {
        id: Status | 'all';
        label: string;
        n: number;
        dot?: string;
    }[] = [
        { id: 'all', label: t('Semua'), n: all.length },
        {
            id: 'empty',
            label: t('Kosong'),
            n: count('empty'),
            dot: STATUS.empty.dot,
        },
        {
            id: 'occupied',
            label: t('Terisi'),
            n: count('occupied'),
            dot: STATUS.occupied.dot,
        },
        {
            id: 'awaiting_payment',
            label: t('Menunggu Bayar'),
            n: count('awaiting_payment'),
            dot: STATUS.awaiting_payment.dot,
        },
    ];

    return (
        <div className="grid gap-5 overflow-y-auto pb-24 md:pb-2">
            <div
                role="group"
                aria-label={t('Filter status meja')}
                className="flex [scrollbar-width:none] gap-2 overflow-x-auto"
            >
                {pills.map((p) => (
                    <button
                        key={p.id}
                        type="button"
                        aria-pressed={only === p.id}
                        onClick={() => setOnly(p.id)}
                        className={cn(
                            'flex h-9 shrink-0 items-center gap-1.5 rounded-full border px-3 text-sm font-medium transition-colors',
                            only === p.id
                                ? 'border-foreground/80 bg-foreground text-background'
                                : 'bg-card hover:bg-muted',
                        )}
                    >
                        {p.dot && (
                            <span
                                aria-hidden
                                className={cn('size-2 rounded-full', p.dot)}
                            />
                        )}
                        {p.label}
                        <span
                            className={cn(
                                'tabular-nums',
                                only === p.id
                                    ? 'opacity-80'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {p.n}
                        </span>
                    </button>
                ))}
            </div>

            {floor.map((area) => {
                const tables = area.tables.filter(
                    (x) => only === 'all' || x.status === only,
                );

                if (tables.length === 0) {
                    return null;
                }

                return (
                    <section key={area.id} aria-label={area.name}>
                        <h2 className="mb-2 text-sm font-semibold text-muted-foreground">
                            {area.name}
                        </h2>
                        <div className="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2.5">
                            {tables.map((tb) => {
                                const st = STATUS[tb.status];
                                const age = since(tb.order?.opened_at);

                                return (
                                    <button
                                        key={tb.id}
                                        onClick={() => onSelect(tb)}
                                        aria-pressed={selectedId === tb.id}
                                        className={cn(
                                            'relative flex min-h-28 flex-col overflow-hidden rounded-xl border p-3 pl-4 text-left transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                            st.card,
                                            selectedId === tb.id &&
                                                'ring-2 ring-primary',
                                        )}
                                    >
                                        <span
                                            aria-hidden
                                            className={cn(
                                                'absolute inset-y-0 left-0 w-1.5',
                                                st.stripe,
                                            )}
                                        />
                                        <span className="flex items-start justify-between gap-1">
                                            <span className="text-xl font-semibold tracking-tight">
                                                {tb.name}
                                            </span>
                                            {!tb.qr_enabled && (
                                                <span className="rounded bg-muted px-1 text-[10px] text-muted-foreground">
                                                    QR off
                                                </span>
                                            )}
                                        </span>
                                        <span className="mt-0.5 flex items-center gap-1 text-xs text-muted-foreground">
                                            <Users
                                                className="size-3.5"
                                                aria-hidden
                                            />
                                            {tb.capacity} {t('kursi')} ·{' '}
                                            {t(st.label)}
                                        </span>
                                        {tb.order ? (
                                            <span className="mt-auto flex items-end justify-between gap-2 pt-2">
                                                <span className="text-base font-semibold tabular-nums">
                                                    {rupiah(
                                                        tb.order.grand_total,
                                                    )}
                                                </span>
                                                {age && (
                                                    <span className="flex items-center gap-1 text-xs text-muted-foreground">
                                                        <Clock
                                                            className="size-3.5"
                                                            aria-hidden
                                                        />
                                                        {age}
                                                    </span>
                                                )}
                                            </span>
                                        ) : (
                                            <span className="mt-auto pt-2 text-xs text-muted-foreground">
                                                {t('Ketuk untuk mulai pesan')}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    </section>
                );
            })}
            {all.length > 0 &&
                !floor.some((a) =>
                    a.tables.some((x) => only === 'all' || x.status === only),
                ) && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        {t('Tidak ada meja dengan status ini.')}
                    </p>
                )}
        </div>
    );
}
