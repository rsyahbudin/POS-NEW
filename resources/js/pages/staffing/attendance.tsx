import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, ScanFace } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, longshiftText, timeId } from '@/lib/format';

type Rec = {
    id: number;
    user_id: number;
    name: string;
    work_date: string;
    clock_in_at: string;
    clock_out_at: string | null;
    method_in: string;
    note: string | null;
    hours: number | null;
    late: number | null;
    role: string | null;
    longshift: { hours: number; approved_hours: number; status: string } | null;
};
type Summary = {
    user_id: number;
    name: string;
    role: string | null;
    days: number;
    hours: number;
    late: number;
    long_hours: number;
    open: number;
};
type Props = {
    filters: { from: string; to: string; user_id: number | null };
    records: Rec[];
    summary: Summary[];
    on_duty: { name: string; since: string }[];
    absent: { name: string; shift: string; starts_at: string; late: number }[];
    people: { id: number; name: string }[];
    grace: number;
    standard_hours: number;
};

const pad = (n: number) => String(n).padStart(2, '0');
const ymd = (d: Date) =>
    `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
const monthStart = (by: number) => {
    const d = new Date();

    return ymd(new Date(d.getFullYear(), d.getMonth() + by, 1));
};
const monthEnd = (by: number) => {
    const d = new Date();

    return ymd(new Date(d.getFullYear(), d.getMonth() + by + 1, 0));
};
const hm = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleTimeString('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          })
        : '';

export default function Attendance({
    filters,
    records,
    summary,
    on_duty,
    absent,
    people,
    grace,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const qs = new URLSearchParams({
        from: filters.from,
        to: filters.to,
    }).toString();
    const [form, setForm] = useState<{
        id?: number;
        user_id: number | '';
        date: string;
        clock_in: string;
        clock_out: string;
        note: string;
    } | null>(null);
    const open = records.filter((r) => !r.clock_out_at).length;
    const late = records.filter((r) => (r.late ?? 0) > 0).length;
    const hours = records.reduce((s, r) => s + (r.hours ?? 0), 0);
    const firstError = Object.values(errors ?? {})[0];

    return (
        <>
            <Head title="Absensi" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Rekap absensi"
                    subtitle={`Masuk sampai ${grace} menit setelah jam shift dianggap tepat waktu.`}
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <Link href="/attendance/faces">
                                    <ScanFace /> Wajah karyawan
                                </Link>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/attendance/export?${qs}&format=xlsx`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a href={`/attendance/export?${qs}&format=pdf`}>
                                    Unduh PDF
                                </a>
                            </Button>
                            <Button
                                className="h-11"
                                onClick={() =>
                                    setForm({
                                        user_id: people[0]?.id ?? '',
                                        date: filters.to,
                                        clock_in: '08:00',
                                        clock_out: '',
                                        note: '',
                                    })
                                }
                            >
                                <Plus /> Tambah manual
                            </Button>
                        </>
                    }
                />
                {firstError && (
                    <p role="alert" className="text-sm text-destructive">
                        {firstError}
                    </p>
                )}
                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const d = new FormData(e.currentTarget);
                        router.get('/attendance', {
                            from: d.get('from'),
                            to: d.get('to'),
                            user_id: d.get('user_id') || undefined,
                        });
                    }}
                >
                    <Field label="Dari">
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
                    <Field label="Karyawan">
                        <Select
                            name="user_id"
                            defaultValue={filters.user_id ?? ''}
                        >
                            <option value="">Semua karyawan</option>
                            {people.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        onClick={() =>
                            router.get('/attendance', {
                                from: monthStart(0),
                                to: monthEnd(0),
                                user_id: filters.user_id ?? undefined,
                            })
                        }
                    >
                        Bulan ini
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="h-11"
                        onClick={() =>
                            router.get('/attendance', {
                                from: monthStart(-1),
                                to: monthEnd(-1),
                                user_id: filters.user_id ?? undefined,
                            })
                        }
                    >
                        Bulan lalu
                    </Button>
                </form>

                <div className="grid gap-3 lg:grid-cols-2">
                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="mb-2 font-semibold">
                            Sedang bekerja ({on_duty.length})
                        </h2>
                        {on_duty.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Belum ada yang masuk.
                            </p>
                        ) : (
                            <ul className="grid gap-1 text-sm">
                                {on_duty.map((o) => (
                                    <li
                                        key={o.name + o.since}
                                        className="flex justify-between gap-3"
                                    >
                                        <span>{o.name}</span>
                                        <span className="text-muted-foreground">
                                            sejak {timeId(o.since)}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="mb-2 font-semibold">
                            Terjadwal tapi belum absen ({absent.length})
                        </h2>
                        {absent.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Semua yang terjadwal sudah absen.
                            </p>
                        ) : (
                            <ul className="grid gap-1 text-sm">
                                {absent.map((a) => (
                                    <li
                                        key={a.name + a.shift}
                                        className="flex justify-between gap-3"
                                    >
                                        <span>{a.name}</span>
                                        <span className="text-red-700 dark:text-red-400">
                                            {a.shift} {a.starts_at} · telat{' '}
                                            {a.late} menit
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </section>
                </div>

                <section className="grid gap-2">
                    <h2 className="font-semibold">Ringkasan per karyawan</h2>
                    <ResponsiveTable
                        headers={[
                            'Nama',
                            'Hari hadir',
                            'Total jam',
                            'Terlambat',
                            'Longshift',
                            'Belum pulang',
                            '',
                        ]}
                        numeric={[1, 2, 3, 4, 5]}
                        empty="Tidak ada absensi pada periode ini."
                        rows={summary.map((p) => [
                            <span key={p.user_id} className="font-medium">
                                {p.name}
                                {p.role && (
                                    <span className="ml-2 text-xs font-normal text-muted-foreground">
                                        {p.role}
                                    </span>
                                )}
                            </span>,
                            p.days,
                            p.hours,
                            p.late,
                            p.long_hours ? longshiftText(p.long_hours, standard_hours) : '-',
                            p.open || '-',
                            <Button
                                key="f"
                                size="sm"
                                variant="ghost"
                                className="h-10"
                                onClick={() =>
                                    router.get('/attendance', {
                                        from: filters.from,
                                        to: filters.to,
                                        user_id: p.user_id,
                                    })
                                }
                            >
                                Lihat
                            </Button>,
                        ])}
                    />
                </section>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Catatan" value={records.length} />
                    <Stat
                        label="Total jam kerja"
                        value={`${Math.round(hours * 10) / 10} jam`}
                    />
                    <Stat label="Terlambat" value={late} />
                    <Stat
                        label="Belum pulang"
                        value={open}
                        hint="Mungkin lupa absen pulang"
                    />
                </div>

                <h2 className="font-semibold">
                    Rincian
                    {filters.user_id && (
                        <Button
                            variant="ghost"
                            size="sm"
                            className="ml-2 h-9"
                            onClick={() =>
                                router.get('/attendance', {
                                    from: filters.from,
                                    to: filters.to,
                                })
                            }
                        >
                            Tampilkan semua
                        </Button>
                    )}
                </h2>
                <ResponsiveTable
                    headers={[
                        'Tanggal',
                        'Nama',
                        'Masuk',
                        'Pulang',
                        'Jam',
                        'Terlambat',
                        'Sumber',
                        'Catatan',
                        '',
                    ]}
                    numeric={[4]}
                    rows={records.map((r) => [
                        dateId(r.work_date),
                        r.name,
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
                        r.method_in === 'face'
                            ? 'Wajah'
                            : r.method_in === 'button'
                              ? 'Tombol'
                              : 'Manual',
                        r.note ?? '-',
                        <div key={r.id} className="flex gap-1">
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-10"
                                onClick={() =>
                                    setForm({
                                        id: r.id,
                                        user_id: r.user_id,
                                        date: r.work_date,
                                        clock_in: hm(r.clock_in_at),
                                        clock_out: hm(r.clock_out_at),
                                        note: r.note ?? '',
                                    })
                                }
                            >
                                Koreksi
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-10"
                                onClick={() =>
                                    confirm(`Hapus absensi ${r.name}?`) &&
                                    router.delete(
                                        `/attendance/records/${r.id}`,
                                        { preserveScroll: true },
                                    )
                                }
                            >
                                Hapus
                            </Button>
                        </div>,
                    ])}
                />
            </div>

            {form && (
                <Modal
                    open
                    onClose={() => setForm(null)}
                    title={
                        form.id ? 'Koreksi absensi' : 'Tambah absensi manual'
                    }
                >
                    <Field label="Karyawan">
                        <Select
                            value={form.user_id}
                            disabled={!!form.id}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    user_id: Number(e.target.value),
                                })
                            }
                        >
                            {people.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Tanggal">
                        <Input
                            className="h-11"
                            type="date"
                            value={form.date}
                            onChange={(e) =>
                                setForm({ ...form, date: e.target.value })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Masuk">
                            <Input
                                className="h-11"
                                type="time"
                                value={form.clock_in}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        clock_in: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label="Pulang"
                            hint="Kosongkan bila belum pulang."
                        >
                            <Input
                                className="h-11"
                                type="time"
                                value={form.clock_out}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        clock_out: e.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Alasan koreksi (wajib)">
                        <Input
                            className="h-11"
                            value={form.note}
                            placeholder="mis. lupa absen pulang"
                            onChange={(e) =>
                                setForm({ ...form, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={
                            !form.user_id || !form.clock_in || !form.note.trim()
                        }
                        onClick={() => {
                            const body = {
                                user_id: form.user_id,
                                date: form.date,
                                clock_in: form.clock_in,
                                clock_out: form.clock_out || null,
                                note: form.note,
                            };
                            const o = {
                                preserveScroll: true,
                                onSuccess: () => setForm(null),
                            };
                            if (form.id) {
                                router.put(
                                    `/attendance/records/${form.id}`,
                                    body,
                                    o,
                                );
                            } else {
                                router.post('/attendance/records', body, o);
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}
        </>
    );
}
