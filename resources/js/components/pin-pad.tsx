import { router } from '@inertiajs/react';
import { Delete } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { cn } from '@/lib/utils';

const LENGTH = 6;

/** PIN entry for floor staff: big touch keys, six dots, signs in by itself on the sixth digit. A real keyboard works too. */
export function PinPad({ error }: { error?: string }) {
    const [pin, setPin] = useState('');
    const [busy, setBusy] = useState(false);
    const [shake, setShake] = useState(false);
    const [failed, setFailed] = useState<string | null>(error ?? null);
    const sent = useRef('');

    const submit = useCallback((value: string) => {
        if (sent.current === value) {
            return;
        }

        sent.current = value;
        setBusy(true);
        router.post(
            '/login/pin',
            { pin: value },
            {
                preserveScroll: true,
                onError: (errors) => {
                    setFailed(errors.pin ?? 'PIN salah.');
                    setShake(true);
                    window.setTimeout(() => setShake(false), 400);
                },
                onFinish: () => {
                    setBusy(false);
                    setPin('');
                    sent.current = '';
                },
            },
        );
    }, []);

    const press = useCallback(
        (digit: string) => {
            if (busy) {
                return;
            }

            setFailed(null);
            setPin((p) => {
                if (p.length >= LENGTH) {
                    return p;
                }

                const next = p + digit;

                if (next.length === LENGTH) {
                    window.setTimeout(() => submit(next), 120);
                }

                return next;
            });
        },
        [busy, submit],
    );

    const erase = useCallback(() => {
        if (!busy) {
            setFailed(null);
            setPin((p) => p.slice(0, -1));
        }
    }, [busy]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (/^[0-9]$/.test(e.key)) {
                press(e.key);
            } else if (e.key === 'Backspace') {
                erase();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [press, erase]);

    const key =
        'grid size-[4.25rem] place-items-center justify-self-center rounded-full bg-muted text-2xl font-semibold transition-transform select-none active:scale-90 active:bg-accent disabled:opacity-50';

    return (
        <div className="grid justify-items-center gap-6">
            <div
                role="img"
                aria-label={`${pin.length} dari ${LENGTH} digit`}
                className={cn(
                    'flex gap-3.5',
                    shake && 'animate-[pin-shake_0.35s_ease-in-out]',
                )}
            >
                {Array.from({ length: LENGTH }, (_, i) => (
                    <span
                        key={i}
                        className={cn(
                            'size-3.5 rounded-full border-2 transition-colors',
                            i < pin.length
                                ? failed
                                    ? 'border-destructive bg-destructive'
                                    : 'border-primary bg-primary'
                                : 'border-muted-foreground/40',
                        )}
                    />
                ))}
            </div>
            <p
                role="alert"
                className="-mt-3 h-5 text-sm font-medium text-destructive"
            >
                {failed}
            </p>
            <div className="grid w-full max-w-[17.5rem] grid-cols-3 gap-y-3.5">
                {['1', '2', '3', '4', '5', '6', '7', '8', '9'].map((d) => (
                    <button
                        key={d}
                        type="button"
                        className={key}
                        aria-label={`Angka ${d}`}
                        disabled={busy}
                        onClick={() => press(d)}
                    >
                        {d}
                    </button>
                ))}
                <span aria-hidden />
                <button
                    type="button"
                    className={key}
                    aria-label="Angka 0"
                    disabled={busy}
                    onClick={() => press('0')}
                >
                    0
                </button>
                <button
                    type="button"
                    className={cn(key, 'bg-transparent')}
                    aria-label="Hapus digit terakhir"
                    disabled={busy || pin.length === 0}
                    onClick={erase}
                >
                    <Delete className="size-6" aria-hidden />
                </button>
            </div>
        </div>
    );
}
