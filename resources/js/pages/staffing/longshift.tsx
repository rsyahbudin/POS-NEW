import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId } from '@/lib/format';

type Row = {
    id: number;
    name: string;
    role: string | null;
    work_date: string;
    source: 'schedule' | 'attendance';
    hours: number;
    approved_hours: number;
    status: 'pending' | 'approved' | 'rejected';
    decided_by: string | null;
    note: string | null;
};
type Props = {
    filters: { from: string; to: string };
    pending: Row[];
    decided: Row[];
    standard_hours: number;
    can: { decide: boolean };
};

const SOURCE = { schedule: 'Jadwal (rencana)', attendance: 'Absensi (nyata)' };

export default function Longshift({
    filters,
    pending,
    decided,
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
    const firstError = Object.values(errors ?? {})[0];

    return (
        <>
            <Head title="Longshift" />
            <div className="mx-auto grid w-full max-w-[1200px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Persetujuan longshift"
                    subtitle={`Jam kerja lebih dari ${standard_hours} jam sehari, dihitung per jam penuh. Jam yang disetujui masuk laporan HR.`}
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

                <section className="grid gap-2">
                    <h2 className="font-semibold">
                        Menunggu persetujuan ({pending.length})
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
                                    {dateId(r.work_date)} · {SOURCE[r.source]} ·{' '}
                                    <span className="font-semibold text-foreground">
                                        +{r.hours} jam
                                    </span>
                                </p>
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
                        'Asal',
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
                        SOURCE[r.source],
                        `${r.hours} jam`,
                        `${r.approved_hours} jam`,
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
                        {dateId(ask.row.work_date)} · {SOURCE[ask.row.source]} ·
                        diajukan {ask.row.hours} jam
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
        </>
    );
}
