import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { Field } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';

/** Asks for a mandatory reason (void, comp, refund, reject...). */
export function ReasonDialog({
    title,
    description,
    confirmLabel,
    destructive,
    extra,
    quick,
    onClose,
    onSubmit,
}: {
    title: string;
    description?: string;
    confirmLabel: string;
    destructive?: boolean;
    extra?: React.ReactNode;
    quick?: string[];
    onClose: () => void;
    onSubmit: (reason: string) => Promise<void> | void;
}) {
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <Modal open onClose={onClose} title={title} description={description}>
            {extra}
            <Field label={t('Alasan (wajib)')}>
                <Input
                    className="h-11"
                    autoFocus
                    value={reason}
                    maxLength={200}
                    onChange={(e) => setReason(e.target.value)}
                />
            </Field>
            {quick && (
                <div className="flex flex-wrap gap-2">
                    {quick.map((q) => (
                        <Button
                            key={q}
                            type="button"
                            size="sm"
                            variant={reason === q ? 'default' : 'outline'}
                            className="h-9"
                            onClick={() => setReason(q)}
                        >
                            {q}
                        </Button>
                    ))}
                </div>
            )}
            <Button
                type="button"
                variant={destructive ? 'destructive' : 'default'}
                className="h-12"
                disabled={busy || reason.trim() === ''}
                onClick={async () => {
                    setBusy(true);

                    try {
                        await onSubmit(reason.trim());
                    } finally {
                        setBusy(false);
                    }
                }}
            >
                {confirmLabel}
            </Button>
        </Modal>
    );
}
