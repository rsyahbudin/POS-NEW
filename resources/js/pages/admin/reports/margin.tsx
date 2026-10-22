import { Head, Link, router } from '@inertiajs/react';
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
import { rupiah } from '@/lib/format';

const LABELS: Record<string, string> = {
    product: 'Per produk',
    category: 'Per kategori',
    day: 'Per hari',
};
const HEAD: Record<string, string> = {
    product: 'Produk',
    category: 'Kategori',
    day: 'Hari',
};

type Row = {
    label: string;
    qty: number;
    revenue: number;
    cogs: number;
    profit: number;
    margin_pct: number;
    uncosted_qty: number;
};
type Stock = {
    name: string;
    unit: string;
    stock: number;
    avg_cost: number;
    value: number;
    min_stock: number;
    low: boolean;
};
type Props = {
    enabled: boolean;
    filters: { from: string; to: string; dimension: string };
    summary: {
        revenue: number;
        cogs: number;
        profit: number;
        margin_pct: number;
        uncosted_qty: number;
    };
    rows: Row[];
    stock: Stock[];
    dimensions: string[];
};

export default function MarginReport({
    enabled,
    filters,
    summary,
    rows,
    stock,
    dimensions,
}: Props) {
    const qs = (format: string) =>
        new URLSearchParams({ ...filters, format }).toString();
    const data = rows.map((r) => ({ label: r.label, value: r.profit }));

    return (
        <>
            <Head title="Margin & HPP" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Margin & HPP"
                    subtitle="Penjualan dikurangi biaya bahan (HPP) yang tercatat saat menu terjual."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/margin/export?${qs('xlsx')}`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/margin/export?${qs('pdf')}`}
                                >
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                {!enabled && (
                    <p
                        role="status"
                        className="rounded-2xl border bg-muted p-4 text-sm"
                    >
                        Stok & HPP belum aktif, jadi HPP penjualan belum
                        tercatat. Aktifkan di{' '}
                        <Link
                            href="/admin/settings"
                            className="font-semibold underline"
                        >
                            Pengaturan › Stok & HPP
                        </Link>
                        .
                    </p>
                )}
                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/reports/margin',
                            Object.fromEntries(
                                new FormData(e.currentTarget).entries(),
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
                </form>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label="Penjualan"
                        value={rupiah(summary.revenue)}
                        hint="Sebelum diskon order"
                    />
                    <Stat
                        label="HPP (biaya bahan)"
                        value={rupiah(summary.cogs)}
                    />
                    <Stat label="Laba kotor" value={rupiah(summary.profit)} />
                    <Stat
                        label="Margin"
                        value={`${summary.margin_pct.toLocaleString('id-ID')}%`}
                        hint={
                            summary.uncosted_qty > 0
                                ? `${summary.uncosted_qty} porsi tanpa HPP (belum ada resep)`
                                : undefined
                        }
                    />
                </div>

                <ChartCard
                    title={`Laba kotor ${LABELS[filters.dimension].toLowerCase()}`}
                >
                    {filters.dimension === 'day' ? (
                        <LineChart
                            title="Laba kotor per hari"
                            data={data}
                            fmt={rupiah}
                        />
                    ) : (
                        <BarList
                            title="Laba kotor"
                            data={data.slice(0, 12)}
                            fmt={rupiah}
                        />
                    )}
                </ChartCard>

                <ResponsiveTable
                    headers={[
                        HEAD[filters.dimension],
                        'Terjual',
                        'Penjualan',
                        'HPP',
                        'Laba kotor',
                        'Margin',
                    ]}
                    numeric={[1, 2, 3, 4, 5]}
                    rows={rows.map((r) => [
                        r.uncosted_qty > 0 ? `${r.label} *` : r.label,
                        r.qty,
                        rupiah(r.revenue),
                        rupiah(r.cogs),
                        rupiah(r.profit),
                        `${r.margin_pct.toLocaleString('id-ID')}%`,
                    ])}
                />
                {rows.some((r) => r.uncosted_qty > 0) && (
                    <p className="text-xs text-muted-foreground">
                        * Ada porsi yang terjual tanpa resep atau sebelum stok
                        diaktifkan, jadi HPP-nya belum terhitung penuh.
                    </p>
                )}

                <h2 className="mt-2 font-semibold">Stok bahan saat ini</h2>
                <ResponsiveTable
                    headers={[
                        'Bahan',
                        'Stok',
                        'Biaya rata-rata',
                        'Nilai stok',
                        'Batas minimum',
                    ]}
                    numeric={[1, 2, 3, 4]}
                    rows={stock.map((s) => [
                        s.low ? `${s.name} (menipis)` : s.name,
                        `${s.stock.toLocaleString('id-ID')} ${s.unit}`,
                        `${rupiah(s.avg_cost)}/${s.unit}`,
                        rupiah(s.value),
                        `${s.min_stock.toLocaleString('id-ID')} ${s.unit}`,
                    ])}
                />
            </div>
        </>
    );
}
