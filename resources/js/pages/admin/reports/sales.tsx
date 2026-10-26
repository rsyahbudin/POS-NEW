import { Head, Link, router, usePage } from '@inertiajs/react';
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
    cashier: 'Per pencatat order',
    receiver: 'Per penerima pembayaran',
    type: 'Per tipe order',
};

type Row = {
    label: string;
    display: string;
    orders: number;
    total: number;
    subtotal: number;
    discount: number;
    charges: number;
    rounding: number;
    refunds: number;
    average: number;
};

type Props = {
    filters: { from: string; to: string; dimension: string };
    summary: {
        orders: number;
        subtotal: number;
        discounts: number;
        charges: number;
        rounding: number;
        gross: number;
        refunds: number;
        net: number;
        average: number;
    };
    rows: Row[];
    detailed: boolean;
    dimensions: string[];
};

export default function SalesReport({
    filters,
    summary,
    rows,
    detailed,
    dimensions,
}: Props) {
    const sum = (k: keyof Row) =>
        rows.reduce((a, r) => a + (Number(r[k]) || 0), 0);
    const totalOrders = sum('orders');
    const user = usePage().props.auth.user as unknown as {
        permissions?: string[];
        roles?: string[];
    } | null;
    const canReceipts =
        !!user?.roles?.includes('owner') ||
        !!user?.permissions?.includes('export-receipts');
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
                            {canReceipts && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-11"
                                >
                                    <Link href="/admin/receipt-exports">
                                        Struk bulanan (PDF)
                                    </Link>
                                </Button>
                            )}
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
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
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
                        label="Penjualan bersih"
                        value={rupiah(summary.net)}
                        hint="Setelah diskon dan refund"
                    />
                    <Stat
                        label="Rata-rata per order"
                        value={rupiah(summary.average)}
                    />
                    <Stat
                        label="Diskon diberikan"
                        value={rupiah(summary.discounts)}
                    />
                </div>
                <section className="rounded-xl border bg-card p-4">
                    <h2 className="mb-2 text-sm font-semibold">
                        Dari subtotal ke penjualan bersih
                    </h2>
                    <dl className="grid gap-1 text-sm sm:max-w-md">
                        {(
                            [
                                ['Subtotal menu', summary.subtotal, false],
                                ['Diskon', -summary.discounts, false],
                                [
                                    'Pajak & biaya layanan',
                                    summary.charges,
                                    false,
                                ],
                                ['Pembulatan', summary.rounding, false],
                                ['Total ditagih', summary.gross, true],
                                ['Refund', -summary.refunds, false],
                                ['Penjualan bersih', summary.net, true],
                            ] as [string, number, boolean][]
                        ).map(([k, v, strong]) => (
                            <div
                                key={k}
                                className={`flex justify-between gap-3 ${strong ? 'border-t pt-1 font-semibold' : ''}`}
                            >
                                <dt
                                    className={
                                        strong ? '' : 'text-muted-foreground'
                                    }
                                >
                                    {k}
                                </dt>
                                <dd className="tabular-nums">
                                    {v < 0 ? '−' : ''}
                                    {rupiah(Math.abs(v))}
                                </dd>
                            </div>
                        ))}
                    </dl>
                </section>
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
                                label: r.display,
                                value: r.total,
                            }))}
                        />
                    )}
                </ChartCard>
                {detailed ? (
                    <ResponsiveTable
                        headers={[
                            LABELS[filters.dimension],
                            'Order',
                            'Subtotal menu',
                            'Diskon',
                            'Pajak & biaya',
                            'Pembulatan',
                            'Refund',
                            'Penjualan bersih',
                            'Rata-rata/order',
                        ]}
                        numeric={[1, 2, 3, 4, 5, 6, 7, 8]}
                        rows={rows.map((r) => [
                            r.display,
                            r.orders,
                            rupiah(r.subtotal),
                            rupiah(r.discount),
                            rupiah(r.charges),
                            rupiah(r.rounding),
                            rupiah(r.refunds),
                            rupiah(r.total),
                            rupiah(r.average),
                        ])}
                        footer={[
                            'Total',
                            totalOrders,
                            rupiah(sum('subtotal')),
                            rupiah(sum('discount')),
                            rupiah(sum('charges')),
                            rupiah(sum('rounding')),
                            rupiah(sum('refunds')),
                            rupiah(sum('total')),
                            rupiah(
                                totalOrders > 0
                                    ? Math.floor(sum('total') / totalOrders)
                                    : 0,
                            ),
                        ]}
                    />
                ) : (
                    <ResponsiveTable
                        headers={[
                            LABELS[filters.dimension],
                            filters.dimension === 'product' ||
                            filters.dimension === 'category'
                                ? 'Qty'
                                : 'Transaksi',
                            'Total',
                        ]}
                        numeric={[1, 2]}
                        rows={rows.map((r) => [
                            r.display,
                            r.orders,
                            rupiah(r.total),
                        ])}
                        footer={['Total', totalOrders, rupiah(sum('total'))]}
                    />
                )}
            </div>
        </>
    );
}
