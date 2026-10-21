import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronDown,
    Search,
} from 'lucide-react';
import { isValidElement, useMemo, useState } from 'react';
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

function textOf(node: ReactNode): string {
    if (node === null || node === undefined || typeof node === 'boolean') {
        return '';
    }

    if (typeof node === 'string' || typeof node === 'number') {
        return String(node);
    }

    if (Array.isArray(node)) {
        return node.map(textOf).join(' ');
    }

    if (isValidElement(node)) {
        return textOf((node.props as { children?: ReactNode }).children);
    }

    return '';
}

/** "Rp1.164.500", "5.000 g", "12%", "1,5" -> number (Indonesian formatting); null when there is none. */
function numberOf(text: string): number | null {
    const m = text.replace(/\s/g, '').match(/-?\d[\d.,]*/);

    if (!m) {
        return null;
    }

    let t = m[0];

    if (t.includes(',')) {
        t = t.replace(/\./g, '').replace(',', '.');
    } else if (/^-?\d{1,3}(\.\d{3})+$/.test(t)) {
        t = t.replace(/\./g, '');
    }

    const n = Number(t);

    return Number.isFinite(n) ? n : null;
}

type Sort = { col: number; dir: 'asc' | 'desc' } | null;

/**
 * Data table for lists and reports: search box, drop-down filters for columns with few distinct values, and
 * sorting by clicking a column header. Collapses to cards on phones (first column is the card title), where a
 * "Urutkan" control replaces the headers. Everything works on the rows already on the page.
 *
 * `toolbar` hides search and filters (when the page has its own); `sortable={false}` keeps the given order
 * (statements with subtotals). The "Aksi" column is never searched, filtered or sorted.
 */
export function ResponsiveTable({
    headers,
    rows,
    empty = 'Belum ada data.',
    numeric = [],
    toolbar,
    sortable = true,
}: {
    headers: string[];
    rows: ReactNode[][];
    empty?: string;
    numeric?: number[];
    toolbar?: boolean;
    sortable?: boolean;
}) {
    const [query, setQuery] = useState('');
    const [filters, setFilters] = useState<Record<number, string>>({});
    const [sort, setSort] = useState<Sort>(null);

    const isAction = (i: number) => headers[i] === 'Aksi' || headers[i] === '';
    const texts = useMemo(() => rows.map((r) => r.map(textOf)), [rows]);
    const hasText = (i: number) =>
        texts.some((r) => (r[i] ?? '').trim() !== '');
    const canSort = (i: number) => sortable && !isAction(i) && hasText(i);

    // columns with a handful of distinct short values become drop-down filters
    const filterable = useMemo(() => {
        if (rows.length < 6) {
            return [] as { col: number; values: string[] }[];
        }

        const out: { col: number; values: string[] }[] = [];

        headers.forEach((_, i) => {
            if (isAction(i) || numeric.includes(i)) {
                return;
            }

            const values = [
                ...new Set(
                    texts.map((r) => (r[i] ?? '').trim()).filter(Boolean),
                ),
            ];

            if (
                values.length >= 2 &&
                values.length <= 10 &&
                values.every((v) => v.length <= 28) &&
                values.length < rows.length
            ) {
                out.push({
                    col: i,
                    values: values.sort((a, b) => a.localeCompare(b, 'id')),
                });
            }
        });

        return out.slice(0, 3);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [texts, headers, numeric, rows.length]);

    const order = useMemo(() => {
        const q = query.trim().toLowerCase();
        let idx = rows
            .map((_, i) => i)
            .filter((i) => {
                const t = texts[i];

                if (
                    q &&
                    !t.some(
                        (cell, c) =>
                            !isAction(c) && cell.toLowerCase().includes(q),
                    )
                ) {
                    return false;
                }

                return Object.entries(filters).every(
                    ([c, v]) => !v || (t[Number(c)] ?? '').trim() === v,
                );
            });

        if (sort) {
            const col = sort.col;
            const nums = idx.map((i) => numberOf(texts[i][col] ?? ''));
            const useNumbers =
                numeric.includes(col) ||
                (idx.length > 0 && nums.every((n) => n !== null));
            const dir = sort.dir === 'asc' ? 1 : -1;
            idx = [...idx].sort((a, b) => {
                if (useNumbers) {
                    const na = numberOf(texts[a][col] ?? '');
                    const nb = numberOf(texts[b][col] ?? '');

                    if (na === null || nb === null) {
                        return na === nb ? 0 : na === null ? 1 : -1;
                    }

                    return (na - nb) * dir;
                }

                return (
                    (texts[a][col] ?? '').localeCompare(
                        texts[b][col] ?? '',
                        'id',
                        { numeric: true, sensitivity: 'base' },
                    ) * dir
                );
            });
        }

        return idx;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [rows, texts, query, filters, sort, numeric]);

    if (rows.length === 0) {
        return (
            <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                {empty}
            </p>
        );
    }

    const showToolbar = toolbar ?? rows.length > 5;
    const toggle = (col: number) =>
        setSort((s) =>
            s?.col !== col
                ? { col, dir: 'asc' }
                : s.dir === 'asc'
                  ? { col, dir: 'desc' }
                  : null,
        );
    const active = query !== '' || Object.values(filters).some(Boolean);
    const sortableCols = headers.map((_, i) => i).filter(canSort);

    return (
        <div className="grid gap-2">
            {(showToolbar || sortableCols.length > 0) && (
                <div className="flex flex-wrap items-center gap-2">
                    {showToolbar && (
                        <div className="relative w-full sm:w-64">
                            <Search
                                aria-hidden
                                className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            />
                            <input
                                type="search"
                                aria-label="Cari di tabel"
                                placeholder="Cari…"
                                value={query}
                                onChange={(e) => setQuery(e.target.value)}
                                className="h-10 w-full rounded-xl border border-input bg-card pr-3 pl-9 text-sm outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </div>
                    )}
                    {showToolbar &&
                        filterable.map((f) => (
                            <div key={f.col} className="w-full sm:w-44">
                                <Select
                                    aria-label={`Filter ${headers[f.col]}`}
                                    className="h-10"
                                    value={filters[f.col] ?? ''}
                                    onChange={(e) =>
                                        setFilters({
                                            ...filters,
                                            [f.col]: e.target.value,
                                        })
                                    }
                                >
                                    <option value="">
                                        Semua {headers[f.col].toLowerCase()}
                                    </option>
                                    {f.values.map((v) => (
                                        <option key={v} value={v}>
                                            {v}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        ))}
                    {sortableCols.length > 0 && (
                        <div className="flex w-full items-center gap-1.5 md:hidden">
                            <Select
                                aria-label="Urutkan"
                                className="h-10"
                                value={sort?.col ?? ''}
                                onChange={(e) =>
                                    setSort(
                                        e.target.value === ''
                                            ? null
                                            : {
                                                  col: Number(e.target.value),
                                                  dir: sort?.dir ?? 'asc',
                                              },
                                    )
                                }
                            >
                                <option value="">Urutkan…</option>
                                {sortableCols.map((i) => (
                                    <option key={i} value={i}>
                                        {headers[i]}
                                    </option>
                                ))}
                            </Select>
                            {sort && (
                                <button
                                    type="button"
                                    aria-label={
                                        sort.dir === 'asc'
                                            ? 'Urut naik'
                                            : 'Urut turun'
                                    }
                                    className="grid size-10 shrink-0 place-items-center rounded-xl border bg-card"
                                    onClick={() =>
                                        setSort({
                                            col: sort.col,
                                            dir:
                                                sort.dir === 'asc'
                                                    ? 'desc'
                                                    : 'asc',
                                        })
                                    }
                                >
                                    {sort.dir === 'asc' ? (
                                        <ArrowUp className="size-4" />
                                    ) : (
                                        <ArrowDown className="size-4" />
                                    )}
                                </button>
                            )}
                        </div>
                    )}
                    {active && (
                        <button
                            type="button"
                            className="text-sm text-primary underline"
                            onClick={() => {
                                setQuery('');
                                setFilters({});
                            }}
                        >
                            Hapus filter
                        </button>
                    )}
                    {(showToolbar || active) && (
                        <span
                            className="ml-auto text-xs text-muted-foreground"
                            aria-live="polite"
                        >
                            {order.length} dari {rows.length} baris
                        </span>
                    )}
                </div>
            )}

            {order.length === 0 ? (
                <p className="rounded-2xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    Tidak ada baris yang cocok.
                </p>
            ) : (
                <>
                    <div className="hidden overflow-x-auto rounded-2xl border bg-card md:block">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/60">
                                <tr>
                                    {headers.map((h, i) => {
                                        const right =
                                            numeric.includes(i) || h === 'Aksi';
                                        const current =
                                            sort?.col === i ? sort.dir : null;

                                        return (
                                            <th
                                                key={h + i}
                                                aria-sort={
                                                    current === 'asc'
                                                        ? 'ascending'
                                                        : current === 'desc'
                                                          ? 'descending'
                                                          : undefined
                                                }
                                                className={cn(
                                                    'px-3 py-2 text-left font-medium',
                                                    right && 'text-right',
                                                )}
                                            >
                                                {canSort(i) ? (
                                                    <button
                                                        type="button"
                                                        onClick={() =>
                                                            toggle(i)
                                                        }
                                                        className={cn(
                                                            'inline-flex items-center gap-1 rounded hover:text-primary focus-visible:ring-2 focus-visible:ring-ring',
                                                            right &&
                                                                'flex-row-reverse',
                                                        )}
                                                    >
                                                        {h}
                                                        {current === 'asc' ? (
                                                            <ArrowUp className="size-3.5 text-primary" />
                                                        ) : current ===
                                                          'desc' ? (
                                                            <ArrowDown className="size-3.5 text-primary" />
                                                        ) : (
                                                            <ArrowUpDown className="size-3.5 opacity-30" />
                                                        )}
                                                    </button>
                                                ) : (
                                                    h
                                                )}
                                            </th>
                                        );
                                    })}
                                </tr>
                            </thead>
                            <tbody>
                                {order.map((r) => (
                                    <tr key={r} className="border-t">
                                        {rows[r].map((cell, i) => (
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
                        {order.map((r) => (
                            <div
                                key={r}
                                className="rounded-2xl border bg-card p-3"
                            >
                                <p className="font-medium">{rows[r][0]}</p>
                                <dl className="mt-1 grid gap-0.5 text-sm">
                                    {rows[r].slice(1).map((cell, i) => (
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
            )}
        </div>
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
