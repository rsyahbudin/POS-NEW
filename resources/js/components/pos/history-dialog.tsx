import { useEffect, useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import { api } from '@/lib/api';
import { rupiah, timeId } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    number: string;
    where: string;
    at: string | null;
    status: string;
    status_label: string;
    total: number;
    refunded: number;
    by: string | null;
    paid_by: string[];
    bills: {
        id: number;
        label: string | null;
        status: string;
        total: number;
    }[];
};
type History = {
    shift: { opened_by: string | null; opened_at: string } | null;
    summary: { paid: number; total: number; open: number; cancelled: number };
    orders: Row[];
};

const FILTERS = [
    ['all', 'Semua'],
    ['paid', 'Lunas'],
    ['open', 'Terbuka'],
    ['cancelled', 'Batal'],
] as const;

const TONE: Record<string, string> = {
    paid: 'bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-200 text-emerald-900',
    refunded:
        'bg-amber-100 dark:bg-amber-950/50 dark:text-amber-200 text-amber-900',
    cancelled: 'bg-muted text-muted-foreground',
    open: 'bg-sky-100 dark:bg-sky-950/50 dark:text-sky-200 text-sky-900',
    partially_paid:
        'bg-sky-100 dark:bg-sky-950/50 dark:text-sky-200 text-sky-900',
};

/** Today's bills for the cashier: what was sold, what is still open, and a way to print a receipt again. */
export function HistoryDialog({
    onClose,
    onReceipt,
    onOpen,
}: {
    onClose: () => void;
    onReceipt: (billId: number) => void;
    onOpen: (orderId: number) => void;
}) {
    const [data, setData] = useState<History | null>(null);
    const [failed, setFailed] = useState(false);
    const [filter, setFilter] = useState<(typeof FILTERS)[number][0]>('all');

    useEffect(() => {
        api<History>('GET', '/pos/history')
            .then(setData)
            .catch(() => setFailed(true));
    }, []);

    const rows = (data?.orders ?? []).filter((o) =>
        filter === 'all'
            ? true
            : filter === 'paid'
              ? o.status === 'paid' || o.status === 'refunded'
              : filter === 'open'
                ? o.status === 'open' || o.status === 'partially_paid'
                : o.status === 'cancelled',
    );

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Transaksi shift ini')}
            description={
                data
                    ? data.shift
                        ? `${t('Shift dibuka oleh')} ${data.shift.opened_by ?? '-'} · ${t('semua staf bisa melihat.')}`
                        : t(
                              'Belum ada shift terbuka; menampilkan transaksi hari ini.',
                          )
                    : undefined
            }
            className="sm:max-w-xl"
        >
            {failed && (
                <p role="alert" className="text-sm text-destructive">
                    {t('Gagal memuat riwayat.')}
                </p>
            )}
            {!data && !failed && (
                <p className="text-sm text-muted-foreground">{t('Memuat…')}</p>
            )}
            {data && (
                <>
                    <div className="grid grid-cols-2 gap-2">
                        <div className="rounded-xl border bg-card p-3">
                            <p className="text-[13px] text-muted-foreground">
                                {t('Penjualan lunas')}
                            </p>
                            <p className="text-xl font-semibold tabular-nums">
                                {rupiah(data.summary.total)}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {data.summary.paid} {t('transaksi')}
                            </p>
                        </div>
                        <div className="rounded-xl border bg-card p-3">
                            <p className="text-[13px] text-muted-foreground">
                                {t('Belum selesai')}
                            </p>
                            <p className="text-xl font-semibold tabular-nums">
                                {data.summary.open}
                            </p>
                            <p className="text-xs text-muted-foreground">
                                {data.summary.cancelled} {t('dibatalkan')}
                            </p>
                        </div>
                    </div>
                    <div
                        className="flex [scrollbar-width:none] gap-1.5 overflow-x-auto"
                        role="group"
                        aria-label={t('Filter status')}
                    >
                        {FILTERS.map(([id, label]) => (
                            <button
                                key={id}
                                type="button"
                                aria-pressed={filter === id}
                                onClick={() => setFilter(id)}
                                className={cn(
                                    'h-9 shrink-0 rounded-full border px-3.5 text-sm font-medium',
                                    filter === id
                                        ? 'border-primary bg-primary text-primary-foreground'
                                        : 'bg-card',
                                )}
                            >
                                {t(label)}
                            </button>
                        ))}
                    </div>
                    {rows.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-5 text-center text-sm text-muted-foreground">
                            {t('Belum ada transaksi.')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {rows.map((o) => (
                                <li
                                    key={o.id}
                                    className="grid gap-1.5 p-3 text-sm"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="font-semibold">
                                                {o.where}{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    · {o.number}
                                                </span>
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                {o.at ? timeId(o.at) : '-'}
                                                {o.by
                                                    ? ` · ${t('dibuka')} ${o.by}`
                                                    : ''}
                                                {o.paid_by.length > 0
                                                    ? ` · ${t('dibayar')} ${o.paid_by.join(', ')}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="font-semibold tabular-nums">
                                                {rupiah(o.total)}
                                            </p>
                                            <span
                                                className={cn(
                                                    'rounded-md px-1.5 py-0.5 text-[11px] font-medium',
                                                    TONE[o.status] ??
                                                        'bg-muted',
                                                )}
                                            >
                                                {o.status_label}
                                            </span>
                                        </div>
                                    </div>
                                    <div className="flex flex-wrap gap-1.5">
                                        {(o.status === 'open' ||
                                            o.status === 'partially_paid') && (
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                className="h-9"
                                                onClick={() => {
                                                    onClose();
                                                    onOpen(o.id);
                                                }}
                                            >
                                                {t('Buka pesanan')}
                                            </Button>
                                        )}
                                        {o.bills
                                            .filter((b) => b.status === 'paid')
                                            .map((b) => (
                                                <Button
                                                    key={b.id}
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-9"
                                                    onClick={() =>
                                                        onReceipt(b.id)
                                                    }
                                                >
                                                    {o.bills.length > 1
                                                        ? `${t('Struk')} ${b.label ?? ''}`
                                                        : t(
                                                              'Cetak ulang struk',
                                                          )}
                                                </Button>
                                            ))}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}
        </Modal>
    );
}
