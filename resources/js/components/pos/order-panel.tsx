import { MoreVertical, Minus, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { t } from '@/lang';
import { rupiah, timeId } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Bill, CartLine, Order, OrderItem, Payment } from '@/types/pos';

const ITEM_STATUS: Record<string, { label: string; cls: string }> = {
    pending: {
        label: 'Menunggu konfirmasi',
        cls: 'bg-muted text-muted-foreground',
    },
    new: {
        label: 'Baru',
        cls: 'bg-sky-100 dark:bg-sky-950/50 dark:text-sky-200 text-sky-900',
    },
    preparing: {
        label: 'Dibuat',
        cls: 'bg-amber-100 dark:bg-amber-950/50 dark:text-amber-200 text-amber-900',
    },
    ready: {
        label: 'Siap',
        cls: 'bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-200 text-emerald-900',
    },
    voided: {
        label: 'VOID',
        cls: 'bg-red-100 dark:bg-red-950/50 dark:text-red-200 text-red-800',
    },
    rejected: { label: 'Dihapus', cls: 'bg-muted text-muted-foreground' },
};

export type PanelActions = {
    send: () => void;
    sendAndPay: () => void;
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

/** "Bayar per item": cashier ticks items/qty, sees the server-priced total, then pays just those. */
export type PickState = {
    active: boolean;
    qty: Record<number, number>;
    available: (item: OrderItem) => number;
    set: (id: number, qty: number) => void;
    toggle: () => void;
    busy: boolean;
    preview: {
        subtotal: number;
        discount_total: number;
        rounding: number;
        grand_total: number;
        charges: { name: string; amount: number; is_inclusive: boolean }[];
    } | null;
    supported: boolean;
    submit: () => void;
};

export function OrderPanel({
    title,
    subtitle,
    order,
    cart,
    can,
    actions,
    pick,
    showKitchenStatus = true,
}: {
    title: string;
    subtitle?: string;
    order: Order | null;
    cart: CartLine[];
    can: { pay: boolean; void: boolean; refund: boolean; discount: boolean };
    actions: PanelActions;
    pick?: PickState;
    showKitchenStatus?: boolean;
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
            <header className="flex items-start justify-between gap-2 border-b px-4 py-3">
                <div>
                    <h2 className="text-lg leading-tight font-semibold">
                        {title}
                    </h2>
                    {subtitle && (
                        <p className="text-xs text-muted-foreground">
                            {subtitle}
                        </p>
                    )}
                    {order && (order.cashier || order.bill_requested_by) && (
                        <p className="text-xs text-muted-foreground">
                            {order.cashier &&
                                `${t('Dibuka oleh')} ${order.cashier}`}
                            {order.bill_requested_by &&
                                ` · ${t('bill diminta')} ${order.bill_requested_by}`}
                        </p>
                    )}
                </div>
                {order && (
                    <span className="shrink-0 rounded-md bg-muted px-2 py-1 text-xs font-medium">
                        {order.status_label}
                    </span>
                )}
            </header>

            <div className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4">
                {order?.rounds.map((round, idx) => (
                    <div key={round.id}>
                        <p className="mb-1.5 flex flex-wrap items-center gap-x-2 text-xs font-medium text-muted-foreground">
                            {t('Ronde')} {idx + 1} · {timeId(round.created_at)}
                            {round.source === 'pos' && round.by && (
                                <span>
                                    · {t('oleh')} {round.by}
                                </span>
                            )}
                            {round.source === 'qr' && round.confirmed_by && (
                                <span>
                                    · {t('dikonfirmasi')} {round.confirmed_by}
                                </span>
                            )}
                            {round.source === 'qr' && (
                                <span className="rounded bg-violet-100 px-1.5 text-[10px] font-semibold text-violet-800 dark:bg-violet-950/50 dark:text-violet-200">
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
                        <ul className="divide-y rounded-lg border">
                            {round.items
                                .filter((i) => i.status !== 'rejected')
                                .map((i) => (
                                    <li
                                        key={i.id}
                                        className={cn(
                                            'px-3 py-2.5 text-sm',
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
                                                    <p className="text-xs font-medium text-fuchsia-700 dark:text-fuchsia-400">
                                                        {i.comp_type ===
                                                        'staff_meal'
                                                            ? t('Staff meal')
                                                            : t('Komplimen')}
                                                    </p>
                                                )}
                                                {i.void_reason && (
                                                    <p className="text-xs text-red-700 dark:text-red-400">
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
                                                {(showKitchenStatus ||
                                                    i.status === 'voided') && (
                                                    <span
                                                        className={cn(
                                                            'rounded-md px-1.5 py-0.5 text-[10px] font-medium',
                                                            ITEM_STATUS[
                                                                i.status
                                                            ]?.cls,
                                                        )}
                                                    >
                                                        {t(
                                                            ITEM_STATUS[
                                                                i.status
                                                            ]?.label ??
                                                                i.status,
                                                        )}
                                                    </span>
                                                )}
                                            </div>
                                            {editable &&
                                                !pick?.active &&
                                                [
                                                    'new',
                                                    'preparing',
                                                    'ready',
                                                ].includes(i.status) && (
                                                    <ItemMenu
                                                        item={i}
                                                        actions={actions}
                                                    />
                                                )}
                                        </div>
                                        {pick?.active &&
                                            [
                                                'new',
                                                'preparing',
                                                'ready',
                                            ].includes(i.status) &&
                                            !i.comp_type && (
                                                <PickRow item={i} pick={pick} />
                                            )}
                                        {editable &&
                                            !pick?.active &&
                                            [
                                                'new',
                                                'preparing',
                                                'ready',
                                            ].includes(i.status) && (
                                                <div className="mt-1 hidden flex-wrap gap-1 md:flex">
                                                    <Button
                                                        type="button"
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8 px-2 text-xs md:h-9"
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
                                                        className="h-8 px-2 text-xs md:h-9"
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
                                                            className="h-8 px-2 text-xs md:h-9"
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
                        <p className="mb-1 text-xs font-semibold text-emerald-700 dark:text-emerald-400">
                            {t('Item baru (belum dikirim)')}
                        </p>
                        <ul className="grid gap-1">
                            {cart.map((l) => (
                                <CartRow
                                    key={l.key}
                                    line={l}
                                    actions={actions}
                                />
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
                    <div className="rounded-lg border p-3 text-sm">
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
                                                <span className="text-xs text-amber-700 dark:text-amber-400">
                                                    {t('Alokasikan dulu')}
                                                </span>
                                            )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                        {unallocated && (
                            <p className="mt-1 text-xs text-amber-700 dark:text-amber-400">
                                {t(
                                    'Ada item baru setelah split. Pecah ulang atau alokasikan sebelum membayar.',
                                )}
                            </p>
                        )}
                    </div>
                )}

                {order && order.payments.length > 0 && (
                    <div className="rounded-lg border p-3 text-sm">
                        <p className="mb-1 font-medium">{t('Pembayaran')}</p>
                        {order.payments.map((p) => (
                            <div
                                key={p.id}
                                className="flex items-center justify-between gap-2"
                            >
                                <span>
                                    {p.method}
                                    {p.reference ? ` · ${p.reference}` : ''}
                                    {p.by && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            · {t('diterima')} {p.by}{' '}
                                            {timeId(p.created_at)}
                                        </span>
                                    )}
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

            <footer className="grid gap-2 border-t p-3 md:p-4">
                {order && liveItems.length > 0 && (
                    <dl className="grid gap-0.5 text-sm">
                        <Row
                            label={t('Subtotal')}
                            value={rupiah(order.subtotal)}
                        />
                        {order.discounts.map((d) => (
                            <div
                                key={d.id}
                                className="flex items-center justify-between text-emerald-700 dark:text-emerald-400"
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
                        <div className="mt-1 flex items-center justify-between border-t pt-1.5 text-lg font-semibold tracking-tight md:pt-2 md:text-xl">
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
                            className="h-12 text-base md:h-14"
                            disabled={actions.sending}
                            onClick={actions.send}
                        >
                            {actions.sending
                                ? t('Mengirim…')
                                : order
                                  ? t('Tambah ronde ke dapur')
                                  : t('Kirim ke dapur')}
                        </Button>
                        {can.pay && (
                            <Button
                                type="button"
                                variant="outline"
                                className="h-12 border-primary text-primary"
                                disabled={actions.sending}
                                onClick={actions.sendAndPay}
                            >
                                {t('Kirim & bayar langsung')}
                            </Button>
                        )}
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

                {pick?.active && order && <PickSummary pick={pick} />}

                {order &&
                    order.is_editable &&
                    cart.length === 0 &&
                    !pick?.active &&
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
                                            className="h-12 text-base md:h-14"
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
                            <div className="flex [scrollbar-width:none] gap-2 overflow-x-auto md:grid md:grid-cols-2 md:overflow-visible">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10 shrink-0 px-3 md:h-11"
                                    onClick={actions.billRequest}
                                >
                                    {t('Pre-bill')}
                                </Button>
                                {can.discount && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-10 shrink-0 px-3 md:h-11"
                                        onClick={() => actions.discount(null)}
                                    >
                                        {t('Diskon')}
                                    </Button>
                                )}
                                {can.pay && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-10 shrink-0 px-3 md:h-11"
                                        onClick={actions.split}
                                    >
                                        {t('Split bill')}
                                    </Button>
                                )}
                                {order.table && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-10 shrink-0 px-3 md:h-11"
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
                                                className="h-10 shrink-0 px-3 md:h-11"
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
                                    className="h-10 shrink-0 px-3 text-destructive md:h-11"
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

/** Phone: Void / Diskon / Komplimen behind one button so each order line stays a single, readable row. */
function ItemMenu({
    item: i,
    actions,
}: {
    item: OrderItem;
    actions: PanelActions;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="-mt-1 -mr-1 size-10 shrink-0 text-muted-foreground md:hidden"
                    aria-label={`${t('Aksi')} ${i.name}`}
                >
                    <MoreVertical />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="min-w-40">
                <DropdownMenuItem
                    className="h-11 text-base"
                    onSelect={() => actions.discount(i)}
                >
                    {t('Diskon')}
                </DropdownMenuItem>
                {!i.comp_type && (
                    <DropdownMenuItem
                        className="h-11 text-base"
                        onSelect={() => actions.comp(i)}
                    >
                        {t('Komplimen')}
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem
                    className="h-11 text-base text-destructive focus:text-destructive"
                    onSelect={() => actions.voidItem(i)}
                >
                    {t('Void')}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function CartRow({
    line: l,
    actions,
}: {
    line: CartLine;
    actions: PanelActions;
}) {
    const [note, setNote] = useState(l.note !== '');

    return (
        <li className="px-3 py-2.5 text-sm">
            <div className="flex justify-between gap-3">
                <p className="min-w-0 font-medium">{l.label}</p>
                <p className="shrink-0 font-semibold tabular-nums">
                    {rupiah(l.unitPrice * l.qty)}
                </p>
            </div>
            {note && (
                <input
                    type="text"
                    value={l.note}
                    maxLength={200}
                    autoFocus={l.note === ''}
                    placeholder={t('Catatan (mis. less ice, tanpa gula)')}
                    aria-label={`${t('Catatan')} ${l.label}`}
                    onChange={(e) => actions.setNote(l.key, e.target.value)}
                    className="mt-1.5 h-10 w-full rounded-md border border-input bg-background px-2.5 text-base sm:text-sm"
                />
            )}
            <div className="mt-1.5 flex items-center gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-10"
                    aria-label={t('Kurangi')}
                    onClick={() => actions.setQty(l.key, -1)}
                >
                    <Minus />
                </Button>
                <span
                    className="w-8 text-center font-semibold tabular-nums"
                    aria-live="polite"
                >
                    {l.qty}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-10"
                    aria-label={t('Tambah')}
                    onClick={() => actions.setQty(l.key, 1)}
                >
                    <Plus />
                </Button>
                {!note && (
                    <button
                        type="button"
                        className="ml-2 h-10 px-1 text-sm text-primary underline-offset-2 hover:underline"
                        onClick={() => setNote(true)}
                    >
                        {t('Catatan')}
                    </button>
                )}
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="ml-auto size-10 text-muted-foreground"
                    aria-label={t('Hapus')}
                    onClick={() => actions.remove(l.key)}
                >
                    <Trash2 />
                </Button>
            </div>
        </li>
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

function PickRow({ item, pick }: { item: OrderItem; pick: PickState }) {
    const avail = pick.available(item);
    const n = pick.qty[item.id] ?? 0;

    if (avail <= 0) {
        return (
            <p className="mt-1 text-xs font-medium text-emerald-700 dark:text-emerald-400">
                {t('Sudah dibayar')}
            </p>
        );
    }

    return (
        <div
            className="mt-2 flex items-center gap-1.5"
            role="group"
            aria-label={`${t('Bayar')} ${item.name}`}
        >
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-11"
                aria-label={t('Kurangi')}
                onClick={() => pick.set(item.id, Math.max(0, n - 1))}
            >
                <Minus />
            </Button>
            <span
                className="min-w-14 text-center text-sm font-bold tabular-nums"
                aria-live="polite"
            >
                {n} / {avail}
            </span>
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-11"
                aria-label={t('Tambah')}
                onClick={() => pick.set(item.id, Math.min(avail, n + 1))}
            >
                <Plus />
            </Button>
            <Button
                type="button"
                variant={n === avail ? 'default' : 'outline'}
                size="sm"
                className="ml-auto h-11"
                onClick={() => pick.set(item.id, n === avail ? 0 : avail)}
            >
                {n === avail ? t('Batal pilih') : t('Semua')}
            </Button>
        </div>
    );
}

function PickSummary({ pick }: { pick: PickState }) {
    const count = Object.values(pick.qty).reduce((a, b) => a + b, 0);
    const p = pick.preview;

    return (
        <div
            className="grid gap-2 rounded-xl border border-primary/40 bg-primary/5 p-3"
            aria-live="polite"
        >
            <p className="text-sm font-semibold">{t('Bayar sebagian dulu')}</p>
            {count === 0 ? (
                <p className="text-sm text-muted-foreground">
                    {t('Pilih item dan jumlahnya di daftar pesanan.')}
                </p>
            ) : (
                <dl className="grid gap-0.5 text-sm">
                    <div className="flex justify-between">
                        <dt className="text-muted-foreground">
                            {count} {t('item dipilih')}
                        </dt>
                        <dd className="tabular-nums">
                            {p ? rupiah(p.subtotal) : '…'}
                        </dd>
                    </div>
                    {p && p.discount_total > 0 && (
                        <div className="flex justify-between text-emerald-700 dark:text-emerald-400">
                            <dt>{t('Diskon')}</dt>
                            <dd className="tabular-nums">
                                -{rupiah(p.discount_total)}
                            </dd>
                        </div>
                    )}
                    {p?.charges
                        .filter((c) => c.amount > 0)
                        .map((c) => (
                            <div key={c.name} className="flex justify-between">
                                <dt className="text-muted-foreground">
                                    {c.is_inclusive
                                        ? `${c.name} (${t('termasuk')})`
                                        : c.name}
                                </dt>
                                <dd className="tabular-nums">
                                    {rupiah(c.amount)}
                                </dd>
                            </div>
                        ))}
                    {p && p.rounding !== 0 && (
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">
                                {t('Pembulatan')}
                            </dt>
                            <dd className="tabular-nums">
                                {rupiah(p.rounding)}
                            </dd>
                        </div>
                    )}
                    <div className="mt-1 flex justify-between border-t pt-1 text-lg font-bold">
                        <dt>{t('Total dibayar')}</dt>
                        <dd className="tabular-nums">
                            {p ? rupiah(p.grand_total) : '…'}
                        </dd>
                    </div>
                </dl>
            )}
            <Button
                type="button"
                className="h-12 text-base md:h-14"
                disabled={count === 0 || pick.busy || !p}
                onClick={pick.submit}
            >
                {p && count > 0
                    ? `${t('Bayar')} ${rupiah(p.grand_total)}`
                    : t('Bayar yang dipilih')}
            </Button>
            <Button
                type="button"
                variant="ghost"
                className="h-11"
                onClick={pick.toggle}
            >
                {t('Batal')}
            </Button>
        </div>
    );
}
