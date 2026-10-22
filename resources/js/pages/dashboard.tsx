import { Head, Link } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import {
    BarList,
    ChartCard,
    ColumnChart,
    LineChart,
} from '@/components/pos/charts';
import { PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, rupiah } from '@/lib/format';

type Row = { label: string; orders: number; total: number };

type Props = {
    date: string;
    metrics: {
        revenue: number;
        orders: number;
        average: number;
        last_week_revenue: number;
        change_percent: number | null;
    };
    hourly: Row[];
    daily: Row[];
    topProducts: Row[];
};

export default function Dashboard({
    date,
    metrics,
    hourly,
    daily,
    topProducts,
}: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Dashboard"
                    subtitle={`Hari bisnis ${dateId(date)}`}
                    actions={
                        <Button asChild className="h-11">
                            <Link href="/admin/reports/sales">
                                Laporan lengkap
                            </Link>
                        </Button>
                    }
                />

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label="Omzet hari ini"
                        value={rupiah(metrics.revenue)}
                        hint={
                            metrics.change_percent === null ? (
                                'Belum ada pembanding'
                            ) : (
                                <span
                                    className={`inline-flex items-center gap-1 font-medium ${metrics.change_percent >= 0 ? 'text-emerald-700' : 'text-rose-700'}`}
                                >
                                    {metrics.change_percent >= 0 ? (
                                        <TrendingUp className="size-3.5" />
                                    ) : (
                                        <TrendingDown className="size-3.5" />
                                    )}
                                    {Math.abs(metrics.change_percent)}% vs
                                    minggu lalu
                                </span>
                            )
                        }
                    />
                    <Stat label="Transaksi" value={metrics.orders} />
                    <Stat
                        label="Rata-rata transaksi"
                        value={rupiah(metrics.average)}
                    />
                    <Stat
                        label="Hari sama minggu lalu"
                        value={rupiah(metrics.last_week_revenue)}
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title="Omzet 14 hari terakhir">
                        <LineChart
                            title="Omzet harian 14 hari terakhir"
                            data={daily.map((d) => ({
                                label: d.label,
                                value: d.total,
                            }))}
                        />
                    </ChartCard>
                    <ChartCard title="Penjualan per jam (hari ini)">
                        <ColumnChart
                            title="Penjualan per jam hari ini"
                            data={hourly.map((h) => ({
                                label: h.label.slice(0, 2),
                                value: h.total,
                            }))}
                        />
                    </ChartCard>
                </div>

                <div className="grid gap-4 lg:grid-cols-[2fr_3fr]">
                    <ChartCard title="Produk terlaris hari ini">
                        <BarList
                            title="Produk terlaris hari ini"
                            data={topProducts.map((p) => ({
                                label: p.label,
                                value: p.total,
                            }))}
                        />
                    </ChartCard>
                    <ResponsiveTable
                        headers={['Produk', 'Qty', 'Nilai']}
                        numeric={[1, 2]}
                        empty="Belum ada penjualan hari ini."
                        rows={topProducts.map((p) => [
                            p.label,
                            p.orders,
                            rupiah(p.total),
                        ])}
                    />
                </div>
            </div>
        </>
    );
}
