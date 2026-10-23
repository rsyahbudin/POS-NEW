import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { MoneyInput } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';

/** Opens the cashier's shift without leaving the POS: count the drawer, confirm the starting cash, start selling. */
export function OpenShiftDialog({
    suggested,
    onClose,
}: {
    suggested: number;
    onClose: () => void;
}) {
    const [cash, setCash] = useState<number | ''>(suggested);
    const [busy, setBusy] = useState(false);

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Buka shift')}
            description={t(
                'Hitung uang di laci, lalu pastikan jumlahnya sama dengan modal di bawah.',
            )}
        >
            <div className="grid gap-1.5">
                <label className="text-sm font-medium" htmlFor="opening-cash">
                    {t('Modal awal di laci')}
                </label>
                <MoneyInput
                    id="opening-cash"
                    value={cash}
                    onChange={setCash}
                    autoFocus
                />
                {suggested > 0 && (
                    <p className="text-xs text-muted-foreground">
                        {t('Terisi dari uang yang ditinggal shift sebelumnya.')}
                    </p>
                )}
            </div>
            <Button
                type="button"
                className="h-12 text-base"
                disabled={busy || cash === ''}
                onClick={() => {
                    setBusy(true);
                    router.post(
                        '/shift/open',
                        { opening_cash: cash === '' ? 0 : cash },
                        {
                            preserveScroll: true,
                            onSuccess: onClose,
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                {busy ? t('Membuka…') : t('Buka shift')}
            </Button>
        </Modal>
    );
}
