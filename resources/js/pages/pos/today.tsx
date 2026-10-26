import { Head, Link } from '@inertiajs/react';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { AccountMenu } from '@/components/pos/account-menu';
import { BarList, ChartCard, ColumnChart } from '@/components/pos/charts';
import { PageHeader, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import { dateId, rupiah } from '@/lib/format';

type Row = { label: string; orders: number; total: number };

export default function Today({
    date,
    metrics,
    hourly,
    topProducts,
    back,
}: {
    date: string;
    metrics: {
        revenue: number;
        orders: number;
        average: number;
        last_week_revenue: number;
        change_percent: number | null;
    };
    hourly: Row[];
    topProducts: Row[];
    back: string;
}) {
    const change = metrics.change_percent;

    return (
        <>
            <Head title={t('Hari ini')} />
            <div className="mx-auto grid w-full max-w-[1100px] gap-5 p-4 md:p-6">
                <PageHeader
                    title={t('Hari ini')}
                    subtitle={`${t('Hari bisnis')} ${dateId(date)}`}
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <Link href={back}>
                                    ←{' '}
                                    {back === '/pos' ? t('Kasir') : t('Dapur')}
                                </Link>
                            </Button>
                            <AccountMenu />
                        </>
                    }
                />
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-3">
                    <Stat
                        label={t('Omzet hari ini')}
                        value={rupiah(metrics.revenue)}
                        hint={
                            change === null ? (
                                t('Belum ada pembanding')
                            ) : (
                                <span
                                    className={`inline-flex items-center gap-1 font-medium ${change >= 0 ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400'}`}
                                >
                                    {change >= 0 ? (
                                        <TrendingUp className="size-3.5" />
                                    ) : (
                                        <TrendingDown className="size-3.5" />
                                    )}
                                    {Math.abs(change)}% {t('vs minggu lalu')}
                                </span>
                            )
                        }
                    />
                    <Stat label={t('Transaksi')} value={metrics.orders} />
                    <Stat
                        label={t('Rata-rata transaksi')}
                        value={rupiah(metrics.average)}
                    />
                </div>
                <div className="grid gap-4 lg:grid-cols-2">
                    <ChartCard title={t('Penjualan per jam')}>
                        <ColumnChart
                            title={t('Penjualan per jam hari ini')}
                            data={hourly.map((h) => ({
                                label: h.label.slice(0, 2),
                                value: h.total,
                            }))}
                        />
                    </ChartCard>
                    <ChartCard title={t('Menu terlaris hari ini')}>
                        <BarList
                            title={t('Menu terlaris hari ini')}
                            data={topProducts.map((p) => ({
                                label: `${p.label} · ${p.orders}x`,
                                value: p.total,
                            }))}
                        />
                    </ChartCard>
                </div>
            </div>
        </>
    );
}
