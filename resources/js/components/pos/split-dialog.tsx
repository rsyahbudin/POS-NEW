import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Field, MoneyInput } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { rupiah } from '@/lib/format';
import type { Order, OrderItem } from '@/types/pos';

type Mode = 'item' | 'equal' | 'amount';

export function SplitDialog({
    order,
    onClose,
    onDone,
}: {
    order: Order;
    onClose: () => void;
    onDone: (o: Order) => void;
}) {
    const [mode, setMode] = useState<Mode>('equal');
    const [count, setCount] = useState(2);
    const [amounts, setAmounts] = useState<(number | '')[]>(['', '']);
    const [parts, setParts] = useState<Record<number, number>[]>([{}, {}]);
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
    const alreadySplit = order.bills.some((b) => b.mode !== null);
    const amountSum = amounts.reduce<number>(
        (s, a) => s + (typeof a === 'number' ? a : 0),
        0,
    );

    const assigned = (id: number) =>
        parts.reduce((s, p) => s + (p[id] ?? 0), 0);
    const itemsOk =
        items.every((i) => assigned(i.id) === i.qty) &&
        parts.every((p) => Object.values(p).some((q) => q > 0));

    const submit = async () => {
        setBusy(true);

        try {
            const body =
                mode === 'equal'
                    ? { mode, count }
                    : mode === 'amount'
                      ? {
                            mode,
                            parts: amounts.map((a, i) => ({
                                label: `Tamu ${i + 1}`,
                                amount: a,
                            })),
                        }
                      : {
                            mode,
                            parts: parts.map((p, i) => ({
                                label: `Tamu ${i + 1}`,
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
            toast.success(t('Tagihan dipecah.'));
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

    const resize = (n: number) => {
        setAmounts((a) => Array.from({ length: n }, (_, i) => a[i] ?? ''));
        setParts((p) => Array.from({ length: n }, (_, i) => p[i] ?? {}));
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Pecah tagihan')}
            description={`${order.number} · ${rupiah(order.grand_total)}`}
            className="sm:max-w-xl"
        >
            {alreadySplit && (
                <div className="flex items-center justify-between rounded-lg bg-muted p-3 text-sm">
                    <span>{t('Order sudah dipecah.')}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={cancelSplit}
                        disabled={busy || order.paid_total > 0}
                    >
                        {t('Batalkan split')}
                    </Button>
                </div>
            )}

            <div role="radiogroup" className="grid grid-cols-3 gap-2">
                {(
                    [
                        ['equal', 'Bagi rata'],
                        ['item', 'Per item'],
                        ['amount', 'Nominal bebas'],
                    ] as [Mode, string][]
                ).map(([m, label]) => (
                    <Button
                        key={m}
                        type="button"
                        role="radio"
                        aria-checked={mode === m}
                        variant={mode === m ? 'default' : 'outline'}
                        className="h-11"
                        onClick={() => setMode(m)}
                    >
                        {t(label)}
                    </Button>
                ))}
            </div>

            {mode === 'equal' && (
                <Field
                    label={t('Jumlah tagihan')}
                    hint={t(
                        'Sisa pembagian rupiah dibebankan ke tagihan terakhir.',
                    )}
                >
                    <Input
                        className="h-11"
                        type="number"
                        min={2}
                        max={20}
                        value={count}
                        onChange={(e) =>
                            setCount(
                                Math.max(
                                    2,
                                    Math.min(20, Number(e.target.value)),
                                ),
                            )
                        }
                    />
                </Field>
            )}

            {mode !== 'equal' && (
                <div className="flex items-center gap-2">
                    <span className="text-sm">{t('Jumlah tagihan')}</span>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('Kurangi')}
                        onClick={() => resize(Math.max(2, amounts.length - 1))}
                    >
                        −
                    </Button>
                    <span className="w-6 text-center tabular-nums">
                        {amounts.length}
                    </span>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-label={t('Tambah')}
                        onClick={() => resize(Math.min(12, amounts.length + 1))}
                    >
                        +
                    </Button>
                </div>
            )}

            {mode === 'amount' && (
                <div className="grid gap-2">
                    {amounts.map((a, i) => (
                        <Field key={i} label={`${t('Tamu')} ${i + 1}`}>
                            <MoneyInput
                                value={a}
                                onChange={(v) =>
                                    setAmounts((arr) =>
                                        arr.map((x, j) => (j === i ? v : x)),
                                    )
                                }
                            />
                        </Field>
                    ))}
                    <p
                        className={
                            amountSum === order.grand_total
                                ? 'text-sm text-emerald-700'
                                : 'text-sm text-destructive'
                        }
                        aria-live="polite"
                    >
                        {t('Total')} {rupiah(amountSum)} /{' '}
                        {rupiah(order.grand_total)}
                    </p>
                </div>
            )}

            {mode === 'item' && (
                <div className="grid gap-3">
                    {items.map((it) => (
                        <div key={it.id} className="rounded-lg border p-2">
                            <p className="text-sm font-medium">
                                {it.name}{' '}
                                <span className="text-muted-foreground">
                                    × {it.qty}
                                </span>
                            </p>
                            <div className="mt-2 flex flex-wrap gap-2">
                                {parts.map((p, i) => (
                                    <label
                                        key={i}
                                        className="flex items-center gap-1 text-xs"
                                    >
                                        {t('Tamu')} {i + 1}
                                        <input
                                            type="number"
                                            min={0}
                                            max={it.qty}
                                            value={p[it.id] ?? 0}
                                            aria-label={`${it.name} ${t('Tamu')} ${i + 1}`}
                                            className="h-10 w-14 rounded-md border border-input px-2 text-center"
                                            onChange={(e) =>
                                                setParts((arr) =>
                                                    arr.map((x, j) =>
                                                        j === i
                                                            ? {
                                                                  ...x,
                                                                  [it.id]:
                                                                      Math.max(
                                                                          0,
                                                                          Math.min(
                                                                              it.qty,
                                                                              Number(
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                              ),
                                                                          ),
                                                                      ),
                                                              }
                                                            : x,
                                                    ),
                                                )
                                            }
                                        />
                                    </label>
                                ))}
                            </div>
                            {assigned(it.id) !== it.qty && (
                                <p className="mt-1 text-xs text-destructive">
                                    {t('Alokasikan semua qty')} (
                                    {assigned(it.id)}/{it.qty})
                                </p>
                            )}
                        </div>
                    ))}
                </div>
            )}

            <Button
                type="button"
                className="h-12"
                disabled={
                    busy ||
                    (mode === 'amount' && amountSum !== order.grand_total) ||
                    (mode === 'item' && !itemsOk)
                }
                onClick={submit}
            >
                {t('Pecah tagihan')}
            </Button>
        </Modal>
    );
}
