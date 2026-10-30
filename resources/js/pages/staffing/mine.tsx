import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ScanFace } from 'lucide-react';
import { useEffect, useState } from 'react';
import { MonthCalendar } from '@/components/pos/month-calendar';
import { PageHeader, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, longshiftText, timeId } from '@/lib/format';
import { StaffingTabs } from '@/components/pos/section-tabs';

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
type Plan = {
    name: string;
    starts_at: string;
    ends_at: string;
    positions: string[];
};
type Props = {
    name: string;
    month: string;
    records: Rec[];
    planned: Record<string, Plan[]>;
    today: string;
    standard_hours: number;
    totals: {
        days: number;
        hours: number;
        late: number;
        late_minutes: number;
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
const short = (t: string) => t.slice(0, 5);

export default function Mine({
    name,
    month,
    records,
    planned,
    today,
    standard_hours,
    totals,
    open_since,
    can,
}: Props) {
    const [now, setNow] = useState(Date.now());
    const [picked, setPicked] = useState<string | null>(null);
    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 30000);

        return () => clearInterval(t);
    }, []);
    const go = (m: string) =>
        router.get('/attendance/mine', { month: m }, { preserveState: true });
    const byDay = (d: string) => records.filter((r) => r.work_date === d);
    const lsLabel = (r: Rec) =>
        r.longshift
            ? r.longshift.status === 'approved'
                ? `Longshift ${longshiftText(r.longshift.approved_hours, standard_hours)} disetujui`
                : r.longshift.status === 'rejected'
                  ? 'Longshift ditolak'
                  : `Longshift +${longshiftText(r.longshift.hours, standard_hours)} menunggu MOD`
            : null;

    return (
        <>
            <Head title="Absensi" />
            <div className="mx-auto grid w-full max-w-4xl gap-5 p-4 md:p-6">
                <StaffingTabs />
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
                    <Button
                        variant="ghost"
                        className="h-11"
                        onClick={() => router.get('/attendance/mine')}
                    >
                        Bulan ini
                    </Button>
                </div>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Hari hadir" value={totals.days} />
                    <Stat
                        label="Total jam kerja"
                        value={`${totals.hours} jam`}
                    />
                    <Stat
                        label="Terlambat"
                        value={`${totals.late} kali`}
                        hint={
                            totals.late
                                ? `Total ${totals.late_minutes} menit`
                                : 'Tidak pernah terlambat'
                        }
                    />
                    <Stat
                        label="Longshift disetujui"
                        value={longshiftText(totals.long_hours, standard_hours)}
                    />
                </div>

                <MonthCalendar
                    month={month}
                    today={today}
                    selected={picked}
                    onSelect={(d) => setPicked(picked === d ? null : d)}
                    render={(d) => {
                        const recs = byDay(d);
                        const plan = planned[d] ?? [];
                        const missed =
                            recs.length === 0 && plan.length > 0 && d < today;

                        return (
                            <>
                                {plan.map((p) => (
                                    <span
                                        key={p.name}
                                        className="truncate text-[10px] leading-tight text-muted-foreground"
                                    >
                                        {p.name} {short(p.starts_at)}–
                                        {short(p.ends_at)}
                                    </span>
                                ))}
                                {recs.map((r) => (
                                    <span
                                        key={r.id}
                                        className={`rounded px-1 text-[10px] leading-tight font-medium sm:text-xs ${r.clock_out_at ? 'bg-emerald-600/15 text-emerald-800 dark:text-emerald-300' : 'bg-amber-600/15 text-amber-800 dark:text-amber-300'}`}
                                    >
                                        {timeId(r.clock_in_at)}–
                                        {r.clock_out_at
                                            ? timeId(r.clock_out_at)
                                            : '…'}
                                    </span>
                                ))}
                                {recs.some((r) => (r.late ?? 0) > 0) && (
                                    <span className="text-[10px] leading-tight text-amber-700 dark:text-amber-400">
                                        Telat
                                    </span>
                                )}
                                {recs.some((r) => r.longshift) && (
                                    <span className="text-[10px] leading-tight font-medium text-violet-700 dark:text-violet-300">
                                        Longshift
                                    </span>
                                )}
                                {missed && (
                                    <span className="text-[10px] leading-tight text-red-700 dark:text-red-400">
                                        Tidak hadir
                                    </span>
                                )}
                            </>
                        );
                    }}
                />

                {picked && (
                    <section className="grid gap-2 rounded-xl border bg-card p-4">
                        <h3 className="font-semibold">{dateId(picked)}</h3>
                        {(planned[picked] ?? []).map((p) => (
                            <p
                                key={p.name}
                                className="text-sm text-muted-foreground"
                            >
                                Jadwal: {p.name} {short(p.starts_at)}–
                                {short(p.ends_at)}
                                {p.positions.length > 0 &&
                                    ` (${p.positions.join(' + ')})`}
                            </p>
                        ))}
                        {byDay(picked).length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Tidak ada catatan absensi.
                            </p>
                        ) : (
                            byDay(picked).map((r) => (
                                <div
                                    key={r.id}
                                    className="grid gap-0.5 text-sm"
                                >
                                    <p>
                                        Masuk <b>{timeId(r.clock_in_at)}</b> ·
                                        Pulang{' '}
                                        <b>
                                            {r.clock_out_at
                                                ? timeId(r.clock_out_at)
                                                : 'belum pulang'}
                                        </b>
                                        {r.hours !== null &&
                                            ` · ${r.hours} jam`}
                                    </p>
                                    {r.late !== null && (
                                        <p className="text-muted-foreground">
                                            {r.late > 0
                                                ? `Terlambat ${r.late} menit`
                                                : 'Tepat waktu'}
                                        </p>
                                    )}
                                    {lsLabel(r) && (
                                        <p className="text-violet-700 dark:text-violet-300">
                                            {lsLabel(r)}
                                        </p>
                                    )}
                                </div>
                            ))
                        )}
                    </section>
                )}

                <div className="flex flex-wrap gap-3 text-xs text-muted-foreground">
                    <span>
                        <span className="mr-1 inline-block size-2 rounded-full bg-emerald-600" />
                        Sudah pulang
                    </span>
                    <span>
                        <span className="mr-1 inline-block size-2 rounded-full bg-amber-600" />
                        Belum pulang
                    </span>
                    <span>
                        Ketuk tanggal untuk rincian. Teks abu-abu = jadwal.
                    </span>
                </div>
            </div>
        </>
    );
}
