import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, ScanFace } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { MonthCalendar } from '@/components/pos/month-calendar';
import { Field, PageHeader, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
    in_reason: string | null;
    out_reason: string | null;
    review_status: string | null;
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
    requests: {
        id: number;
        work_date: string;
        kind: 'missing_out' | 'both';
        clock_in: string | null;
        clock_out: string | null;
        reason: string;
        status: 'pending' | 'approved' | 'rejected';
        decision_note: string | null;
    }[];
    forgot: string[];
    reach_days: number;
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
    requests,
    forgot,
    reach_days,
    open_since,
    can,
}: Props) {
    const [now, setNow] = useState(Date.now());
    const [picked, setPicked] = useState<string | null>(null);
    const errors = usePage().props.errors as Record<string, string>;
    const [ask, setAsk] = useState<{
        kind: 'missing_out' | 'both';
        work_date: string;
        clock_in: string;
        clock_out: string;
        reason: string;
    } | null>(null);
    const dayMs = 86400000;
    const ymd = (d: Date) =>
        `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    const minDate = ymd(
        new Date(new Date(`${today}T00:00:00`).getTime() - reach_days * dayMs),
    );
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
            <div className="mx-auto grid w-full max-w-4xl grid-cols-1 gap-5 p-4 md:p-6">
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

                {forgot.length > 0 && (
                    <section
                        role="alert"
                        className="grid gap-2 rounded-xl border border-amber-600/40 bg-card p-4"
                    >
                        <p className="font-medium">
                            Belum absen pulang:{' '}
                            {forgot.map((d) => dateId(d)).join(', ')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            Ajukan koreksi supaya jam pulangnya tercatat. MOD
                            yang memutuskan.
                        </p>
                        <Button
                            className="h-11 w-fit"
                            onClick={() =>
                                setAsk({
                                    kind: 'missing_out',
                                    work_date: forgot[0],
                                    clock_in: '',
                                    clock_out: '',
                                    reason: '',
                                })
                            }
                        >
                            Ajukan koreksi
                        </Button>
                    </section>
                )}

                <section className="grid gap-2 rounded-xl border bg-card p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="font-semibold">Lupa absen?</h2>
                        <Button
                            variant="outline"
                            className="h-11"
                            onClick={() =>
                                setAsk({
                                    kind: 'missing_out',
                                    work_date: today,
                                    clock_in: '',
                                    clock_out: '',
                                    reason: '',
                                })
                            }
                        >
                            Ajukan koreksi absen
                        </Button>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Lupa absen masuk atau pulang? Ajukan dengan alasan; MOD
                        menyetujui atau menolak. Bisa sampai {reach_days} hari
                        ke belakang.
                    </p>
                    {requests.length > 0 && (
                        <ul className="grid gap-2 text-sm">
                            {requests.map((q) => (
                                <li
                                    key={q.id}
                                    className="grid gap-0.5 rounded-lg border p-3"
                                >
                                    <p className="flex flex-wrap items-center justify-between gap-2">
                                        <span className="font-medium">
                                            {dateId(q.work_date)} ·{' '}
                                            {q.kind === 'both'
                                                ? `masuk ${q.clock_in}, pulang ${q.clock_out}`
                                                : `pulang ${q.clock_out}`}
                                        </span>
                                        <span
                                            className={`rounded-full border px-2 py-0.5 text-xs ${q.status === 'approved' ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-400' : q.status === 'rejected' ? 'border-red-600/40 text-red-700 dark:text-red-400' : 'border-amber-600/40 text-amber-700 dark:text-amber-400'}`}
                                        >
                                            {q.status === 'approved'
                                                ? 'Disetujui'
                                                : q.status === 'rejected'
                                                  ? 'Ditolak'
                                                  : 'Menunggu MOD'}
                                        </span>
                                    </p>
                                    <p className="text-muted-foreground">
                                        {q.reason}
                                    </p>
                                    {q.decision_note && (
                                        <p className="text-muted-foreground">
                                            Catatan MOD: {q.decision_note}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
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
                                        <span className="hidden sm:inline">
                                            {p.name}{' '}
                                        </span>
                                        {short(p.starts_at)}–{short(p.ends_at)}
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
                                    {r.in_reason && (
                                        <p className="text-muted-foreground">
                                            Alasan masuk: {r.in_reason}
                                        </p>
                                    )}
                                    {r.out_reason && (
                                        <p className="text-muted-foreground">
                                            Alasan pulang: {r.out_reason}
                                        </p>
                                    )}
                                    {r.review_status === 'pending' && (
                                        <p className="text-amber-700 dark:text-amber-400">
                                            Menunggu ditinjau MOD (tidak ada
                                            jadwal)
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

            {ask && (
                <Modal
                    open
                    onClose={() => setAsk(null)}
                    title="Ajukan koreksi absen"
                >
                    <div className="grid gap-2" role="radiogroup">
                        {(
                            [
                                [
                                    'missing_out',
                                    'Lupa absen pulang',
                                    'Saya sudah absen masuk tapi lupa absen pulang.',
                                ],
                                [
                                    'both',
                                    'Lupa absen masuk dan pulang',
                                    'Tidak ada catatan sama sekali pada hari itu.',
                                ],
                            ] as const
                        ).map(([k, label, hint]) => (
                            <button
                                key={k}
                                type="button"
                                role="radio"
                                aria-checked={ask.kind === k}
                                onClick={() => setAsk({ ...ask, kind: k })}
                                className={`rounded-xl border p-3 text-left ${ask.kind === k ? 'border-primary bg-accent' : ''}`}
                            >
                                <span className="block font-medium">
                                    {label}
                                </span>
                                <span className="block text-xs text-muted-foreground">
                                    {hint}
                                </span>
                            </button>
                        ))}
                    </div>
                    <Field label="Tanggal">
                        <Input
                            className="h-11"
                            type="date"
                            min={minDate}
                            max={today}
                            value={ask.work_date}
                            onChange={(e) =>
                                setAsk({ ...ask, work_date: e.target.value })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        {ask.kind === 'both' && (
                            <Field label="Jam masuk">
                                <Input
                                    className="h-11"
                                    type="time"
                                    value={ask.clock_in}
                                    onChange={(e) =>
                                        setAsk({
                                            ...ask,
                                            clock_in: e.target.value,
                                        })
                                    }
                                />
                            </Field>
                        )}
                        <Field label="Jam pulang">
                            <Input
                                className="h-11"
                                type="time"
                                value={ask.clock_out}
                                onChange={(e) =>
                                    setAsk({
                                        ...ask,
                                        clock_out: e.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <Field
                        label="Alasan (wajib)"
                        hint="Jelaskan kenapa lupa dan jam sebenarnya. MOD membaca ini."
                    >
                        <textarea
                            className="min-h-20 w-full rounded-xl border border-input bg-card p-3 text-base outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            value={ask.reason}
                            maxLength={200}
                            onChange={(e) =>
                                setAsk({ ...ask, reason: e.target.value })
                            }
                        />
                    </Field>
                    {Object.values(errors ?? {})[0] && (
                        <p role="alert" className="text-sm text-destructive">
                            {Object.values(errors)[0]}
                        </p>
                    )}
                    <Button
                        className="h-12"
                        disabled={
                            !ask.work_date ||
                            !ask.clock_out ||
                            (ask.kind === 'both' && !ask.clock_in) ||
                            ask.reason.trim().length < 5
                        }
                        onClick={() =>
                            router.post(
                                '/attendance/requests',
                                {
                                    ...ask,
                                    clock_in:
                                        ask.kind === 'both'
                                            ? ask.clock_in
                                            : undefined,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setAsk(null),
                                },
                            )
                        }
                    >
                        Kirim pengajuan
                    </Button>
                </Modal>
            )}
        </>
    );
}
