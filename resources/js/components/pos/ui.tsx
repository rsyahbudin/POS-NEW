import { ChevronDown } from 'lucide-react';
import type { ComponentProps, ReactNode } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';

/** Native select styled like the inputs: reliable on tablets, 44px touch target, with our own chevron. */
export function Select({
    className,
    children,
    ...props
}: ComponentProps<'select'>) {
    return (
        <div className="relative w-full">
            <select
                {...props}
                className={cn(
                    'h-11 w-full appearance-none rounded-xl border border-input bg-card pr-9 pl-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:opacity-50',
                    className,
                )}
            >
                {children}
            </select>
            <ChevronDown
                aria-hidden
                className="pointer-events-none absolute top-1/2 right-3 size-4 -translate-y-1/2 text-muted-foreground"
            />
        </div>
    );
}

export function Field({
    label,
    children,
    hint,
    error,
}: {
    label: string;
    children: ReactNode;
    hint?: string;
    error?: string;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label>{label}</Label>
            {children}
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            {error && <p className="text-xs text-destructive">{error}</p>}
        </div>
    );
}

export function TextArea({ className, ...props }: ComponentProps<'textarea'>) {
    return (
        <textarea
            {...props}
            className={cn(
                'min-h-20 w-full rounded-xl border border-input bg-card px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                className,
            )}
        />
    );
}

/** Integer rupiah input. Shows grouped digits, keeps a plain number in state. */
export function MoneyInput({
    value,
    onChange,
    className,
    ...props
}: { value: number | ''; onChange: (v: number | '') => void } & Omit<
    ComponentProps<'input'>,
    'value' | 'onChange'
>) {
    return (
        <input
            {...props}
            inputMode="numeric"
            onFocus={(e) => e.target.select()}
            className={cn(
                'h-11 w-full rounded-xl border border-input bg-card px-3 text-right text-base tabular-nums outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                className,
            )}
            value={value === '' ? '' : value.toLocaleString('id-ID')}
            onChange={(e) => {
                const digits = e.target.value.replace(/\D/g, '');
                onChange(digits === '' ? '' : Number(digits));
            }}
        />
    );
}

export function PageHeader({
    title,
    actions,
    subtitle,
}: {
    title: string;
    subtitle?: string;
    actions?: ReactNode;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 className="text-2xl font-bold tracking-tight">{title}</h1>
                {subtitle && (
                    <p className="text-sm text-muted-foreground">{subtitle}</p>
                )}
            </div>
            <div className="flex flex-wrap items-center gap-2">{actions}</div>
        </div>
    );
}

export function Stat({
    label,
    value,
    hint,
}: {
    label: string;
    value: ReactNode;
    hint?: ReactNode;
}) {
    return (
        <div className="rounded-2xl border bg-card p-4">
            <p className="text-xs font-medium text-muted-foreground">{label}</p>
            <p className="mt-1.5 text-2xl font-bold tracking-tight tabular-nums">
                {value}
            </p>
            {hint && (
                <p className="mt-1 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

/**
 * Report table that collapses to cards on phones (PRD 6.1): first column is the card title.
 */
export function ResponsiveTable({
    headers,
    rows,
    empty = 'Belum ada data.',
    numeric = [],
}: {
    headers: string[];
    rows: ReactNode[][];
    empty?: string;
    numeric?: number[];
}) {
    if (rows.length === 0) {
        return (
            <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    return (
        <>
            <div className="hidden overflow-x-auto rounded-2xl border bg-card md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/60">
                        <tr>
                            {headers.map((h, i) => (
                                <th
                                    key={h}
                                    className={cn(
                                        'px-3 py-2 text-left font-medium',
                                        (numeric.includes(i) || h === 'Aksi') &&
                                            'text-right',
                                    )}
                                >
                                    {h}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row, r) => (
                            <tr key={r} className="border-t">
                                {row.map((cell, i) => (
                                    <td
                                        key={i}
                                        className={cn(
                                            'px-3 py-2',
                                            numeric.includes(i) &&
                                                'text-right tabular-nums',
                                        )}
                                    >
                                        {cell}
                                    </td>
                                ))}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <div className="grid gap-2 md:hidden">
                {rows.map((row, r) => (
                    <div key={r} className="rounded-2xl border bg-card p-3">
                        <p className="font-medium">{row[0]}</p>
                        <dl className="mt-1 grid gap-0.5 text-sm">
                            {row.slice(1).map((cell, i) => (
                                <div
                                    key={i}
                                    className="flex justify-between gap-3"
                                >
                                    <dt className="text-muted-foreground">
                                        {headers[i + 1]}
                                    </dt>
                                    <dd className="text-right tabular-nums">
                                        {cell}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </div>
                ))}
            </div>
        </>
    );
}

export function Tabs<T extends string>({
    value,
    onChange,
    tabs,
}: {
    value: T;
    onChange: (v: T) => void;
    tabs: { id: T; label: string }[];
}) {
    return (
        <div
            role="tablist"
            className="flex gap-1 overflow-x-auto rounded-xl bg-muted p-1"
        >
            {tabs.map((tb) => (
                <button
                    key={tb.id}
                    role="tab"
                    aria-selected={value === tb.id}
                    onClick={() => onChange(tb.id)}
                    className={cn(
                        'h-10 shrink-0 rounded-lg px-3.5 text-sm font-semibold transition-colors',
                        value === tb.id
                            ? 'bg-card text-primary shadow-sm'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tb.label}
                </button>
            ))}
        </div>
    );
}
