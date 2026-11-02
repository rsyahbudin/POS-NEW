import { Head, Link, router } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import {
    BarList,
    ChartCard,
    ColumnChart,
    LineChart,
} from '@/components/pos/charts';
import { Field, PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah } from '@/lib/format';

type Row = {
    label: string;
    display: string;
    orders: number;
    total: number;
    net_sales: number;
};
type Sum = {
    orders: number;
    gross_sales: number;
    discounts: number;
    tax: number;
    service: number;
    net_sales: number;
    net: number;
    items: number;
    average: number;
    refunds: number;
};
type Props = {
    period: string;
    filters: { from: string; to: string };
    overview: {
        now: Sum;
        previous: Sum;
        growth: Record<string, number | null>;
        previous_from: string;
        previous_to: string;
    };
    hourly: Row[];
    daily: Row[];
    topProducts: Row[];
    methods: Row[];
    types: Row[];
};

const PERIODS: [string, string][] = [
    ['today', 'Hari ini'],
    ['yesterday', 'Kemarin'],
    ['week', '7 hari'],
    ['month', 'Bulan ini'],
    ['last_month', 'Bulan lalu'],
];

/** Growth against the period right before it: green up, red down, and plain words when there is nothing to compare. */
function Growth({
    value,
    vs,
}: {
    value: number | null | undefined;
    vs: string;
}) {
    if (value === null || value === undefined) {
        return <span>Belum ada pembanding</span>;
    }

    return (
        <span
            className={`inline-flex items-center gap-1 font-medium ${value >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'}`}
        >
            {value >= 0 ? (
                <TrendingUp className="size-3.5" />
            ) : (
                <TrendingDown className="size-3.5" />
            )}
            {value >= 0 ? '+' : '−'}
            {Math.abs(value)}% {vs}
        </span>
    );
}

export default function Dashboard({
    period,
    filters,
    overview,
    hourly,
    daily,
    topProducts,
    methods,
    types,
}: Props) {
    const { now, previous, growth } = overview;
    const single = filters.from === filters.to;
    const vs = `vs ${dateId(overview.previous_from)}${single ? '' : ` – ${dateId(overview.previous_to)}`}`;
    const go = (q: Record<string, string>) =>
        router.get('/dashboard', q, { preserveState: true });

    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto grid w-full max-w-[1400px] grid-cols-1 gap-5 p-4 md:p-6">
                <PageHeader
                    title="Dashboard"
                    subtitle={
                        single
                            ? `Hari bisnis ${dateId(filters.from)}`
                            : `${dateId(filters.from)} – ${dateId(filters.to)}`
                    }
                    actions={
                        <Button asChild className="h-11">
                            <Link
                                href={`/admin/reports/sales?from=${filters.from}&to=${filters.to}`}
                            >
                                Sales Recapitulation
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-end gap-2">
                    {PERIODS.map(([key, label]) => (
                        <Button
                            key={key}
                            variant={period === key ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => go({ period: key })}
                        >
                            {label}
                        </Button>
                    ))}
                    <form
                        className="flex flex-wrap items-end gap-2"
                        onSubmit={(e) => {
                            e.preventDefault();
                            const d = new FormData(e.currentTarget);
                            go({
                                period: 'custom',
                                from: d.get('from') as string,
                                to: d.get('to') as string,
                            });
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
                        <Button
                            type="submit"
                            variant={
                                period === 'custom' ? 'default' : 'outline'
                            }
                            className="h-11"
                        >
                            Tampilkan
                        </Button>
                    </form>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label="Net Sales"
                        value={rupiah(now.net_sales)}
                        hint={<Growth value={growth.net_sales} vs={vs} />}
                    />
                    <Stat
                        label="Gross Sales"
                        value={rupiah(now.gross_sales)}
                        hint={<Growth value={growth.gross_sales} vs={vs} />}
                    />
                    <Stat
                        label="Order"
                        value={now.orders}
                        hint={<Growth value={growth.orders} vs={vs} />}
                    />
                    <Stat
                        label="Rata-rata per order"
                        value={rupiah(now.average)}
                        hint={<Growth value={growth.average} vs={vs} />}
                    />
                    <Stat
                        label="Item terjual"
                        value={now.items}
                        hint={<Growth value={growth.items} vs={vs} />}
                    />
                    <Stat
                        label="Diskon"
                        value={rupiah(now.discounts)}
                        hint={
                            now.gross_sales > 0
                                ? `${Math.round((now.discounts / now.gross_sales) * 1000) / 10}% dari Gross Sales`
                                : undefined
                        }
                    />
                    <Stat
                        label="Pajak & service"
                        value={rupiah(now.tax + now.service)}
                        hint={`Pajak ${rupiah(now.tax)} · Service ${rupiah(now.service)}`}
                    />
                    <Stat
                        label="Total diterima"
                        value={rupiah(now.net)}
                        hint={
                            now.refunds ? (
                                `Setelah refund ${rupiah(now.refunds)}`
                            ) : (
                                <Growth value={growth.net} vs={vs} />
                            )
                        }
                    />
                </div>
                <p className="text-xs text-muted-foreground">
                    Net Sales = penjualan menu setelah diskon, tanpa pajak dan
                    service charge. Total diterima = yang dibayar tamu (Net
                    Sales + pajak + service + pembulatan, setelah refund).
                    Pembanding: periode sama panjang sebelumnya (
                    {previous.orders} order, Net Sales{' '}
                    {rupiah(previous.net_sales)}).
                </p>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard
                        title={
                            single
                                ? 'Net Sales 14 hari terakhir'
                                : 'Net Sales per hari'
                        }
                    >
                        <LineChart
                            title="Net Sales per hari"
                            data={daily.map((d) => ({
                                label: d.label,
                                value: d.net_sales,
                            }))}
                        />
                    </ChartCard>
                    {single ? (
                        <ChartCard title="Penjualan per jam">
                            <ColumnChart
                                title="Penjualan per jam"
                                data={hourly.map((h) => ({
                                    label: h.label.slice(0, 2),
                                    value: h.net_sales,
                                }))}
                            />
                        </ChartCard>
                    ) : (
                        <ChartCard title="Per tipe order">
                            <BarList
                                title="Net Sales per tipe order"
                                data={types.map((t) => ({
                                    label: t.display,
                                    value: t.net_sales,
                                }))}
                            />
                        </ChartCard>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Metode pembayaran">
                        <BarList
                            title="Pembayaran per metode"
                            data={methods.map((m) => ({
                                label: m.label,
                                value: m.total,
                            }))}
                        />
                    </ChartCard>
                    <ChartCard title="Produk terlaris">
                        <BarList
                            title="Produk terlaris"
                            data={topProducts.map((p) => ({
                                label: p.label,
                                value: p.total,
                            }))}
                        />
                    </ChartCard>
                </div>

                <ResponsiveTable
                    headers={['Produk', 'Qty', 'Nilai']}
                    numeric={[1, 2]}
                    empty="Belum ada penjualan pada periode ini."
                    rows={topProducts.map((p) => [
                        p.label,
                        p.orders,
                        rupiah(p.total),
                    ])}
                />
            </div>
        </>
    );
}
