import { BellRing, Clock, Users } from 'lucide-react';
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

export function since(iso: string | null | undefined): string | null {
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

    if (m < 1440) {
        return `${Math.floor(m / 60)} j ${m % 60} mnt`;
    }

    return `${Math.floor(m / 1440)} hari`;
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
    const [areaId, setAreaId] = useState<number | 'all'>('all');
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

            {floor.length > 1 && (
                <div
                    role="group"
                    aria-label={t('Filter area')}
                    className="-mt-2 flex [scrollbar-width:none] gap-2 overflow-x-auto"
                >
                    {[
                        { id: 'all' as const, name: t('Semua area') },
                        ...floor,
                    ].map((a) => (
                        <button
                            key={a.id}
                            type="button"
                            aria-pressed={areaId === a.id}
                            onClick={() => setAreaId(a.id)}
                            className={cn(
                                'h-9 shrink-0 rounded-lg px-3 text-sm font-medium transition-colors',
                                areaId === a.id
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {a.name}
                        </button>
                    ))}
                </div>
            )}

            {floor
                .filter((a) => areaId === 'all' || a.id === areaId)
                .map((area) => {
                    const tables = area.tables.filter(
                        (x) => only === 'all' || x.status === only,
                    );

                    if (tables.length === 0) {
                        return null;
                    }

                    return (
                        <section key={area.id} aria-label={area.name}>
                            <h2 className="mb-2 flex items-baseline gap-2 text-sm font-semibold text-muted-foreground">
                                {area.name}
                                <span className="text-xs font-normal">
                                    {
                                        area.tables.filter(
                                            (x) => x.status !== 'empty',
                                        ).length
                                    }
                                    /{area.tables.length} {t('terisi')}
                                </span>
                            </h2>
                            <div className="grid grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-2.5">
                                {tables.map((tb) => (
                                    <TableCard
                                        key={tb.id}
                                        tb={tb}
                                        selected={selectedId === tb.id}
                                        onSelect={onSelect}
                                    />
                                ))}
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

/** One table: empty ones stay quiet, busy ones show the money, who, how long, and what needs someone to act. */
function TableCard({
    tb,
    selected,
    onSelect,
}: {
    tb: FloorTable;
    selected: boolean;
    onSelect: (t: FloorTable) => void;
}) {
    const st = STATUS[tb.status];
    const o = tb.order;
    const age = since(o?.opened_at);
    const flags: { text: string; tone: string; pulse?: boolean }[] = [];

    if (tb.calling) {
        flags.push({
            text: t('Memanggil'),
            tone: 'bg-amber-500 text-white',
            pulse: true,
        });
    }

    if (o && o.ready > 0) {
        flags.push({
            text: `${o.ready} ${t('siap diantar')}`,
            tone: 'bg-emerald-600 text-white',
            pulse: true,
        });
    }

    if (o && o.pending_qr > 0) {
        flags.push({
            text: t('Pesanan QR'),
            tone: 'bg-violet-600 text-white',
            pulse: true,
        });
    }

    if (tb.reserved) {
        flags.push({
            text: `${t('Reservasi')} ${tb.reserved.time} · ${tb.reserved.name} · ${tb.reserved.pax}`,
            tone: 'bg-sky-600 text-white',
        });
    }

    if (o?.bill_requested) {
        flags.push({ text: t('Minta bill'), tone: 'bg-rose-600 text-white' });
    }

    return (
        <button
            onClick={() => onSelect(tb)}
            aria-pressed={selected}
            className={cn(
                'relative flex flex-col overflow-hidden rounded-xl border p-3 pl-4 text-left transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                o ? 'min-h-32' : 'min-h-20',
                st.card,
                !o && 'border-dashed',
                tb.calling && 'border-amber-500 ring-2 ring-amber-400/70',
                selected && 'ring-2 ring-primary',
            )}
        >
            <span
                aria-hidden
                className={cn('absolute inset-y-0 left-0 w-1.5', st.stripe)}
            />
            <span className="flex items-start justify-between gap-1">
                <span className="text-xl font-semibold tracking-tight">
                    {tb.name}
                </span>
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    <Users className="size-3.5" aria-hidden />
                    {tb.capacity}
                </span>
            </span>
            {o?.joined_to ? (
                <span className="mt-1 text-sm font-medium">
                    {t('Gabung ke')} {o.joined_to}
                    <span className="block text-xs font-normal text-muted-foreground">
                        {t('satu bill')} · {rupiah(o.grand_total)}
                    </span>
                </span>
            ) : o ? (
                <>
                    <span className="mt-1 text-lg font-semibold tabular-nums">
                        {rupiah(o.grand_total)}
                    </span>
                    <span className="flex flex-wrap items-center gap-x-2 text-xs text-muted-foreground">
                        {o.customer_name && (
                            <span className="max-w-full truncate font-medium text-foreground/80">
                                {o.customer_name}
                            </span>
                        )}
                        {o.items > 0 && (
                            <span>
                                {o.items} {t('item')}
                            </span>
                        )}
                        {age && (
                            <span className="flex items-center gap-1">
                                <Clock className="size-3" aria-hidden />
                                {age}
                            </span>
                        )}
                    </span>
                </>
            ) : (
                <span className="mt-auto text-xs text-muted-foreground">
                    {t(st.label)}
                    {!tb.qr_enabled && ' · QR off'}
                </span>
            )}
            {flags.length > 0 && (
                <span className="mt-2 flex flex-wrap gap-1">
                    {flags.map((f) => (
                        <span
                            key={f.text}
                            className={cn(
                                'flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                f.tone,
                            )}
                        >
                            {f.pulse && (
                                <BellRing
                                    className="size-3 animate-pulse"
                                    aria-hidden
                                />
                            )}
                            {f.text}
                        </span>
                    ))}
                </span>
            )}
        </button>
    );
}
