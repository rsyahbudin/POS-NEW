import { useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { usePinGate } from '@/components/pos/pin-gate';
import { Field, MoneyInput } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import type { Order, OrderItem } from '@/types/pos';

export function DiscountDialog({
    order,
    item,
    limit,
    onClose,
    onDone,
}: {
    order: Order;
    item: OrderItem | null;
    limit: number;
    onClose: () => void;
    onDone: (o: Order) => void;
}) {
    const [type, setType] = useState<'percent' | 'fixed'>('percent');
    const [value, setValue] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const pin = usePinGate();

    const submit = async () => {
        setBusy(true);

        try {
            const v =
                typeof value === 'number'
                    ? type === 'percent'
                        ? value * 100
                        : value
                    : 0; // percent sent in basis points
            const res = await pin.run((p) =>
                api<{ order: Order }>(
                    'POST',
                    `/pos/orders/${order.id}/discounts`,
                    {
                        type,
                        value: v,
                        reason,
                        item_id: item?.id ?? null,
                        pin: p,
                    },
                ),
            );

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

    return (
        <Modal
            open
            onClose={onClose}
            title={item ? `${t('Diskon')} ${item.name}` : t('Diskon order')}
            description={`${t('Batas kasir tanpa PIN')}: ${limit}%`}
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
            <Field label={type === 'percent' ? t('Persentase') : t('Nominal')}>
                <MoneyInput value={value} onChange={setValue} autoFocus />
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
                onClick={submit}
            >
                {t('Terapkan')}
            </Button>
            {pin.dialog}
        </Modal>
    );
}
