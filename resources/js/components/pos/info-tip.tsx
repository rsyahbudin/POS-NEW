import { Info } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

type Place = { top: number; left: number; width: number };

/**
 * A small "i" button that opens a short explanation next to it. Long help text goes here instead of
 * sitting on the page: tap the icon to read, tap anywhere else (or press Esc) to close.
 */
export function InfoTip({
    children,
    label = 'Info',
    className,
    chip,
}: {
    children: ReactNode;
    label?: string;
    className?: string;
    /** Show the label next to the icon (for page-level notes) instead of the icon alone. */
    chip?: boolean;
}) {
    const [place, setPlace] = useState<Place | null>(null);
    const button = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const id = useId();
    const open = place !== null;

    useEffect(() => {
        if (!open) {
            return;
        }

        const close = () => setPlace(null);
        const outside = (e: PointerEvent) => {
            const t = e.target as Node;

            if (!panel.current?.contains(t) && !button.current?.contains(t)) {
                close();
            }
        };
        const key = (e: KeyboardEvent) => e.key === 'Escape' && close();
        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', key);
        window.addEventListener('resize', close);
        window.addEventListener('scroll', close, true);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', key);
            window.removeEventListener('resize', close);
            window.removeEventListener('scroll', close, true);
        };
    }, [open]);

    const toggle = () => {
        if (open || !button.current) {
            setPlace(null);

            return;
        }

        const r = button.current.getBoundingClientRect();
        const width = Math.min(320, window.innerWidth - 32);
        const left = Math.min(
            Math.max(16, r.left + r.width / 2 - width / 2),
            window.innerWidth - width - 16,
        );
        setPlace({ top: r.bottom + 8, left, width });
    };

    return (
        <>
            <button
                ref={button}
                type="button"
                aria-label={label}
                aria-expanded={open}
                aria-controls={open ? id : undefined}
                onClick={toggle}
                className={cn(
                    'inline-flex shrink-0 items-center gap-1 rounded-full text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                    chip
                        ? 'h-8 border bg-card px-2.5 text-xs font-medium'
                        : 'size-6 justify-center',
                    className,
                )}
            >
                <Info className="size-4" />
                {chip && label}
            </button>
            {place &&
                createPortal(
                    <div
                        ref={panel}
                        id={id}
                        role="dialog"
                        style={{
                            top: place.top,
                            left: place.left,
                            width: place.width,
                            pointerEvents: 'auto',
                        }}
                        className="fixed z-[100] rounded-lg border bg-popover p-3 text-sm leading-relaxed font-normal text-popover-foreground shadow-md"
                    >
                        {children}
                    </div>,
                    document.body,
                )}
        </>
    );
}

/** A page-level explanation as a compact "Info" chip, for the paragraphs that used to sit above a table or form. */
export function InfoNote({
    children,
    label = 'Cara kerja',
}: {
    children: ReactNode;
    label?: string;
}) {
    return (
        <div className="flex">
            <InfoTip chip label={label}>
                {children}
            </InfoTip>
        </div>
    );
}
