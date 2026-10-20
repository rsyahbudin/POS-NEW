import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Field, MoneyInput } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { rupiah, uuid } from '@/lib/format';
import type { Bill, Order, PaymentMethod } from '@/types/pos';

const QUICK = [50000, 100000, 150000, 200000];

type PayResult = {
    order: Order;
    payment_id: number;
    change: number;
    bill_public_id: string;
};

/**
 * One payment attempt = one idempotency key. The key is regenerated only after
 * a success, so a double tap or a retry on a slow connection can never create
 * two transactions (PRD 6.3).
 */
export function PaymentDialog({
    order,
    bill,
    methods,
    onClose,
    onPaid,
}: {
    order: Order;
    bill: Bill | null;
    methods: PaymentMethod[];
    onClose: () => void;
    onPaid: (r: PayResult, bill: Bill) => void;
}) {
    const [methodId, setMethodId] = useState<number>(methods[0]?.id ?? 0);
    const [amount, setAmount] = useState<number | ''>('');
    const [reference, setReference] = useState('');
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());
    const method = methods.find((m) => m.id === methodId);
    const remaining = bill?.remaining ?? 0;
    const isCash = method?.type === 'cash';

    useEffect(() => {
        setAmount(bill?.remaining ?? '');
        key.current = uuid();
    }, [bill?.id, bill?.remaining]);

    if (!bill) {
        return null;
    }

    const tendered = typeof amount === 'number' ? amount : 0;
    const change = isCash ? Math.max(0, tendered - remaining) : 0;
    const valid = tendered > 0 && (isCash || tendered <= remaining);

    const submit = async () => {
        if (busy || !valid) {
            return;
        }

        setBusy(true);

        try {
            const res = await api<PayResult>(
                'POST',
                `/pos/orders/${order.id}/pay`,
                {
                    bill_id: bill.id,
                    payment_method_id: methodId,
                    amount: tendered,
                    reference: reference || null,
                    idempotency_key: key.current,
                },
            );
            key.current = uuid();
            onPaid(res, bill);
        } catch (e) {
            // keep the same key: if the first request actually succeeded, retrying returns it instead of duplicating
            toast.error(
                e instanceof ApiError
                    ? e.message
                    : t('Gagal menyimpan pembayaran.'),
            );
            setBusy(false);
        }
    };

    return (
        <Modal
            open
            onClose={busy ? () => undefined : onClose}
            title={t('Pembayaran')}
            description={bill.label ?? order.number}
        >
            <div className="rounded-lg bg-muted p-3 text-center">
                <p className="text-xs text-muted-foreground">
                    {bill.label
                        ? `${t('Tagihan')} ${bill.label}`
                        : t('Sisa tagihan')}
                </p>
                <p className="text-3xl font-bold tabular-nums">
                    {rupiah(remaining)}
                </p>
            </div>

            <div
                role="radiogroup"
                aria-label={t('Metode pembayaran')}
                className="grid grid-cols-3 gap-2"
            >
                {methods.map((m) => (
                    <Button
                        key={m.id}
                        type="button"
                        role="radio"
                        aria-checked={methodId === m.id}
                        variant={methodId === m.id ? 'default' : 'outline'}
                        className="h-12"
                        onClick={() => {
                            setMethodId(m.id);
                            setAmount(remaining);
                        }}
                    >
                        {m.name}
                    </Button>
                ))}
            </div>

            <Field label={isCash ? t('Uang diterima') : t('Nominal')}>
                <MoneyInput
                    value={amount}
                    onChange={setAmount}
                    aria-label={t('Nominal pembayaran')}
                    autoFocus
                />
            </Field>

            {isCash && (
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        onClick={() => setAmount(remaining)}
                    >
                        {t('Uang pas')}
                    </Button>
                    {QUICK.map((q) => (
                        <Button
                            key={q}
                            type="button"
                            variant="outline"
                            className="h-11"
                            onClick={() => setAmount(q)}
                        >
                            {(q / 1000).toLocaleString('id-ID')}rb
                        </Button>
                    ))}
                </div>
            )}

            {!isCash && (
                <Field
                    label={t('Nomor referensi (opsional)')}
                    hint={t(
                        'Tidak ada verifikasi otomatis ke bank. Pastikan dana sudah masuk.',
                    )}
                >
                    <Input
                        className="h-11"
                        value={reference}
                        onChange={(e) => setReference(e.target.value)}
                        maxLength={80}
                    />
                </Field>
            )}

            {isCash && (
                <p className="text-center text-lg" aria-live="polite">
                    {t('Kembalian')}:{' '}
                    <strong className="tabular-nums">{rupiah(change)}</strong>
                </p>
            )}

            <Button
                type="button"
                className="h-14 text-base"
                disabled={!valid || busy}
                onClick={submit}
            >
                {busy
                    ? t('Memproses…')
                    : `${t('Bayar')} ${rupiah(Math.min(tendered, remaining))}`}
            </Button>
        </Modal>
    );
}
