import { Head, router } from '@inertiajs/react';
import {
    BarList,
    ChartCard,
    ColumnChart,
    LineChart,
} from '@/components/pos/charts';
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
    day: 'Per hari',
    hour: 'Per jam',
    product: 'Per produk',
    category: 'Per kategori',
    method: 'Per metode bayar',
    cashier: 'Per kasir',
    type: 'Per tipe order',
};

type Props = {
    filters: { from: string; to: string; dimension: string };
    summary: {
        orders: number;
        gross: number;
        refunds: number;
        net: number;
        discounts: number;
    };
    rows: { label: string; orders: number; total: number }[];
    dimensions: string[];
};

export default function SalesReport({
    filters,
    summary,
    rows,
    dimensions,
}: Props) {
    const qs = (extra: Record<string, string> = {}) =>
        new URLSearchParams({ ...filters, ...extra }).toString();

    return (
        <>
            <Head title="Laporan Penjualan" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Laporan penjualan"
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/export?${qs({ format: 'xlsx' })}`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/export?${qs({ format: 'pdf' })}`}
                                >
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const d = new FormData(e.currentTarget);
                        router.get(
                            '/admin/reports/sales',
                            Object.fromEntries(d.entries()),
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
                    <Stat label="Order" value={summary.orders} />
                    <Stat
                        label="Penjualan kotor"
                        value={rupiah(summary.gross)}
                    />
                    <Stat label="Refund" value={rupiah(summary.refunds)} />
                    <Stat
                        label="Penjualan bersih"
                        value={rupiah(summary.net)}
                        hint={`Diskon ${rupiah(summary.discounts)}`}
                    />
                </div>
                <ChartCard
                    title={`Penjualan ${LABELS[filters.dimension].toLowerCase()}`}
                >
                    {filters.dimension === 'day' ? (
                        <LineChart
                            title="Penjualan per hari"
                            data={rows.map((r) => ({
                                label: r.label,
                                value: r.total,
                            }))}
                        />
                    ) : filters.dimension === 'hour' ? (
                        <ColumnChart
                            title="Penjualan per jam"
                            data={rows.map((r) => ({
                                label: r.label.slice(0, 2),
                                value: r.total,
                            }))}
                        />
                    ) : (
                        <BarList
                            title={`Penjualan ${LABELS[filters.dimension].toLowerCase()}`}
                            data={rows.map((r) => ({
                                label: r.label,
                                value: r.total,
                            }))}
                        />
                    )}
                </ChartCard>
                <ResponsiveTable
                    headers={[
                        LABELS[filters.dimension],
                        filters.dimension === 'product' ||
                        filters.dimension === 'category'
                            ? 'Qty'
                            : 'Jumlah',
                        'Total',
                    ]}
                    numeric={[1, 2]}
                    rows={rows.map((r) => [r.label, r.orders, rupiah(r.total)])}
                />
            </div>
        </>
    );
}
