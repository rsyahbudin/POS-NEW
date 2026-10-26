import { useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { ageMinutes, rupiah, timeId } from '@/lib/format';
import type { PendingQr } from '@/types/pos';

/**
 * Pending self-orders. Cashier may edit qty (or remove sold-out items) before
 * confirming; reject needs a reason that the guest sees (PRD 4.4).
 */
export function QrInbox({
    open,
    onClose,
    items,
    onChanged,
}: {
    open: boolean;
    onClose: () => void;
    items: PendingQr[];
    onChanged: () => void;
}) {
    const [edits, setEdits] = useState<Record<number, number>>({});
    const [rejecting, setRejecting] = useState<number | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState<number | null>(null);

    const act = async (fn: () => Promise<unknown>, id: number, ok: string) => {
        setBusy(id);

        try {
            await fn();
            toast.success(ok);
            setRejecting(null);
            setReason('');
            onChanged();
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        } finally {
            setBusy(null);
        }
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={`${t('Pesanan QR')} (${items.length})`}
            description={t('Pesanan baru dari meja menunggu konfirmasi')}
            className="sm:max-w-xl"
        >
            {items.length === 0 && (
                <p className="py-6 text-center text-sm text-muted-foreground">
                    {t('Tidak ada pesanan QR yang menunggu.')}
                </p>
            )}
            {items.map((r) => {
                const total = r.items.reduce(
                    (s, i) => s + (edits[i.id] ?? i.qty) * i.unit_price,
                    0,
                );

                return (
                    <article
                        key={r.id}
                        className="grid gap-2 rounded-xl border p-3"
                    >
                        <header className="flex items-center justify-between">
                            <h3 className="font-semibold">
                                {r.table ?? '-'} · {r.customer_name}
                            </h3>
                            <span
                                className={`text-xs ${ageMinutes(r.created_at) >= 3 ? 'font-bold text-red-600 dark:text-red-400' : 'text-muted-foreground'}`}
                            >
                                {timeId(r.created_at)} ·{' '}
                                {ageMinutes(r.created_at)} {t('mnt')}
                            </span>
                        </header>
                        <ul className="grid gap-1">
                            {r.items.map((i) => (
                                <li
                                    key={i.id}
                                    className="flex items-center justify-between gap-2 text-sm"
                                >
                                    <span className="min-w-0">
                                        {i.name}
                                        {i.modifiers.length > 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {i.modifiers.join(', ')}
                                            </span>
                                        )}
                                        {i.note && (
                                            <span className="block text-xs italic">
                                                “{i.note}”
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t('Kurangi')}
                                            onClick={() =>
                                                setEdits((e) => ({
                                                    ...e,
                                                    [i.id]: Math.max(
                                                        0,
                                                        (e[i.id] ?? i.qty) - 1,
                                                    ),
                                                }))
                                            }
                                        >
                                            −
                                        </Button>
                                        <span className="w-6 text-center tabular-nums">
                                            {edits[i.id] ?? i.qty}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="icon"
                                            className="size-10"
                                            aria-label={t('Tambah')}
                                            onClick={() =>
                                                setEdits((e) => ({
                                                    ...e,
                                                    [i.id]: Math.min(
                                                        99,
                                                        (e[i.id] ?? i.qty) + 1,
                                                    ),
                                                }))
                                            }
                                        >
                                            +
                                        </Button>
                                    </span>
                                </li>
                            ))}
                        </ul>
                        <p className="text-right text-sm font-medium tabular-nums">
                            {rupiah(total)}
                        </p>

                        {rejecting === r.id ? (
                            <div className="grid gap-2">
                                <Input
                                    className="h-11"
                                    autoFocus
                                    placeholder={t(
                                        'Alasan penolakan (ditampilkan ke customer)',
                                    )}
                                    value={reason}
                                    onChange={(e) => setReason(e.target.value)}
                                />
                                <div className="grid grid-cols-2 gap-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-11"
                                        onClick={() => setRejecting(null)}
                                    >
                                        {t('Batal')}
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="destructive"
                                        className="h-11"
                                        disabled={
                                            !reason.trim() || busy === r.id
                                        }
                                        onClick={() =>
                                            act(
                                                () =>
                                                    api(
                                                        'POST',
                                                        `/pos/rounds/${r.id}/reject`,
                                                        { reason },
                                                    ),
                                                r.id,
                                                t('Pesanan ditolak.'),
                                            )
                                        }
                                    >
                                        {t('Tolak')}
                                    </Button>
                                </div>
                            </div>
                        ) : (
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-12"
                                    onClick={() => setRejecting(r.id)}
                                >
                                    {t('Tolak')}
                                </Button>
                                <Button
                                    type="button"
                                    className="h-12"
                                    disabled={busy === r.id}
                                    onClick={() =>
                                        act(
                                            () =>
                                                api(
                                                    'POST',
                                                    `/pos/rounds/${r.id}/confirm`,
                                                    {
                                                        changes:
                                                            Object.fromEntries(
                                                                r.items
                                                                    .filter(
                                                                        (i) =>
                                                                            edits[
                                                                                i
                                                                                    .id
                                                                            ] !==
                                                                            undefined,
                                                                    )
                                                                    .map(
                                                                        (i) => [
                                                                            i.id,
                                                                            edits[
                                                                                i
                                                                                    .id
                                                                            ],
                                                                        ],
                                                                    ),
                                                            ),
                                                    },
                                                ),
                                            r.id,
                                            t('Dikirim ke dapur.'),
                                        )
                                    }
                                >
                                    {t('Konfirmasi')}
                                </Button>
                            </div>
                        )}
                    </article>
                );
            })}
        </Modal>
    );
}
