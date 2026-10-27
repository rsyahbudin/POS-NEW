import { Check, CircleDollarSign, UtensilsCrossed } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';

export type Incoming = {
    id: string;
    kind: 'order' | 'payment';
    text: string;
};

/** New table orders and QRIS payments that need no action but must be noticed. Rings until each is acknowledged. */
export function IncomingBar({
    items,
    onAck,
}: {
    items: Incoming[];
    onAck: (id: string) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <section
            role="alert"
            aria-label={t('Masuk dari meja')}
            className="flex flex-wrap items-center gap-2 border-b border-emerald-300 bg-emerald-100 px-3 py-2 text-emerald-950 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-50"
        >
            <span className="text-sm font-semibold">
                {t('Masuk dari meja')}
            </span>
            {items.map((i) => (
                <span
                    key={i.id}
                    className="flex items-center gap-2 rounded-full border border-emerald-400 bg-white py-1 pr-1 pl-3 text-sm dark:bg-emerald-900"
                >
                    {i.kind === 'payment' ? (
                        <CircleDollarSign className="size-4" aria-hidden />
                    ) : (
                        <UtensilsCrossed className="size-4" aria-hidden />
                    )}
                    <span>{i.text}</span>
                    <Button
                        type="button"
                        size="sm"
                        className="h-9 rounded-full"
                        onClick={() => onAck(i.id)}
                    >
                        <Check /> {t('Oke')}
                    </Button>
                </span>
            ))}
        </section>
    );
}
