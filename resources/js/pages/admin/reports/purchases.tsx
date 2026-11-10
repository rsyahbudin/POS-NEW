import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';
import { BarList, ChartCard, LineChart } from '@/components/pos/charts';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

const LABELS: Record<string, string> = {
    ingredient: 'Per bahan',
    supplier: 'Per pemasok',
    day: 'Per hari',
    person: 'Per pencatat',
};
const HEAD: Record<string, string> = {
    ingredient: 'Bahan',
    supplier: 'Pemasok',
    day: 'Hari',
    person: 'Pencatat',
};
const STATUS: Record<string, string> = {
    approved: 'Disetujui (masuk stok)',
    pending: 'Menunggu persetujuan',
    rejected: 'Ditolak',
    all: 'Semua status',
};
const BADGE: Record<string, { label: string; cls: string }> = {
    approved: {
        label: 'Masuk stok',
        cls: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200',
    },
    pending: {
        label: 'Menunggu',
        cls: 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
    },
    rejected: { label: 'Ditolak', cls: 'bg-muted text-muted-foreground' },
};

type Row = {
    label: string;
    notes: number;
    total: number;
    share_pct: number;
    qty?: number;
    unit?: string;
    qty_label?: string;
    average?: number;
    low?: number;
    high?: number;
};
type Entry = {
    purchase_id: number;
    date: string;
    status: string;
    note: string | null;
    supplier: string;
    user: string | null;
    ingredient: string;
    qty_label: string;
    unit: string;
    unit_cost: number;
    total: number;
};
type Props = {
    filters: {
        from: string;
        to: string;
        dimension: string;
        status: string;
        supplier_id: number | null;
    };
    summary: {
        total: number;
        notes: number;
        ingredients: number;
        average: number;
        top_ingredient: string | null;
        top_supplier: string | null;
    };
    rows: Row[];
    entries: Entry[];
    dimensions: string[];
    statuses: string[];
    suppliers: { id: number; name: string }[];
};

const price = (n: number) =>
    `Rp${n.toLocaleString('id-ID', { maximumFractionDigits: 2 })}`;

/** What was bought, from whom, how much and at what price: totals by bahan, pemasok, hari or pencatat, and every note with its lines. */
export default function PurchaseReport({
    filters,
    summary,
    rows,
    entries,
    dimensions,
    statuses,
    suppliers,
}: Props) {
    const qs = (format: string) =>
        new URLSearchParams(
            Object.fromEntries(
                Object.entries({ ...filters, format })
                    .filter(([, v]) => v !== null && v !== '')
                    .map(([k, v]) => [k, String(v)]),
            ),
        ).toString();
    const ingredient = filters.dimension === 'ingredient';
    const data = rows.map((r) => ({
        label: filters.dimension === 'day' ? dateId(r.label) : r.label,
        value: r.total,
    }));

    // lines grouped back into the notes they came from
    const notes = useMemo(() => {
        const map = new Map<number, { head: Entry; lines: Entry[] }>();

        for (const e of entries) {
            const n = map.get(e.purchase_id);

            if (n) {
                n.lines.push(e);
            } else {
                map.set(e.purchase_id, { head: e, lines: [e] });
            }
        }

        return [...map.values()];
    }, [entries]);

    return (
        <>
            <Head title="Laporan Belanja" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Laporan belanja"
                    subtitle="Apa saja yang dibeli, dari siapa, berapa jumlah dan harganya. Hanya belanja yang disetujui yang sudah menambah stok."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/purchases/export?${qs('xlsx')}`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/purchases/export?${qs('pdf')}`}
                                >
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />

                <form
                    className="grid grid-cols-2 items-end gap-3 rounded-xl border bg-card p-3 sm:flex sm:flex-wrap [&>button]:col-span-2 sm:[&>button]:col-span-1"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/reports/purchases',
                            Object.fromEntries(
                                [
                                    ...new FormData(e.currentTarget).entries(),
                                ].filter(([, v]) => v !== ''),
                            ),
                            { preserveState: true },
                        );
                    }}
                >
                    <Field label="Dari">
                        <Input
                            className="h-11"
                            type="date"
                            name="from"
                            defaultValue={filters.from}
                        />
                    </Field>
                    <Field label="Sampai">
                        <Input
                            className="h-11"
                            type="date"
                            name="to"
                            defaultValue={filters.to}
                        />
                    </Field>
                    <Field label="Pemasok">
                        <Select
                            name="supplier_id"
                            defaultValue={filters.supplier_id ?? ''}
                        >
                            <option value="">Semua pemasok</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Status">
                        <Select name="status" defaultValue={filters.status}>
                            {statuses.map((s) => (
                                <option key={s} value={s}>
                                    {STATUS[s]}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Kelompokkan">
                        <Select
                            name="dimension"
                            defaultValue={filters.dimension}
                        >
                            {dimensions.map((d) => (
                                <option key={d} value={d}>
                                    {LABELS[d]}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                    <Button asChild variant="ghost" className="h-11">
                        <Link href="/stock/purchase">Catat belanja</Link>
                    </Button>
                </form>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3 xl:grid-cols-5">
                    <Stat label="Total belanja" value={rupiah(summary.total)} />
                    <Stat label="Jumlah nota" value={summary.notes} />
                    <Stat
                        label="Jenis bahan dibeli"
                        value={summary.ingredients}
                    />
                    <Stat
                        label="Rata-rata per nota"
                        value={rupiah(summary.average)}
                    />
                    <Stat
                        label="Terbesar"
                        value={summary.top_ingredient ?? '-'}
                        hint={
                            summary.top_supplier
                                ? `Pemasok: ${summary.top_supplier}`
                                : undefined
                        }
                    />
                </div>

                <ChartCard
                    title={`Belanja ${LABELS[filters.dimension].toLowerCase()}`}
                >
                    {filters.dimension === 'day' ? (
                        <LineChart
                            title="Belanja per hari"
                            data={data}
                            fmt={rupiah}
                        />
                    ) : (
                        <BarList
                            title="Belanja"
                            data={data.slice(0, 12)}
                            fmt={rupiah}
                        />
                    )}
                </ChartCard>

                <ResponsiveTable
                    headers={
                        ingredient
                            ? [
                                  'Bahan',
                                  'Total dibeli',
                                  'Kali beli',
                                  'Harga rata-rata',
                                  'Termurah – termahal',
                                  'Total',
                                  '% dari total',
                              ]
                            : [
                                  HEAD[filters.dimension],
                                  'Jumlah nota',
                                  'Total',
                                  '% dari total',
                              ]
                    }
                    numeric={ingredient ? [1, 2, 3, 4, 5, 6] : [1, 2, 3]}
                    empty="Tidak ada belanja pada periode ini."
                    footer={
                        ingredient
                            ? [
                                  'Total',
                                  '',
                                  '',
                                  '',
                                  '',
                                  rupiah(rows.reduce((a, r) => a + r.total, 0)),
                                  '',
                              ]
                            : [
                                  'Total',
                                  rows.reduce((a, r) => a + r.notes, 0),
                                  rupiah(rows.reduce((a, r) => a + r.total, 0)),
                                  '',
                              ]
                    }
                    rows={rows.map((r) =>
                        ingredient
                            ? [
                                  r.label,
                                  r.qty_label ?? '',
                                  r.notes,
                                  `${price(r.average ?? 0)}/${r.unit}`,
                                  r.low === r.high
                                      ? price(r.low ?? 0)
                                      : `${price(r.low ?? 0)} – ${price(r.high ?? 0)}`,
                                  rupiah(r.total),
                                  `${r.share_pct.toLocaleString('id-ID')}%`,
                              ]
                            : [
                                  filters.dimension === 'day'
                                      ? dateId(r.label)
                                      : r.label,
                                  r.notes,
                                  rupiah(r.total),
                                  `${r.share_pct.toLocaleString('id-ID')}%`,
                              ],
                    )}
                />

                <section className="grid gap-3">
                    <h2 className="font-semibold">Rincian belanja</h2>
                    {notes.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            Tidak ada belanja pada periode ini.
                        </p>
                    )}
                    {notes.map(({ head, lines }) => (
                        <article
                            key={head.purchase_id}
                            className="grid gap-2 rounded-xl border bg-card p-4"
                        >
                            <header className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="font-semibold">
                                        {dateId(head.date)} · {head.supplier}
                                    </p>
                                    <p className="text-xs text-muted-foreground">
                                        Dicatat oleh {head.user ?? '-'}
                                        {head.note ? ` · “${head.note}”` : ''}
                                    </p>
                                </div>
                                <span
                                    className={cn(
                                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        BADGE[head.status]?.cls,
                                    )}
                                >
                                    {BADGE[head.status]?.label ?? head.status}
                                </span>
                            </header>
                            <ul className="divide-y text-sm">
                                {lines.map((l, i) => (
                                    <li
                                        key={i}
                                        className="flex items-start justify-between gap-3 py-1.5"
                                    >
                                        <span className="min-w-0">
                                            <span className="font-medium">
                                                {l.ingredient}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {l.qty_label} ·{' '}
                                                {price(l.unit_cost)}/{l.unit}
                                            </span>
                                        </span>
                                        <span className="shrink-0 tabular-nums">
                                            {rupiah(l.total)}
                                        </span>
                                    </li>
                                ))}
                                <li className="flex justify-between gap-3 pt-2 font-semibold">
                                    <span>Total nota</span>
                                    <span className="tabular-nums">
                                        {rupiah(
                                            lines.reduce(
                                                (a, l) => a + l.total,
                                                0,
                                            ),
                                        )}
                                    </span>
                                </li>
                            </ul>
                        </article>
                    ))}
                    {entries.length >= 600 && (
                        <p className="text-xs text-muted-foreground">
                            Menampilkan 600 baris terbaru. Unduh Excel untuk
                            semuanya.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}
