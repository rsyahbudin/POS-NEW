import { Head, Link, router } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { ChartCard, LineChart } from '@/components/pos/charts';
import { Field, PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    today: string;
    preview: Summary;
    shifts: {
        id: number;
        user: string;
        opened_at: string;
        closed_at: string | null;
        closed_by: string | null;
        difference: number | null;
    }[];
    openShifts: { id: number; user: string; opened_at: string }[];
    history: { date: string; summary: Summary }[];
    channels: string[];
};

export default function DailyReport({
    date,
    today,
    preview,
    shifts,
    openShifts,
    history,
    channels,
}: Props) {
    return (
        <>
            <Head title="Laporan Harian" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Laporan harian"
                    subtitle={`${dateId(date)}${date === today ? ' (hari ini)' : ''}`}
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

                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/day-close',
                            Object.fromEntries(
                                new FormData(e.currentTarget).entries(),
                            ),
                            { preserveState: true },
                        );
                    }}
                >
                    <Field label="Tanggal">
                        <Input
                            className="h-11"
                            type="date"
                            name="date"
                            defaultValue={date}
                            max={today}
                        />
                    </Field>
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        onClick={() =>
                            router.post(
                                '/admin/day-close/send',
                                { date },
                                { preserveScroll: true },
                            )
                        }
                    >
                        <Send /> Kirim laporan ini sekarang
                    </Button>
                </form>
                <p className="text-sm text-muted-foreground">
                    {channels.length > 0 ? (
                        <>
                            Dikirim ke: {channels.join(', ')}. Laporan dikirim
                            otomatis begitu shift terakhir ditutup.
                        </>
                    ) : (
                        <>
                            Belum ada saluran pengiriman.{' '}
                            <Link
                                href="/admin/settings"
                                className="font-medium underline"
                            >
                                Isi Telegram, email, atau WhatsApp di Pengaturan
                                › Notifikasi
                            </Link>{' '}
                            agar laporan sampai ke HP Anda otomatis.
                        </>
                    )}
                </p>

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
                        . Tutup lewat halaman{' '}
                        <Link href="/shift" className="font-medium underline">
                            Shift
                        </Link>
                        ; laporan hari itu menunggu shift ini.
                    </div>
                )}

                <section className="grid gap-2">
                    <h2 className="font-semibold">Shift pada hari ini</h2>
                    <ResponsiveTable
                        headers={[
                            'Kasir',
                            'Buka',
                            'Tutup',
                            'Ditutup oleh',
                            'Selisih kas',
                        ]}
                        numeric={[4]}
                        empty="Tidak ada shift pada tanggal ini."
                        rows={shifts.map((s) => [
                            s.user,
                            timeId(s.opened_at),
                            s.closed_at ? (
                                timeId(s.closed_at)
                            ) : (
                                <span key={s.id} className="text-amber-700">
                                    Masih terbuka
                                </span>
                            ),
                            s.closed_by ?? '-',
                            s.difference === null ? '-' : rupiah(s.difference),
                        ])}
                    />
                </section>

                <ChartCard title="Omzet bersih per hari (riwayat)">
                    <LineChart
                        title="Omzet bersih per hari"
                        data={[...history].reverse().map((h) => ({
                            label: h.date,
                            value: h.summary.net,
                        }))}
                    />
                </ChartCard>
                <section className="grid gap-2">
                    <h2 className="font-semibold">Riwayat laporan harian</h2>
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
