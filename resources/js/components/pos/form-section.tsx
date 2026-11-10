import { ChevronDown } from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** A folded part of a long form: the title and what is inside, opened on demand (or already open when it holds values). */
export function FormSection({
    title,
    hint,
    defaultOpen = false,
    children,
}: {
    title: string;
    hint?: string;
    defaultOpen?: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <section className="rounded-xl border">
            <button
                type="button"
                aria-expanded={open}
                onClick={() => setOpen((o) => !o)}
                className="flex min-h-12 w-full items-center justify-between gap-3 px-3 text-left"
            >
                <span>
                    <span className="block text-sm font-semibold">{title}</span>
                    {hint && (
                        <span className="block text-xs text-muted-foreground">
                            {hint}
                        </span>
                    )}
                </span>
                <ChevronDown
                    className={cn(
                        'size-5 shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>
            {open && <div className="grid gap-4 border-t p-3">{children}</div>}
        </section>
    );
}
