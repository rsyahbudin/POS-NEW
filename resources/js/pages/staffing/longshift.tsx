import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, longshiftText, timeId } from '@/lib/format';
import { StaffingTabs } from '@/components/pos/section-tabs';

type Row = {
    id: number;
    name: string;
    role: string | null;
    work_date: string;
    source: 'schedule' | 'attendance';
    hours: number;
    planned_hours: number;
    worked_hours: number;
    approved_hours: number;
    status: 'pending' | 'approved' | 'rejected';
    decided_by: string | null;
    note: string | null;
    reasons: string[];
};
type Req = {
    id: number;
    name: string;
    work_date: string;
    kind: 'missing_out' | 'both';
    clock_in: string | null;
    clock_out: string | null;
    reason: string;
};
type Review = {
    id: number;
    name: string;
    work_date: string;
    clock_in_at: string;
    clock_out_at: string | null;
    reason: string;
};
type Props = {
    filters: { from: string; to: string };
    pending: Row[];
    decided: Row[];
    requests: Req[];
    reviews: Review[];
    standard_hours: number;
    can: { decide: boolean; decide_attendance: boolean };
};

export default function Longshift({
    filters,
    pending,
    decided,
    requests,
    reviews,
    standard_hours,
    can,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const qs = new URLSearchParams(filters).toString();
    const [ask, setAsk] = useState<{
        row: Row;
        approve: boolean;
        hours: number;
        note: string;
    } | null>(null);
    const [judge, setJudge] = useState<{
        url: string;
        title: string;
        detail: string;
        approve: boolean;
        note: string;
    } | null>(null);
    const firstError = Object.values(errors ?? {})[0];

    return (
        <>
            <Head title="Longshift" />
            <div className="mx-auto grid w-full max-w-[1200px] grid-cols-1 gap-5 p-4 md:p-6">
                <StaffingTabs />
                <PageHeader
                    title="Persetujuan"
                    subtitle={`Koreksi absen, absen di luar jadwal, dan longshift (jam kerja nyata melebihi jadwal hari itu, atau melebihi ${standard_hours} jam bila tidak ada jadwal, per jam penuh). Dua shift terjadwal dalam sehari bukan longshift. Yang disetujui masuk laporan HR.`}
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a href={`/longshift/export?${qs}&format=xlsx`}>
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a href={`/longshift/export?${qs}&format=pdf`}>
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                {firstError && (
                    <p role="alert" className="text-sm text-destructive">
                        {firstError}
                    </p>
                )}

                {(requests.length > 0 || can.decide_attendance) && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            Pengajuan koreksi absen ({requests.length})
                        </h2>
                        {requests.length === 0 && (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Tidak ada pengajuan.
                            </p>
                        )}
                        {requests.map((q) => (
                            <div
                                key={q.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {q.name} · {dateId(q.work_date)}
                                    </p>
                                    <p className="text-sm">
                                        {q.kind === 'both'
                                            ? `Lupa masuk dan pulang: masuk ${q.clock_in}, pulang ${q.clock_out}`
                                            : `Lupa absen pulang: pulang ${q.clock_out}`}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Alasan: {q.reason}
                                    </p>
                                </div>
                                {can.decide_attendance && (
                                    <div className="flex gap-2">
                                        <Button
                                            className="h-11"
                                            onClick={() =>
                                                setJudge({
                                                    url: `/attendance/requests/${q.id}/decide`,
                                                    title: `Setujui koreksi ${q.name}`,
                                                    detail: `${dateId(q.work_date)} · ${q.reason}`,
                                                    approve: true,
                                                    note: '',
                                                })
                                            }
                                        >
                                            Setujui
                                        </Button>
                                        <Button
                                            className="h-11"
                                            variant="outline"
                                            onClick={() =>
                                                setJudge({
                                                    url: `/attendance/requests/${q.id}/decide`,
                                                    title: `Tolak koreksi ${q.name}`,
                                                    detail: `${dateId(q.work_date)} · ${q.reason}`,
                                                    approve: false,
                                                    note: '',
                                                })
                                            }
                                        >
                                            Tolak
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                {(reviews.length > 0 || can.decide_attendance) && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            Absen di luar jadwal ({reviews.length})
                        </h2>
                        {reviews.length === 0 && (
                            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Tidak ada yang perlu ditinjau.
                            </p>
                        )}
                        {reviews.map((v) => (
                            <div
                                key={v.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-600/40 bg-card p-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">
                                        {v.name} · {dateId(v.work_date)}
                                    </p>
                                    <p className="text-sm">
                                        Masuk {timeId(v.clock_in_at)}, pulang{' '}
                                        {v.clock_out_at
                                            ? timeId(v.clock_out_at)
                                            : 'belum'}{' '}
                                        · tidak ada jadwal
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        Alasan: {v.reason || '-'}
                                    </p>
                                </div>
                                {can.decide_attendance && (
                                    <div className="flex gap-2">
                                        <Button
                                            className="h-11"
                                            onClick={() =>
                                                setJudge({
                                                    url: `/attendance/records/${v.id}/review`,
                                                    title: `Terima absen ${v.name}`,
                                                    detail: `${dateId(v.work_date)} · ${v.reason}`,
                                                    approve: true,
                                                    note: '',
                                                })
                                            }
                                        >
                                            Terima
                                        </Button>
                                        <Button
                                            className="h-11"
                                            variant="outline"
                                            onClick={() =>
                                                setJudge({
                                                    url: `/attendance/records/${v.id}/review`,
                                                    title: `Tolak absen ${v.name}`,
                                                    detail: `${dateId(v.work_date)} · catatan dihapus, tidak dihitung`,
                                                    approve: false,
                                                    note: '',
                                                })
                                            }
                                        >
                                            Tolak
                                        </Button>
                                    </div>
                                )}
                            </div>
                        ))}
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Longshift menunggu persetujuan ({pending.length})
                    </h2>
                    {pending.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            Tidak ada longshift yang menunggu.
                        </p>
                    )}
                    {pending.map((r) => (
                        <div
                            key={r.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-600/40 bg-card p-3"
                        >
                            <div>
                                <p className="font-medium">
                                    {r.name}{' '}
                                    {r.role && (
                                        <span className="text-xs text-muted-foreground">
                                            · {r.role}
                                        </span>
                                    )}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {dateId(r.work_date)} · dijadwalkan{' '}
                                    {r.planned_hours
                                        ? `${r.planned_hours} jam`
                                        : 'tidak ada'}{' '}
                                    · bekerja {r.worked_hours} jam ·{' '}
                                    <span className="font-semibold text-foreground">
                                        +
                                        {longshiftText(r.hours, standard_hours)}
                                    </span>
                                </p>
                                {r.reasons.length > 0 && (
                                    <p className="text-sm text-muted-foreground">
                                        Alasan karyawan: {r.reasons.join(' · ')}
                                    </p>
                                )}
                            </div>
                            {can.decide && (
                                <div className="flex gap-2">
                                    <Button
                                        className="h-11"
                                        onClick={() =>
                                            setAsk({
                                                row: r,
                                                approve: true,
                                                hours: r.hours,
                                                note: '',
                                            })
                                        }
                                    >
                                        Setujui
                                    </Button>
                                    <Button
                                        className="h-11"
                                        variant="outline"
                                        onClick={() =>
                                            setAsk({
                                                row: r,
                                                approve: false,
                                                hours: 0,
                                                note: '',
                                            })
                                        }
                                    >
                                        Tolak
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                </section>

                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const d = new FormData(e.currentTarget);
                        router.get('/longshift', {
                            from: d.get('from'),
                            to: d.get('to'),
                        });
                    }}
                >
                    <Field label="Riwayat dari">
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
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                </form>
                <ResponsiveTable
                    headers={[
                        'Tanggal',
                        'Nama',
                        'Jadwal / bekerja',
                        'Diajukan',
                        'Disetujui',
                        'Status',
                        'Oleh',
                        'Catatan',
                    ]}
                    numeric={[3, 4]}
                    rows={decided.map((r) => [
                        dateId(r.work_date),
                        r.name,
                        `${r.planned_hours ? `${r.planned_hours} jam` : '-'} / ${r.worked_hours} jam`,
                        longshiftText(r.hours, standard_hours),
                        longshiftText(r.approved_hours, standard_hours),
                        r.status === 'approved' ? 'Disetujui' : 'Ditolak',
                        r.decided_by ?? '-',
                        r.note ?? '-',
                    ])}
                />
            </div>

            {ask && (
                <Modal
                    open
                    onClose={() => setAsk(null)}
                    title={`${ask.approve ? 'Setujui' : 'Tolak'} longshift ${ask.row.name}`}
                >
                    <p className="text-sm text-muted-foreground">
                        {dateId(ask.row.work_date)} · dijadwalkan{' '}
                        {ask.row.planned_hours
                            ? `${ask.row.planned_hours} jam`
                            : 'tidak ada'}
                        , bekerja {ask.row.worked_hours} jam · diajukan{' '}
                        {longshiftText(ask.row.hours, standard_hours)}
                    </p>
                    {ask.approve && (
                        <Field
                            label="Jam yang disetujui"
                            hint="Kurangi bila hanya sebagian jam yang diakui."
                        >
                            <Input
                                className="h-11"
                                type="number"
                                min={0}
                                max={ask.row.hours}
                                value={ask.hours}
                                onChange={(e) =>
                                    setAsk({
                                        ...ask,
                                        hours: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                    )}
                    <Field label="Catatan (opsional)">
                        <Input
                            className="h-11"
                            value={ask.note}
                            onChange={(e) =>
                                setAsk({ ...ask, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        onClick={() =>
                            router.post(
                                `/longshift/${ask.row.id}/decide`,
                                {
                                    approve: ask.approve,
                                    hours: ask.approve ? ask.hours : undefined,
                                    note: ask.note || undefined,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setAsk(null),
                                },
                            )
                        }
                    >
                        {ask.approve ? 'Setujui' : 'Tolak'}
                    </Button>
                </Modal>
            )}

            {judge && (
                <Modal open onClose={() => setJudge(null)} title={judge.title}>
                    <p className="text-sm text-muted-foreground">
                        {judge.detail}
                    </p>
                    <Field
                        label={
                            judge.approve
                                ? 'Catatan (opsional)'
                                : 'Alasan penolakan (wajib)'
                        }
                    >
                        <Input
                            className="h-11"
                            value={judge.note}
                            onChange={(e) =>
                                setJudge({ ...judge, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={
                            !judge.approve && judge.note.trim().length < 5
                        }
                        onClick={() =>
                            router.post(
                                judge.url,
                                {
                                    approve: judge.approve ? 1 : 0,
                                    note: judge.note || undefined,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setJudge(null),
                                },
                            )
                        }
                    >
                        {judge.approve ? 'Setujui' : 'Tolak'}
                    </Button>
                </Modal>
            )}
        </>
    );
}
