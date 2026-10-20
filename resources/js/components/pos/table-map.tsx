import { t } from '@/lang';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Floor, FloorTable } from '@/types/pos';

const STATUS: Record<FloorTable['status'], { label: string; cls: string }> = {
    empty: {
        label: 'Kosong',
        cls: 'border-emerald-600/40 bg-emerald-50 dark:bg-emerald-950/30',
    },
    occupied: {
        label: 'Terisi',
        cls: 'border-amber-600/50 bg-amber-50 dark:bg-amber-950/30',
    },
    awaiting_payment: {
        label: 'Menunggu Bayar',
        cls: 'border-rose-600/50 bg-rose-50 dark:bg-rose-950/30',
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
                    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                        {area.tables.map((tb) => (
                            <button
                                key={tb.id}
                                onClick={() => onSelect(tb)}
                                aria-pressed={selectedId === tb.id}
                                className={cn(
                                    'min-h-24 rounded-xl border-2 p-3 text-left outline-none focus-visible:ring-2 focus-visible:ring-ring',
                                    STATUS[tb.status].cls,
                                    selectedId === tb.id &&
                                        'ring-2 ring-primary',
                                )}
                            >
                                <span className="flex items-center justify-between">
                                    <span className="text-lg font-semibold">
                                        {tb.name}
                                    </span>
                                    {!tb.qr_enabled && (
                                        <span className="text-[10px] text-slate-600 dark:text-slate-300">
                                            QR off
                                        </span>
                                    )}
                                </span>
                                <span className="block text-xs text-slate-700 dark:text-slate-200">
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
