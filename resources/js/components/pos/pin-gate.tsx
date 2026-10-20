import { useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Modal } from '@/components/pos/modal';
import { ApiError } from '@/lib/api';
import { t } from '@/lang';

type Pending = { resolve: (pin: string | null) => void; message: string };

/**
 * "PIN manager": run an action; if the server asks for a manager PIN the
 * keypad opens, then the action is retried with the PIN (PRD section 3).
 */
export function usePinGate() {
    const [pending, setPending] = useState<Pending | null>(null);
    const [pin, setPin] = useState('');
    const lock = useRef(false);

    const ask = useCallback(
        (message: string) =>
            new Promise<string | null>((resolve) => {
                setPin('');
                setPending({ resolve, message });
            }),
        [],
    );

    const run = useCallback(
        async <T,>(
            action: (pin?: string) => Promise<T>,
        ): Promise<T | undefined> => {
            let current: string | undefined;

            for (let attempt = 0; attempt < 6; attempt++) {
                try {
                    return await action(current);
                } catch (e) {
                    if (
                        !(e instanceof ApiError) ||
                        !/PIN/.test(e.message) ||
                        /Terlalu banyak/.test(e.message)
                    ) {
                        throw e;
                    }

                    const entered = await ask(e.message);

                    if (entered === null) {
                        return undefined;
                    }

                    current = entered;
                }
            }

            return undefined;
        },
        [ask],
    );

    const close = (value: string | null) => {
        if (lock.current) {
            return;
        }

        lock.current = true;
        pending?.resolve(value);
        setPending(null);
        lock.current = false;
    };

    const dialog: ReactNode = (
        <Modal
            open={pending !== null}
            onClose={() => close(null)}
            title={t('PIN Manager')}
            description={pending?.message}
        >
            <p className="text-sm text-muted-foreground">{pending?.message}</p>
            <div aria-live="polite" className="flex justify-center gap-2 py-2">
                {Array.from({ length: 6 }).map((_, i) => (
                    <span
                        key={i}
                        className={`size-4 rounded-full border ${i < pin.length ? 'border-primary bg-primary' : ''}`}
                    />
                ))}
            </div>
            <div className="grid grid-cols-3 gap-2">
                {[
                    '1',
                    '2',
                    '3',
                    '4',
                    '5',
                    '6',
                    '7',
                    '8',
                    '9',
                    'C',
                    '0',
                    '⌫',
                ].map((k) => (
                    <Button
                        key={k}
                        type="button"
                        variant="outline"
                        className="h-12 text-lg"
                        onClick={() =>
                            setPin((p) =>
                                k === 'C'
                                    ? ''
                                    : k === '⌫'
                                      ? p.slice(0, -1)
                                      : p.length < 6
                                        ? p + k
                                        : p,
                            )
                        }
                    >
                        {k}
                    </Button>
                ))}
            </div>
            <Button
                type="button"
                className="h-12"
                disabled={pin.length !== 6}
                onClick={() => close(pin)}
            >
                {t('Setujui')}
            </Button>
        </Modal>
    );

    return { run, dialog };
}
