import { Head, Link } from '@inertiajs/react';
import { PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, rupiah } from '@/lib/format';

type Props = {
    date: string;
    metrics: {
        revenue: number;
        orders: number;
        average: number;
        last_week_revenue: number;
        change_percent: number | null;
    };
    hourly: { label: string; orders: number; total: number }[];
    topProducts: { label: string; orders: number; total: number }[];
};

export default function Dashboard({
    date,
    metrics,
    hourly,
    topProducts,
}: Props) {
    const max = Math.max(1, ...hourly.map((h) => h.total));

    return (
        <>
            <Head title="Dashboard" />
            <div className="grid gap-4 p-4">
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
                            metrics.change_percent === null
                                ? 'Belum ada pembanding'
                                : `${metrics.change_percent >= 0 ? '▲' : '▼'} ${Math.abs(metrics.change_percent)}% vs minggu lalu`
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

                <section
                    className="rounded-xl border bg-card p-4"
                    aria-label="Penjualan per jam"
                >
                    <h2 className="mb-3 font-semibold">Penjualan per jam</h2>
                    {hourly.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada transaksi.
                        </p>
                    ) : (
                        <div
                            className="flex h-40 items-end gap-1"
                            role="img"
                            aria-label="Grafik batang penjualan per jam"
                        >
                            {hourly.map((h) => (
                                <div
                                    key={h.label}
                                    className="flex flex-1 flex-col items-center justify-end gap-1"
                                >
                                    <div
                                        className="w-full rounded-t bg-primary"
                                        style={{
                                            height: `${Math.max(4, (h.total / max) * 100)}%`,
                                        }}
                                        title={`${h.label}: ${rupiah(h.total)}`}
                                    />
                                    <span className="text-[10px] text-muted-foreground">
                                        {h.label.slice(0, 2)}
                                    </span>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                <section>
                    <h2 className="mb-2 font-semibold">
                        Produk terlaris hari ini
                    </h2>
                    <ResponsiveTable
                        headers={['Produk', 'Qty', 'Nilai']}
                        numeric={[1, 2]}
                        rows={topProducts.map((p) => [
                            p.label,
                            p.orders,
                            rupiah(p.total),
                        ])}
                    />
                </section>
            </div>
        </>
    );
}
