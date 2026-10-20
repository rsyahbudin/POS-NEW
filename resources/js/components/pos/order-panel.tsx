import { Minus, Plus, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import { rupiah, timeId } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Bill, CartLine, Order, OrderItem, Payment } from '@/types/pos';

const ITEM_STATUS: Record<string, { label: string; cls: string }> = {
    pending: {
        label: 'Menunggu konfirmasi',
        cls: 'bg-slate-200 text-slate-900',
    },
    new: { label: 'Baru', cls: 'bg-sky-200 text-sky-950' },
    preparing: { label: 'Dibuat', cls: 'bg-amber-200 text-amber-950' },
    ready: { label: 'Siap', cls: 'bg-emerald-200 text-emerald-950' },
    voided: { label: 'VOID', cls: 'bg-red-200 text-red-950' },
    rejected: { label: 'Dihapus', cls: 'bg-slate-200 text-slate-900' },
};

export type PanelActions = {
    send: () => void;
    sending: boolean;
    setQty: (key: string, delta: number) => void;
    remove: (key: string) => void;
    setNote: (key: string, note: string) => void;
    voidItem: (i: OrderItem) => void;
    comp: (i: OrderItem) => void;
    discount: (i: OrderItem | null) => void;
    removeDiscount: (id: number) => void;
    pay: (b: Bill) => void;
    closeZero: () => void;
    split: () => void;
    allocate: (b: Bill) => void;
    billRequest: () => void;
    voidOrder: () => void;
    move: () => void;
    reprint: (b: Bill) => void;
    refund: (p: Payment) => void;
    clear: () => void;
};

export function OrderPanel({
    title,
    subtitle,
    order,
    cart,
    can,
    actions,
}: {
    title: string;
    subtitle?: string;
    order: Order | null;
    cart: CartLine[];
    can: { pay: boolean; void: boolean; refund: boolean; discount: boolean };
    actions: PanelActions;
}) {
    const cartTotal = cart.reduce((s, l) => s + l.unitPrice * l.qty, 0);
    const editable = !order || order.is_editable;
    const liveItems =
        order?.rounds
            .flatMap((r) => r.items)
            .filter((i) => i.status !== 'rejected') ?? [];
    const unallocated = order?.bills.find((b) => b.is_unallocated);
    const unpaidBills =
        order?.bills.filter(
            (b) => b.status === 'unpaid' && !b.is_unallocated,
        ) ?? [];

    return (
        <section
            aria-label={t('Pesanan')}
            className="flex h-full min-h-0 flex-col rounded-xl border bg-card"
        >
            <header className="flex items-start justify-between gap-2 border-b p-3">
                <div>
                    <h2 className="text-lg leading-tight font-semibold">
                        {title}
                    </h2>
                    {subtitle && (
                        <p className="text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    )}
                </div>
                {order && (
                    <span className="rounded bg-muted px-2 py-1 text-xs font-medium">
                        {order.status_label}
                    </span>
                )}
            </header>

            <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3">
                {order?.rounds.map((round, idx) => (
                    <div key={round.id}>
                        <p className="mb-1 flex items-center gap-2 text-xs text-muted-foreground">
                            {t('Ronde')} {idx + 1} · {timeId(round.created_at)}
                            {round.source === 'qr' && (
                                <span className="rounded bg-violet-600 px-1.5 text-[10px] font-bold text-white">
                                    QR{' '}
                                    {round.customer_name
                                        ? `· ${round.customer_name}`
                                        : ''}
                                </span>
                            )}
                            {round.status === 'rejected' && (
                                <span className="text-destructive">
                                    {t('Ditolak')}: {round.reject_reason}
                                </span>
                            )}
                        </p>
                        <ul className="grid gap-1">
                            {round.items
                                .filter((i) => i.status !== 'rejected')
                                .map((i) => (
                                    <li
                                        key={i.id}
                                        className={cn(
                                            'rounded-lg border p-2 text-sm',
                                            i.status === 'voided' &&
                                                'opacity-60',
                                        )}
                                    >
                                        <div className="flex items-start justify-between gap-2">
                                            <div className="min-w-0">
                                                <p
                                                    className={cn(
                                                        'font-medium',
                                                        i.status === 'voided' &&
                                                            'line-through',
                                                    )}
                                                >
                                                    {i.qty}× {i.name}
                                                </p>
                                                {i.modifiers.length > 0 && (
                                                    <p className="text-xs text-muted-foreground">
                                                        {i.modifiers.join(', ')}
                                                    </p>
                                                )}
                                                {i.note && (
                                                    <p className="text-xs italic">
                                                        “{i.note}”
                                                    </p>
                                                )}
                                                {i.comp_type && (
                                                    <p className="text-xs font-medium text-fuchsia-700">
                                                        {i.comp_type ===
                                                        'staff_meal'
                                                            ? t('Staff meal')
                                                            : t('Komplimen')}
                                                    </p>
                                                )}
                                                {i.void_reason && (
                                                    <p className="text-xs text-red-700">
                                                        {i.void_reason}
                                                    </p>
                                                )}
                                            </div>
                                            <div className="text-right">
                                                <p className="tabular-nums">
                                                    {i.comp_type ? (
                                                        <s>
                                                            {rupiah(
                                                                i.line_total,
                                                            )}
                                                        </s>
                                                    ) : (
                                                        rupiah(i.line_total)
                                                    )}
                                                </p>
                                                <span
                                                    className={cn(
                                                        'rounded px-1.5 py-0.5 text-[10px] font-semibold',
                                                        ITEM_STATUS[i.status]
                                                            ?.cls,
                                                    )}
                                                >
                                                    {t(
                                                        ITEM_STATUS[i.status]
                                                            ?.label ?? i.status,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                        {editable &&
                                            [
                                                'new',
                                                'preparing',
                                                'ready',
                                            ].includes(i.status) && (
                                                <div className="mt-1 flex flex-wrap gap-1">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-9 text-xs"
                                                        onClick={() =>
                                                            actions.voidItem(i)
                                                        }
                                                    >
                                                        {t('Void')}
                                                    </Button>
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-9 text-xs"
                                                        onClick={() =>
                                                            actions.discount(i)
                                                        }
                                                    >
                                                        {t('Diskon')}
                                                    </Button>
                                                    {!i.comp_type && (
                                                        <Button
                                                            type="button"
                                                            variant="ghost"
                                                            size="sm"
                                                            className="h-9 text-xs"
                                                            onClick={() =>
                                                                actions.comp(i)
                                                            }
                                                        >
                                                            {t('Komplimen')}
                                                        </Button>
                                                    )}
                                                </div>
                                            )}
                                    </li>
                                ))}
                        </ul>
                    </div>
                ))}

                {cart.length > 0 && (
                    <div>
                        <p className="mb-1 text-xs font-semibold text-emerald-700">
                            {t('Item baru (belum dikirim)')}
                        </p>
                        <ul className="grid gap-1">
                            {cart.map((l) => (
                                <li
                                    key={l.key}
                                    className="rounded-lg border border-dashed p-2 text-sm"
                                >
                                    <div className="flex justify-between gap-2">
                                        <p className="min-w-0 font-medium">
                                            {l.label}
                                        </p>
                                        <p className="tabular-nums">
                                            {rupiah(l.unitPrice * l.qty)}
                                        </p>
                                    </div>
                                    <input
                                        type="text"
                                        value={l.note}
                                        maxLength={200}
                                        placeholder={t(
                                            'Catatan (mis. less ice, tanpa gula)',
                                        )}
                                        aria-label={`${t('Catatan')} ${l.label}`}
                                        onChange={(e) =>
                                            actions.setNote(
                                                l.key,
                                                e.target.value,
                                            )
                                        }
                                        className="mt-1 h-10 w-full rounded-md border border-input bg-background px-2 text-xs"
                                    />
                                    <div className="mt-1 flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className="size-11"
                                            aria-label={t('Kurangi')}
                                            onClick={() =>
                                                actions.setQty(l.key, -1)
                                            }
                                        >
                                            <Minus />
                                        </Button>
                                        <span
                                            className="w-8 text-center tabular-nums"
                                            aria-live="polite"
                                        >
                                            {l.qty}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className="size-11"
                                            aria-label={t('Tambah')}
                                            onClick={() =>
                                                actions.setQty(l.key, 1)
                                            }
                                        >
                                            <Plus />
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="ml-auto size-11"
                                            aria-label={t('Hapus')}
                                            onClick={() =>
                                                actions.remove(l.key)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </div>
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                {!order && cart.length === 0 && (
                    <p className="py-8 text-center text-sm text-muted-foreground">
                        {t('Pilih produk untuk mulai memesan.')}
                    </p>
                )}

                {order && order.bills.some((b) => b.mode) && (
                    <div className="rounded-lg border p-2 text-sm">
                        <p className="mb-1 font-medium">
                            {t('Tagihan terpisah')}
                        </p>
                        <ul className="grid gap-1">
                            {order.bills.map((b) => (
                                <li
                                    key={b.id}
                                    className="flex items-center justify-between gap-2"
                                >
                                    <span>
                                        {b.label ?? '-'}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            (
                                            {b.status === 'paid'
                                                ? t('Lunas')
                                                : b.is_unallocated
                                                  ? t('Belum dialokasikan')
                                                  : t('Belum bayar')}
                                            )
                                        </span>
                                    </span>
                                    <span className="flex items-center gap-1 tabular-nums">
                                        {rupiah(b.grand_total)}
                                        {b.status === 'paid' && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-9"
                                                onClick={() =>
                                                    actions.reprint(b)
                                                }
                                            >
                                                {t('Struk')}
                                            </Button>
                                        )}
                                        {b.status === 'unpaid' &&
                                            b.is_unallocated && (
                                                <span className="text-xs text-amber-700">
                                                    {t('Alokasikan dulu')}
                                                </span>
                                            )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {unallocated && (
                            <p className="mt-1 text-xs text-amber-700">
                                {t(
                                    'Ada item baru setelah split. Pecah ulang atau alokasikan sebelum membayar.',
                                )}
                            </p>
                        )}
                    </div>
                )}

                {order && order.payments.length > 0 && (
                    <div className="rounded-lg border p-2 text-sm">
                        <p className="mb-1 font-medium">{t('Pembayaran')}</p>
                        {order.payments.map((p) => (
                            <div
                                key={p.id}
                                className="flex items-center justify-between gap-2"
                            >
                                <span>
                                    {p.method}
                                    {p.reference ? ` · ${p.reference}` : ''}
                                    {p.refunded_total > 0 && (
                                        <em className="ml-1 text-xs text-destructive">
                                            (refund {rupiah(p.refunded_total)})
                                        </em>
                                    )}
                                </span>
                                <span className="flex items-center gap-1 tabular-nums">
                                    {rupiah(p.amount)}
                                    {can.refund &&
                                        p.amount - p.refunded_total > 0 &&
                                        order.status !== 'cancelled' && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                className="h-9"
                                                onClick={() =>
                                                    actions.refund(p)
                                                }
                                            >
                                                Refund
                                            </Button>
                                        )}
                                </span>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            <footer className="grid gap-2 border-t p-3">
                {order && liveItems.length > 0 && (
                    <dl className="grid gap-0.5 text-sm">
                        <Row
                            label={t('Subtotal')}
                            value={rupiah(order.subtotal)}
                        />
                        {order.discounts.map((d) => (
                            <div
                                key={d.id}
                                className="flex items-center justify-between text-emerald-700"
                            >
                                <dt>
                                    {t('Diskon')}: {d.reason}
                                    {editable && (
                                        <button
                                            type="button"
                                            className="ml-1 underline"
                                            onClick={() =>
                                                actions.removeDiscount(d.id)
                                            }
                                        >
                                            {t('hapus')}
                                        </button>
                                    )}
                                </dt>
                                <dd className="tabular-nums">
                                    -{rupiah(d.amount)}
                                </dd>
                            </div>
                        ))}
                        {order.charges
                            .filter((c) => c.amount > 0)
                            .map((c) => (
                                <Row
                                    key={c.name}
                                    label={
                                        c.is_inclusive
                                            ? `${c.name} (${t('termasuk')})`
                                            : c.name
                                    }
                                    value={rupiah(c.amount)}
                                />
                            ))}
                        {order.rounding !== 0 && (
                            <Row
                                label={t('Pembulatan')}
                                value={rupiah(order.rounding)}
                            />
                        )}
                        <div className="mt-1 flex items-center justify-between border-t pt-1 text-lg font-bold">
                            <dt>{t('Total')}</dt>
                            <dd className="tabular-nums">
                                {rupiah(order.grand_total)}
                            </dd>
                        </div>
                        {order.paid_total > 0 && (
                            <Row
                                label={t('Sudah dibayar')}
                                value={rupiah(order.paid_total)}
                            />
                        )}
                    </dl>
                )}

                {cart.length > 0 && (
                    <>
                        <p className="text-xs text-muted-foreground">
                            {t('Estimasi item baru')}: {rupiah(cartTotal)} ·{' '}
                            {t('total final dihitung server')}
                        </p>
                        <Button
                            type="button"
                            className="h-14 text-base"
                            disabled={actions.sending}
                            onClick={actions.send}
                        >
                            {actions.sending
                                ? t('Mengirim…')
                                : order
                                  ? t('Tambah ronde ke dapur')
                                  : t('Kirim ke dapur')}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-11"
                            onClick={actions.clear}
                        >
                            {t('Kosongkan keranjang')}
                        </Button>
                    </>
                )}

                {order &&
                    order.is_editable &&
                    cart.length === 0 &&
                    liveItems.some((i) =>
                        ['new', 'preparing', 'ready'].includes(i.status),
                    ) && (
                        <div className="grid gap-2">
                            {can.pay && order.grand_total > 0 && (
                                <div className="grid gap-2">
                                    {unpaidBills.map((b) => (
                                        <Button
                                            key={b.id}
                                            type="button"
                                            className="h-14 text-base"
                                            disabled={!!unallocated}
                                            onClick={() => actions.pay(b)}
                                        >
                                            {t('Bayar')} {b.label ?? ''} ·{' '}
                                            {rupiah(b.remaining)}
                                        </Button>
                                    ))}
                                </div>
                            )}
                            {can.pay && order.grand_total === 0 && (
                                <Button
                                    type="button"
                                    className="h-12"
                                    onClick={actions.closeZero}
                                >
                                    {t('Tutup order (Rp0)')}
                                </Button>
                            )}
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    onClick={actions.billRequest}
                                >
                                    {t('Pre-bill')}
                                </Button>
                                {can.discount && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                        onClick={() => actions.discount(null)}
                                    >
                                        {t('Diskon')}
                                    </Button>
                                )}
                                {can.pay && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                        onClick={actions.split}
                                    >
                                        {t('Pecah tagihan')}
                                    </Button>
                                )}
                                {order.table && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                        onClick={actions.move}
                                    >
                                        {t('Pindah / gabung')}
                                    </Button>
                                )}
                                {unallocated &&
                                    order.bills
                                        .filter(
                                            (b) =>
                                                b.status === 'unpaid' &&
                                                !b.is_unallocated,
                                        )
                                        .map((b) => (
                                            <Button
                                                key={b.id}
                                                type="button"
                                                variant="outline"
                                                className="h-11"
                                                onClick={() =>
                                                    actions.allocate(b)
                                                }
                                            >
                                                {t('Alokasi ke')} {b.label}
                                            </Button>
                                        ))}
                                <Button
                                    type="button"
                                    variant="ghost"
                                    className="h-11 text-destructive"
                                    onClick={actions.voidOrder}
                                >
                                    {t('Void order')}
                                </Button>
                            </div>
                        </div>
                    )}

                {order && order.status === 'paid' && (
                    <div className="grid gap-2">
                        {order.bills.map((b) => (
                            <Button
                                key={b.id}
                                type="button"
                                variant="outline"
                                className="h-11"
                                onClick={() => actions.reprint(b)}
                            >
                                {t('Cetak ulang struk')} {b.label ?? ''}
                            </Button>
                        ))}
                    </div>
                )}
            </footer>
        </section>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between">
            <dt className="text-muted-foreground">{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}
