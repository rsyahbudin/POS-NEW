import { Head, router } from '@inertiajs/react';
import { ChartCard, LineChart } from '@/components/pos/charts';
import { PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, rupiah, timeId } from '@/lib/format';

type Summary = {
    orders: number;
    gross: number;
    discounts: number;
    refunds: number;
    net: number;
    by_method: Record<string, number>;
    voids: { items: number; value: number };
    comps: { items: number; value: number };
    cash_difference: number;
};
type Props = {
    date: string;
    preview: Summary;
    openShifts: { id: number; user: string; opened_at: string }[];
    closed: boolean;
    history: { date: string; summary: Summary }[];
};

export default function DayClose({
    date,
    preview,
    openShifts,
    closed,
    history,
}: Props) {
    return (
        <>
            <Head title="Tutup Hari" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Tutup hari (Z-report)"
                    subtitle={`Hari bisnis ${dateId(date)}`}
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a href="/admin/day-close/export?format=xlsx">
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a href="/admin/day-close/export?format=pdf">
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label="Omzet bersih"
                        value={rupiah(preview.net)}
                        hint={`${preview.orders} order`}
                    />
                    <Stat label="Diskon" value={rupiah(preview.discounts)} />
                    <Stat
                        label="Void / komplimen"
                        value={`${preview.voids.items} / ${preview.comps.items}`}
                    />
                    <Stat
                        label="Selisih kas"
                        value={rupiah(preview.cash_difference)}
                    />
                </div>
                <section className="rounded-xl border bg-card p-3 text-sm">
                    <h2 className="mb-1 font-semibold">Per metode bayar</h2>
                    {Object.entries(preview.by_method).map(([m, v]) => (
                        <p key={m} className="flex justify-between">
                            <span>{m}</span>
                            <span className="tabular-nums">{rupiah(v)}</span>
                        </p>
                    ))}
                    {Object.keys(preview.by_method).length === 0 && (
                        <p className="text-muted-foreground">
                            Belum ada pembayaran.
                        </p>
                    )}
                </section>

                {openShifts.length > 0 && (
                    <div
                        role="alert"
                        className="rounded-xl border border-amber-500 bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        Masih ada shift terbuka:{' '}
                        {openShifts
                            .map(
                                (s) =>
                                    `${s.user} (sejak ${timeId(s.opened_at)})`,
                            )
                            .join(', ')}
                        . Tutup semua shift dulu.
                    </div>
                )}
                {closed ? (
                    <p className="rounded-xl border p-3 text-sm text-emerald-800">
                        Hari ini sudah ditutup.
                    </p>
                ) : (
                    <Button
                        className="h-12 max-w-sm"
                        disabled={openShifts.length > 0}
                        onClick={() =>
                            confirm(
                                'Tutup hari bisnis? Z-report akan dikirim ke Telegram owner.',
                            ) && router.post('/admin/day-close')
                        }
                    >
                        Tutup hari & kirim Z-report
                    </Button>
                )}

                <ChartCard title="Omzet bersih per hari (riwayat tutup hari)">
                    <LineChart
                        title="Omzet bersih per hari"
                        data={[...history].reverse().map((h) => ({
                            label: h.date,
                            value: h.summary.net,
                        }))}
                    />
                </ChartCard>
                <section>
                    <h2 className="mb-2 font-semibold">Riwayat</h2>
                    <ResponsiveTable
                        headers={[
                            'Tanggal',
                            'Order',
                            'Omzet bersih',
                            'Selisih kas',
                        ]}
                        numeric={[1, 2, 3]}
                        rows={history.map((h) => [
                            dateId(h.date),
                            h.summary.orders,
                            rupiah(h.summary.net),
                            rupiah(h.summary.cash_difference),
                        ])}
                    />
                </section>
            </div>
        </>
    );
}
