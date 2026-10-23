import { Lock } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { usePinGate } from '@/components/pos/pin-gate';
import { Field, MoneyInput } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { DiscountPreset, Order, OrderItem } from '@/types/pos';

const label = (p: DiscountPreset) =>
    p.type === 'percent'
        ? `${(p.value / 100).toLocaleString('id-ID')}%`
        : rupiah(p.value);

/**
 * Discounts for an order or one item. The owner's registered discounts (Staff 10%, Owner 20% ...) are one tap each;
 * typing a value yourself is only offered to roles that have the "Diskon manual" permission.
 */
export function DiscountDialog({
    order,
    item,
    limit,
    presets,
    customAllowed,
    onClose,
    onDone,
}: {
    order: Order;
    item: OrderItem | null;
    limit: number;
    presets: DiscountPreset[];
    customAllowed: boolean;
    onClose: () => void;
    onDone: (o: Order) => void;
}) {
    const [manual, setManual] = useState(presets.length === 0);
    const [type, setType] = useState<'percent' | 'fixed'>('percent');
    const [value, setValue] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const pin = usePinGate();

    const run = async (
        send: (pinCode?: string) => Promise<{ order: Order }>,
    ) => {
        setBusy(true);

        try {
            const res = await pin.run((p) => send(p));

            if (res) {
                toast.success(t('Diskon diterapkan.'));
                onDone(res.order);
            }
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        } finally {
            setBusy(false);
        }
    };

    const usePreset = (p: DiscountPreset) =>
        run((pinCode) =>
            api<{ order: Order }>(
                'POST',
                `/pos/orders/${order.id}/discount-presets/${p.id}`,
                { item_id: item?.id ?? null, pin: pinCode },
            ),
        );

    const submitManual = () =>
        run((pinCode) =>
            api<{ order: Order }>('POST', `/pos/orders/${order.id}/discounts`, {
                type,
                value:
                    typeof value === 'number'
                        ? type === 'percent'
                            ? value * 100
                            : value
                        : 0, // percent is sent in basis points
                reason,
                item_id: item?.id ?? null,
                pin: pinCode,
            }),
        );

    return (
        <Modal
            open
            onClose={onClose}
            title={item ? `${t('Diskon')} ${item.name}` : t('Diskon order')}
            description={
                presets.length > 0
                    ? t('Pilih diskon yang sudah terdaftar.')
                    : `${t('Batas kasir tanpa PIN')}: ${limit}%`
            }
        >
            {presets.length > 0 && (
                <ul className="grid gap-2">
                    {presets.map((p) => (
                        <li key={p.id}>
                            <button
                                type="button"
                                disabled={busy}
                                onClick={() => void usePreset(p)}
                                className="flex min-h-14 w-full items-center justify-between gap-3 rounded-xl border bg-card px-4 text-left transition-colors hover:border-primary/50 hover:bg-accent/40 disabled:opacity-60"
                            >
                                <span className="font-medium">{p.name}</span>
                                <span className="flex items-center gap-2">
                                    {p.needs_pin && (
                                        <Lock
                                            className="size-4 text-muted-foreground"
                                            aria-label={t('Butuh PIN manager')}
                                        />
                                    )}
                                    <span className="text-lg font-semibold tabular-nums">
                                        {label(p)}
                                    </span>
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}

            {presets.length === 0 && !customAllowed && (
                <p className="rounded-lg bg-muted p-3 text-sm">
                    {t(
                        'Belum ada diskon yang terdaftar. Minta owner menambahkannya di Pengaturan › Promo.',
                    )}
                </p>
            )}

            {customAllowed && presets.length > 0 && !manual && (
                <button
                    type="button"
                    className="h-11 text-sm text-primary underline-offset-2 hover:underline"
                    onClick={() => setManual(true)}
                >
                    {t('Diskon manual (isi sendiri)')}
                </button>
            )}

            {customAllowed && manual && (
                <div
                    className={cn(
                        'grid gap-3',
                        presets.length > 0 && 'border-t pt-4',
                    )}
                >
                    <div role="radiogroup" className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            role="radio"
                            aria-checked={type === 'percent'}
                            variant={type === 'percent' ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => setType('percent')}
                        >
                            %
                        </Button>
                        <Button
                            type="button"
                            role="radio"
                            aria-checked={type === 'fixed'}
                            variant={type === 'fixed' ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => setType('fixed')}
                        >
                            Rp
                        </Button>
                    </div>
                    <Field
                        label={
                            type === 'percent' ? t('Persentase') : t('Nominal')
                        }
                    >
                        <MoneyInput
                            value={value}
                            onChange={setValue}
                            autoFocus={presets.length === 0}
                        />
                    </Field>
                    <Field label={t('Alasan (wajib)')}>
                        <Input
                            className="h-11"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={200}
                        />
                    </Field>
                    <Button
                        type="button"
                        className="h-12"
                        disabled={busy || !value || reason.trim() === ''}
                        onClick={() => void submitManual()}
                    >
                        {t('Terapkan')}
                    </Button>
                </div>
            )}
            {pin.dialog}
        </Modal>
    );
}
