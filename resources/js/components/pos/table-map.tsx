import { t } from '@/lang';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Floor, FloorTable } from '@/types/pos';

const STATUS: Record<
    FloorTable['status'],
    { label: string; cls: string; dot: string }
> = {
    empty: {
        label: 'Kosong',
        cls: 'bg-card hover:border-primary/50',
        dot: 'bg-emerald-500',
    },
    occupied: {
        label: 'Terisi',
        cls: 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30',
        dot: 'bg-amber-500',
    },
    awaiting_payment: {
        label: 'Menunggu Bayar',
        cls: 'border-rose-300 bg-rose-50 dark:border-rose-800 dark:bg-rose-950/30',
        dot: 'bg-rose-500',
    },
};

export function TableMap({
    floor,
    selectedId,
    onSelect,
}: {
    floor: Floor;
    selectedId: number | null;
    onSelect: (t: FloorTable) => void;
}) {
    return (
        <div className="grid gap-5 overflow-y-auto pb-24 md:pb-2">
            {floor.map((area) => (
                <section key={area.id} aria-label={area.name}>
                    <h2 className="mb-2 text-sm font-medium text-muted-foreground">
                        {area.name}
                    </h2>
                    <div className="grid grid-cols-[repeat(auto-fill,minmax(9rem,1fr))] gap-2">
                        {area.tables.map((tb) => (
                            <button
                                key={tb.id}
                                onClick={() => onSelect(tb)}
                                aria-pressed={selectedId === tb.id}
                                className={cn(
                                    'min-h-24 rounded-xl border p-3 text-left transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    STATUS[tb.status].cls,
                                    selectedId === tb.id &&
                                        'ring-2 ring-primary',
                                )}
                            >
                                <span className="flex items-center justify-between">
                                    <span className="text-lg font-semibold tracking-tight">
                                        {tb.name}
                                    </span>
                                    {!tb.qr_enabled && (
                                        <span className="text-[10px] text-slate-600 dark:text-slate-300">
                                            QR off
                                        </span>
                                    )}
                                </span>
                                <span className="mt-0.5 flex items-center gap-1.5 text-xs text-muted-foreground">
                                    <span
                                        aria-hidden
                                        className={cn(
                                            'size-1.5 rounded-full',
                                            STATUS[tb.status].dot,
                                        )}
                                    />
                                    {t(STATUS[tb.status].label)} · {tb.capacity}{' '}
                                    {t('kursi')}
                                </span>
                                {tb.order && (
                                    <span className="mt-1 block text-sm font-medium tabular-nums">
                                        {rupiah(tb.order.grand_total)}
                                    </span>
                                )}
                            </button>
                        ))}
                    </div>
                </section>
            ))}
        </div>
    );
}
