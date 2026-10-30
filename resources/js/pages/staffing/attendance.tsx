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
import { dateId, timeId } from '@/lib/format';

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
};
type Props = {
    filters: { from: string; to: string };
    records: Rec[];
    people: { id: number; name: string }[];
    grace: number;
};

const hm = (iso: string | null) =>
    iso
        ? new Date(iso).toLocaleTimeString('en-GB', {
              hour: '2-digit',
              minute: '2-digit',
              hour12: false,
          })
        : '';

export default function Attendance({ filters, records, people, grace }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const qs = new URLSearchParams(filters).toString();
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
                    title="Absensi karyawan"
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
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                </form>

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
                        r.method_in === 'face' ? 'Wajah' : 'Manual',
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
