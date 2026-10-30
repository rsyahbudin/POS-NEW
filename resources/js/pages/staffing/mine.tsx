import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ScanFace } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, timeId } from '@/lib/format';

type Rec = {
    id: number;
    work_date: string;
    clock_in_at: string;
    clock_out_at: string | null;
    hours: number | null;
    late: number | null;
    method_in: string;
    longshift: { hours: number; approved_hours: number; status: string } | null;
};
type Props = {
    name: string;
    month: string;
    records: Rec[];
    totals: {
        days: number;
        hours: number;
        late: number;
        long_hours: number;
        open: number;
    };
    open_since: string | null;
    can: { kiosk: boolean };
};

const shiftMonth = (m: string, by: number) => {
    const d = new Date(`${m}-01T00:00:00`);
    d.setMonth(d.getMonth() + by);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};
const monthName = (m: string) =>
    new Date(`${m}-01T00:00:00`).toLocaleDateString('id-ID', {
        month: 'long',
        year: 'numeric',
    });
const since = (iso: string, now: number) => {
    const m = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));

    return `${Math.floor(m / 60)} jam ${m % 60} menit`;
};

export default function Mine({
    name,
    month,
    records,
    totals,
    open_since,
    can,
}: Props) {
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 30000);

        return () => clearInterval(t);
    }, []);
    const go = (m: string) =>
        router.get('/attendance/mine', { month: m }, { preserveState: true });

    return (
        <>
            <Head title="Absensi" />
            <div className="mx-auto grid w-full max-w-4xl gap-5 p-4 md:p-6">
                <PageHeader
                    title="Absensi saya"
                    subtitle={name}
                    actions={
                        can.kiosk ? (
                            <Button asChild className="h-11">
                                <Link href="/attendance/kiosk">
                                    <ScanFace /> Buka layar absen
                                </Link>
                            </Button>
                        ) : undefined
                    }
                />

                <section className="grid gap-1 rounded-2xl border bg-card p-5 text-center">
                    {open_since ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Masuk pukul {timeId(open_since)}
                            </p>
                            <p className="text-xl font-semibold">
                                Sedang bekerja {since(open_since, now)}
                            </p>
                        </>
                    ) : (
                        <p className="text-xl font-semibold">Belum masuk</p>
                    )}
                    <p className="text-xs text-muted-foreground">
                        Absen masuk dan pulang lewat layar absen di outlet.
                    </p>
                </section>

                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Bulan sebelumnya"
                        onClick={() => go(shiftMonth(month, -1))}
                    >
                        <ChevronLeft />
                    </Button>
                    <h2 className="min-w-40 text-center text-lg font-semibold capitalize">
                        {monthName(month)}
                    </h2>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Bulan berikutnya"
                        onClick={() => go(shiftMonth(month, 1))}
                    >
                        <ChevronRight />
                    </Button>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Hari hadir" value={totals.days} />
                    <Stat
                        label="Total jam kerja"
                        value={`${totals.hours} jam`}
                    />
                    <Stat label="Terlambat" value={`${totals.late} kali`} />
                    <Stat
                        label="Longshift disetujui"
                        value={`${totals.long_hours} jam`}
                    />
                </div>

                <ResponsiveTable
                    headers={[
                        'Tanggal',
                        'Masuk',
                        'Pulang',
                        'Jam',
                        'Terlambat',
                        'Longshift',
                    ]}
                    numeric={[3]}
                    empty="Belum ada catatan absensi bulan ini."
                    rows={records.map((r) => [
                        dateId(r.work_date),
                        timeId(r.clock_in_at),
                        r.clock_out_at
                            ? timeId(r.clock_out_at)
                            : 'Belum pulang',
                        r.hours ?? '-',
                        r.late === null
                            ? '-'
                            : r.late > 0
                              ? `${r.late} menit`
                              : 'Tepat waktu',
                        r.longshift
                            ? `+${r.longshift.hours}j ${r.longshift.status === 'approved' ? `disetujui ${r.longshift.approved_hours}j` : r.longshift.status === 'rejected' ? 'ditolak' : 'menunggu MOD'}`
                            : '-',
                    ])}
                />
            </div>
        </>
    );
}
