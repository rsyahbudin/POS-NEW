import { Minus, Plus, Receipt, Users, UtensilsCrossed } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { rupiah } from '@/lib/format';
import type { Order, OrderItem } from '@/types/pos';

type Mode = 'choose' | 'equal' | 'person';

/**
 * Split bill: everyone pays their own share. "Bagi rata" divides the total, "Per orang" assigns
 * items to each person and shows what each pays. The third card pays only some items now.
 */
export function SplitDialog({
    order,
    canPayPartial,
    onPayPartial,
    onClose,
    onDone,
}: {
    order: Order;
    canPayPartial: boolean;
    onPayPartial: () => void;
    onClose: () => void;
    onDone: (o: Order) => void;
}) {
    const alreadySplit = order.bills.some((b) => b.mode !== null);
    const [mode, setMode] = useState<Mode>('choose');
    const [people, setPeople] = useState(2);
    const [active, setActive] = useState(0);
    const [parts, setParts] = useState<Record<number, number>[]>([{}, {}]);
    const [totals, setTotals] = useState<(number | null)[]>([null, null]);
    const [busy, setBusy] = useState(false);

    const items: OrderItem[] = useMemo(
        () =>
            order.rounds
                .flatMap((r) => r.items)
                .filter(
                    (i) =>
                        ['new', 'preparing', 'ready'].includes(i.status) &&
                        !i.comp_type,
                ),
        [order],
    );

    const assigned = (id: number) =>
        parts.reduce((s, p) => s + (p[id] ?? 0), 0);
    const left = (it: OrderItem) => it.qty - assigned(it.id);
    const allAssigned = items.every((i) => left(i) === 0);
    const everyoneHasItems = parts.every((p) =>
        Object.values(p).some((q) => q > 0),
    );
    const share = Math.floor(order.grand_total / people);

    const resize = (n: number) => {
        const next = Math.max(2, Math.min(12, n));
        setPeople(next);
        setParts((p) => Array.from({ length: next }, (_, i) => p[i] ?? {}));
        setTotals((p) => Array.from({ length: next }, (_, i) => p[i] ?? null));
        setActive((a) => Math.min(a, next - 1));
    };

    // What each person pays, priced by the server (tax, service, discount included).
    useEffect(() => {
        if (mode !== 'person') {
            return;
        }

        const timer = window.setTimeout(() => {
            parts.forEach((p, i) => {
                const picked = Object.entries(p)
                    .filter(([, q]) => q > 0)
                    .map(([id, qty]) => ({ order_item_id: Number(id), qty }));

                if (picked.length === 0) {
                    setTotals((arr) => arr.map((x, j) => (j === i ? 0 : x)));

                    return;
                }

                api<{ grand_total: number }>(
                    'POST',
                    `/pos/orders/${order.id}/carve/preview`,
                    { items: picked },
                )
                    .then((r) =>
                        setTotals((arr) =>
                            arr.map((x, j) => (j === i ? r.grand_total : x)),
                        ),
                    )
                    .catch(() => undefined);
            });
        }, 250);

        return () => window.clearTimeout(timer);
    }, [mode, parts, order.id]);

    const setQty = (personIdx: number, it: OrderItem, qty: number) => {
        const others = assigned(it.id) - (parts[personIdx][it.id] ?? 0);
        const clamped = Math.max(0, Math.min(it.qty - others, qty));
        setParts((arr) =>
            arr.map((x, j) =>
                j === personIdx ? { ...x, [it.id]: clamped } : x,
            ),
        );
    };

    const submit = async () => {
        setBusy(true);

        try {
            const body =
                mode === 'equal'
                    ? { mode: 'equal', count: people }
                    : {
                          mode: 'item',
                          parts: parts.map((p, i) => ({
                              label: `${t('Orang')} ${i + 1}`,
                              items: Object.entries(p)
                                  .filter(([, q]) => q > 0)
                                  .map(([id, qty]) => ({
                                      order_item_id: Number(id),
                                      qty,
                                  })),
                          })),
                      };
            const res = await api<{ order: Order }>(
                'POST',
                `/pos/orders/${order.id}/split`,
                body,
            );
            toast.success(t('Tagihan dipecah. Bayar satu per satu.'));
            onDone(res.order);
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.message : t('Gagal memecah tagihan.'),
            );
        } finally {
            setBusy(false);
        }
    };

    const cancelSplit = async () => {
        setBusy(true);

        try {
            const res = await api<{ order: Order }>(
                'DELETE',
                `/pos/orders/${order.id}/split`,
            );
            onDone(res.order);
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        } finally {
            setBusy(false);
        }
    };

    const Stepper = (
        <div className="flex items-center gap-2">
            <span className="text-sm">{t('Jumlah orang')}</span>
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-11"
                aria-label={t('Kurangi orang')}
                onClick={() => resize(people - 1)}
            >
                <Minus />
            </Button>
            <span className="w-8 text-center text-lg font-bold tabular-nums">
                {people}
            </span>
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-11"
                aria-label={t('Tambah orang')}
                onClick={() => resize(people + 1)}
            >
                <Plus />
            </Button>
        </div>
    );

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Split bill')}
            description={`${order.number} · ${rupiah(order.grand_total)}`}
            className="sm:max-w-xl"
        >
            {alreadySplit && (
                <div className="flex items-center justify-between gap-2 rounded-lg bg-muted p-3 text-sm">
                    <span>
                        {t(
                            'Tagihan sudah dipecah. Memecah ulang menggantikan yang lama.',
                        )}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={cancelSplit}
                        disabled={busy || order.paid_total > 0}
                    >
                        {t('Satukan lagi')}
                    </Button>
                </div>
            )}

            {mode === 'choose' && (
                <div className="grid gap-2">
                    <Choice
                        icon={<Users />}
                        title={t('Bagi rata')}
                        text={t('Total dibagi sama banyak ke beberapa orang.')}
                        onClick={() => setMode('equal')}
                    />
                    <Choice
                        icon={<UtensilsCrossed />}
                        title={t('Per orang (pilih item)')}
                        text={t(
                            'Tiap orang bayar item miliknya sendiri, pajak dan service ikut proporsional.',
                        )}
                        onClick={() => setMode('person')}
                    />
                    {canPayPartial && (
                        <Choice
                            icon={<Receipt />}
                            title={t('Bayar sebagian dulu')}
                            text={t(
                                'Pilih item yang dibayar sekarang, sisanya dibayar nanti.',
                            )}
                            onClick={onPayPartial}
                        />
                    )}
                </div>
            )}

            {mode === 'equal' && (
                <div className="grid gap-3">
                    {Stepper}
                    <div className="rounded-xl border bg-primary/5 p-4 text-center">
                        <p className="text-sm text-muted-foreground">
                            {t('Tiap orang membayar')}
                        </p>
                        <p className="text-3xl font-bold tabular-nums">
                            {rupiah(share)}
                        </p>
                        {share * people !== order.grand_total && (
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Sisa pembagian dibebankan ke orang terakhir.',
                                )}
                            </p>
                        )}
                    </div>
                    <Footer
                        busy={busy}
                        label={`${t('Buat')} ${people} ${t('tagihan')}`}
                        onBack={() => setMode('choose')}
                        onSubmit={submit}
                    />
                </div>
            )}

            {mode === 'person' && (
                <div className="grid gap-3">
                    {Stepper}
                    <div
                        role="tablist"
                        aria-label={t('Orang')}
                        className="grid grid-cols-2 gap-2 sm:grid-cols-3"
                    >
                        {parts.map((_, i) => (
                            <button
                                key={i}
                                type="button"
                                role="tab"
                                aria-selected={active === i}
                                onClick={() => setActive(i)}
                                className={cn(
                                    'rounded-xl border-2 p-2 text-left',
                                    active === i
                                        ? 'border-primary bg-primary/10'
                                        : 'border-border',
                                )}
                            >
                                <span className="block text-xs text-muted-foreground">
                                    {t('Orang')} {i + 1}
                                </span>
                                <span className="block font-bold tabular-nums">
                                    {totals[i] === null
                                        ? '…'
                                        : rupiah(totals[i] ?? 0)}
                                </span>
                            </button>
                        ))}
                    </div>

                    <ul className="grid gap-2">
                        {items.map((it) => {
                            const mine = parts[active]?.[it.id] ?? 0;

                            return (
                                <li
                                    key={it.id}
                                    className="flex items-center gap-2 rounded-lg border p-2"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-sm font-medium">
                                            {it.name}
                                        </p>
                                        <p
                                            className={cn(
                                                'text-xs',
                                                left(it) === 0
                                                    ? 'text-emerald-700'
                                                    : 'text-amber-700',
                                            )}
                                        >
                                            {left(it) === 0
                                                ? t('Sudah dibagi semua')
                                                : `${left(it)} ${t('belum dibagi')}`}{' '}
                                            · {it.qty} {t('total')}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-11"
                                        aria-label={`${t('Kurangi')} ${it.name}`}
                                        onClick={() =>
                                            setQty(active, it, mine - 1)
                                        }
                                    >
                                        <Minus />
                                    </Button>
                                    <span className="w-6 text-center font-bold tabular-nums">
                                        {mine}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="icon"
                                        className="size-11"
                                        aria-label={`${t('Tambah')} ${it.name}`}
                                        onClick={() =>
                                            setQty(active, it, mine + 1)
                                        }
                                    >
                                        <Plus />
                                    </Button>
                                </li>
                            );
                        })}
                    </ul>
                    {!allAssigned && (
                        <p
                            className="text-sm text-amber-700"
                            aria-live="polite"
                        >
                            {t(
                                'Masih ada item yang belum dibagi ke siapa pun.',
                            )}
                        </p>
                    )}
                    <Footer
                        busy={busy}
                        disabled={!allAssigned || !everyoneHasItems}
                        label={`${t('Buat')} ${people} ${t('tagihan')}`}
                        onBack={() => setMode('choose')}
                        onSubmit={submit}
                    />
                </div>
            )}
        </Modal>
    );
}

function Choice({
    icon,
    title,
    text,
    onClick,
}: {
    icon: React.ReactNode;
    title: string;
    text: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex items-center gap-3 rounded-xl border-2 p-3 text-left hover:border-primary"
        >
            <span className="text-primary [&_svg]:size-6">{icon}</span>
            <span>
                <span className="block font-semibold">{title}</span>
                <span className="block text-sm text-muted-foreground">
                    {text}
                </span>
            </span>
        </button>
    );
}

function Footer({
    busy,
    disabled,
    label,
    onBack,
    onSubmit,
}: {
    busy: boolean;
    disabled?: boolean;
    label: string;
    onBack: () => void;
    onSubmit: () => void;
}) {
    return (
        <div className="grid grid-cols-[auto_1fr] gap-2">
            <Button
                type="button"
                variant="outline"
                className="h-12"
                onClick={onBack}
            >
                {t('Kembali')}
            </Button>
            <Button
                type="button"
                className="h-12"
                disabled={busy || disabled}
                onClick={onSubmit}
            >
                {label}
            </Button>
        </div>
    );
}
