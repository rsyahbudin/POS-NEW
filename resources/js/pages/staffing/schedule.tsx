import { Head, Link, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Copy, Plus, Settings2 } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { Field, PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type ShiftType = {
    id: number;
    name: string;
    starts_at: string;
    ends_at: string;
    is_active: boolean;
    hours: number;
};
type Entry = { type_id: number; positions: string[] };
type Person = {
    id: number;
    name: string;
    role: string | null;
    default_position: string;
    hours: number;
    shifts: Record<string, Entry[]>;
    longshift: Record<string, { hours: number; status: string }>;
};
type Props = {
    week: string;
    days: string[];
    types: ShiftType[];
    people: Person[];
    coverage: Record<string, Record<string, number>>;
    positions: { key: string; label: string }[];
    standard_hours: number;
    me: number | null;
    can: { manage: boolean; approve: boolean };
    max_per_day: number;
};

const TONES = [
    'border-sky-600/50 text-sky-700 dark:text-sky-300',
    'border-amber-600/50 text-amber-700 dark:text-amber-300',
    'border-violet-600/50 text-violet-700 dark:text-violet-300',
    'border-emerald-600/50 text-emerald-700 dark:text-emerald-300',
    'border-rose-600/50 text-rose-700 dark:text-rose-300',
];
const tone = (id: number) => TONES[id % TONES.length];
const shiftWeek = (w: string, by: number) => {
    const d = new Date(`${w}T00:00:00`);
    d.setDate(d.getDate() + by * 7);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};
const dayLabel = (d: string) =>
    new Date(`${d}T00:00:00`).toLocaleDateString('id-ID', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
const rangeLabel = (days: string[]) =>
    `${dayLabel(days[0])} – ${dayLabel(days[6])}`;

export default function Schedule({
    week,
    days,
    types,
    people,
    coverage,
    positions,
    standard_hours,
    me,
    can,
    max_per_day,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const active = types.filter((t) => t.is_active);
    const byId = Object.fromEntries(types.map((t) => [t.id, t]));
    const label = Object.fromEntries(positions.map((p) => [p.key, p.label]));
    const [cell, setCell] = useState<{
        person: Person;
        date: string;
        entries: Entry[];
    } | null>(null);
    const [typeForm, setTypeForm] = useState<{
        id?: number;
        name: string;
        starts_at: string;
        ends_at: string;
        is_active: boolean;
    } | null>(null);
    const [manageTypes, setManageTypes] = useState(false);
    const go = (w: string) =>
        router.get('/schedule', { week: w }, { preserveState: true });
    const mine = people.find((p) => p.id === me);
    const firstError = Object.values(errors ?? {})[0];

    const cellHours = cell
        ? cell.entries.reduce((s, e) => s + (byId[e.type_id]?.hours ?? 0), 0)
        : 0;
    const extra = Math.max(0, Math.floor(cellHours - standard_hours));
    const toggleType = (id: number) => {
        if (!cell) {
            return;
        }
        const on = cell.entries.some((e) => e.type_id === id);
        const dp = cell.person.default_position;
        setCell({
            ...cell,
            entries: on
                ? cell.entries.filter((e) => e.type_id !== id)
                : [
                      ...cell.entries,
                      { type_id: id, positions: dp === 'none' ? [] : [dp] },
                  ],
        });
    };
    const togglePosition = (id: number, pos: string) => {
        if (!cell) {
            return;
        }
        setCell({
            ...cell,
            entries: cell.entries.map((e) =>
                e.type_id === id
                    ? {
                          ...e,
                          positions: e.positions.includes(pos)
                              ? e.positions.filter((x) => x !== pos)
                              : [...e.positions, pos],
                      }
                    : e,
            ),
        });
    };
    const posText = (e: Entry) =>
        e.positions.map((p) => label[p] ?? p).join(' + ');

    return (
        <>
            <Head title="Jadwal Kerja" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Jadwal kerja"
                    subtitle={
                        can.manage
                            ? `Ketuk kotak untuk mengatur shift dan posisi. Sampai ${max_per_day} shift sehari; lebih dari ${standard_hours} jam sehari = longshift (perlu persetujuan MOD).`
                            : 'Jadwal kerja minggu ini.'
                    }
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/schedule/export?scope=team&week=${week}&format=pdf`}
                                >
                                    Unduh PDF minggu ini
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/schedule/export?scope=mine&month=${week.slice(0, 7)}&format=pdf`}
                                >
                                    Jadwal saya (PDF)
                                </a>
                            </Button>
                            {(can.manage || can.approve) && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-11"
                                >
                                    <Link href="/longshift">Longshift</Link>
                                </Button>
                            )}
                            {can.manage && (
                                <>
                                    <Button
                                        variant="outline"
                                        className="h-11"
                                        onClick={() => setManageTypes(true)}
                                    >
                                        <Settings2 /> Pola shift
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="h-11"
                                        onClick={() =>
                                            confirm(
                                                'Salin jadwal minggu sebelumnya ke minggu ini? Yang sudah terisi tidak diubah.',
                                            ) &&
                                            router.post(
                                                '/schedule/copy',
                                                { week },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Copy /> Salin minggu lalu
                                    </Button>
                                </>
                            )}
                        </>
                    }
                />
                {firstError && (
                    <p role="alert" className="text-sm text-destructive">
                        {firstError}
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Minggu sebelumnya"
                        onClick={() => go(shiftWeek(week, -1))}
                    >
                        <ChevronLeft />
                    </Button>
                    <h2 className="min-w-56 text-center font-semibold">
                        {rangeLabel(days)}
                    </h2>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Minggu berikutnya"
                        onClick={() => go(shiftWeek(week, 1))}
                    >
                        <ChevronRight />
                    </Button>
                    <Button
                        variant="ghost"
                        className="h-11"
                        onClick={() => router.get('/schedule')}
                    >
                        Minggu ini
                    </Button>
                </div>

                {active.length > 0 && (
                    <div className="flex flex-wrap gap-2 text-xs">
                        {active.map((t) => (
                            <span
                                key={t.id}
                                className={`rounded-full border px-2 py-0.5 ${tone(t.id)}`}
                            >
                                {t.name} {t.starts_at}–{t.ends_at}
                            </span>
                        ))}
                    </div>
                )}

                {active.length === 0 && (
                    <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {can.manage
                            ? 'Buat pola shift dulu (mis. Shift 09:00–18:00, 9 jam) lewat tombol "Pola shift".'
                            : 'MOD belum membuat pola shift.'}
                    </p>
                )}

                {mine && mine.shifts && Object.keys(mine.shifts).length > 0 && (
                    <section className="rounded-xl border bg-card p-4">
                        <h2 className="mb-2 font-semibold">Jadwal saya</h2>
                        <ul className="grid gap-1 text-sm">
                            {days.map((d) => (
                                <li
                                    key={d}
                                    className="flex justify-between gap-3 border-t py-1 first:border-t-0"
                                >
                                    <span>{dayLabel(d)}</span>
                                    <span className="text-right">
                                        {(mine.shifts[d] ?? []).length === 0 ? (
                                            <span className="text-muted-foreground">
                                                Libur
                                            </span>
                                        ) : (
                                            (mine.shifts[d] ?? [])
                                                .map(
                                                    (e) =>
                                                        `${byId[e.type_id]?.name} ${byId[e.type_id]?.starts_at}–${byId[e.type_id]?.ends_at}${posText(e) ? ` (${posText(e)})` : ''}`,
                                                )
                                                .join(', ')
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                <div className="overflow-x-auto rounded-xl border bg-card">
                    <table className="w-full min-w-[900px] border-collapse text-sm">
                        <thead>
                            <tr className="border-b text-left">
                                <th className="sticky left-0 z-10 bg-card p-3 font-medium">
                                    Karyawan
                                </th>
                                {days.map((d) => (
                                    <th
                                        key={d}
                                        className="p-3 align-top font-medium"
                                    >
                                        {dayLabel(d)}
                                        <span className="mt-1 block text-xs font-normal text-muted-foreground">
                                            {positions.map((p) => (
                                                <span
                                                    key={p.key}
                                                    className={`mr-1 inline-block ${(coverage[d]?.[p.key] ?? 0) === 0 ? 'text-red-700 dark:text-red-400' : ''}`}
                                                >
                                                    {p.label.split(' ')[0]}{' '}
                                                    {coverage[d]?.[p.key] ?? 0}
                                                </span>
                                            ))}
                                        </span>
                                    </th>
                                ))}
                                <th className="p-3 text-right font-medium">
                                    Jam
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {people.map((p) => (
                                <tr
                                    key={p.id}
                                    className={`border-b last:border-b-0 ${p.id === me ? 'bg-accent/40' : ''}`}
                                >
                                    <td className="sticky left-0 z-10 bg-card p-3 font-medium">
                                        {p.name}
                                        {p.role && (
                                            <span className="block text-xs font-normal text-muted-foreground">
                                                {p.role}
                                            </span>
                                        )}
                                    </td>
                                    {days.map((d) => {
                                        const entries = p.shifts[d] ?? [];
                                        const ls = p.longshift[d];

                                        return (
                                            <td
                                                key={d}
                                                className="p-1.5 align-top"
                                            >
                                                <button
                                                    type="button"
                                                    disabled={
                                                        !can.manage ||
                                                        active.length === 0
                                                    }
                                                    onClick={() =>
                                                        setCell({
                                                            person: p,
                                                            date: d,
                                                            entries,
                                                        })
                                                    }
                                                    aria-label={`${p.name}, ${dayLabel(d)}`}
                                                    className="grid min-h-12 w-full content-start gap-1 rounded-lg p-1 text-left enabled:hover:bg-accent disabled:cursor-default"
                                                >
                                                    {entries.length === 0 ? (
                                                        <span className="px-1 text-xs text-muted-foreground">
                                                            {can.manage
                                                                ? '+'
                                                                : '–'}
                                                        </span>
                                                    ) : (
                                                        entries.map((e) => (
                                                            <span
                                                                key={e.type_id}
                                                                className={`rounded-md border px-1.5 py-0.5 text-xs ${tone(e.type_id)}`}
                                                            >
                                                                {
                                                                    byId[
                                                                        e
                                                                            .type_id
                                                                    ]?.name
                                                                }{' '}
                                                                <span className="opacity-70">
                                                                    {
                                                                        byId[
                                                                            e
                                                                                .type_id
                                                                        ]
                                                                            ?.starts_at
                                                                    }
                                                                </span>
                                                                {posText(e) && (
                                                                    <span className="block opacity-80">
                                                                        {posText(
                                                                            e,
                                                                        )}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        ))
                                                    )}
                                                    {ls && (
                                                        <span
                                                            className={`rounded-md border px-1.5 py-0.5 text-xs font-medium ${
                                                                ls.status ===
                                                                'approved'
                                                                    ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-400'
                                                                    : ls.status ===
                                                                        'rejected'
                                                                      ? 'border-red-600/40 text-red-700 line-through dark:text-red-400'
                                                                      : 'border-amber-600/40 text-amber-700 dark:text-amber-400'
                                                            }`}
                                                        >
                                                            Longshift +
                                                            {ls.hours}j{' '}
                                                            {ls.status ===
                                                            'pending'
                                                                ? '· menunggu MOD'
                                                                : ls.status ===
                                                                    'approved'
                                                                  ? '· disetujui'
                                                                  : '· ditolak'}
                                                        </span>
                                                    )}
                                                </button>
                                            </td>
                                        );
                                    })}
                                    <td className="p-3 text-right tabular-nums">
                                        {p.hours}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>

            {cell && (
                <Modal
                    open
                    onClose={() => setCell(null)}
                    title={`${cell.person.name} · ${dayLabel(cell.date)}`}
                >
                    <p className="text-sm text-muted-foreground">
                        Pilih shift (maksimal {max_per_day}) dan posisinya. Satu
                        orang boleh memegang beberapa posisi, mis. Kasir + MOD.
                        Kosongkan semua untuk libur.
                    </p>
                    <div className="grid gap-2">
                        {active.map((t) => {
                            const entry = cell.entries.find(
                                (e) => e.type_id === t.id,
                            );
                            const full =
                                !entry && cell.entries.length >= max_per_day;

                            return (
                                <div
                                    key={t.id}
                                    className={`grid gap-2 rounded-xl border p-3 ${entry ? tone(t.id) : ''} ${full ? 'opacity-50' : ''}`}
                                >
                                    <label className="flex min-h-10 items-center gap-3">
                                        <input
                                            type="checkbox"
                                            checked={!!entry}
                                            disabled={full}
                                            onChange={() => toggleType(t.id)}
                                        />
                                        <span className="font-medium">
                                            {t.name}
                                        </span>
                                        <span className="ml-auto text-sm tabular-nums">
                                            {t.starts_at}–{t.ends_at} ·{' '}
                                            {t.hours} jam
                                        </span>
                                    </label>
                                    {entry && (
                                        <div className="flex flex-wrap gap-2">
                                            {positions.map((p) => (
                                                <Button
                                                    key={p.key}
                                                    type="button"
                                                    size="sm"
                                                    className="h-10"
                                                    aria-pressed={entry.positions.includes(
                                                        p.key,
                                                    )}
                                                    variant={
                                                        entry.positions.includes(
                                                            p.key,
                                                        )
                                                            ? 'default'
                                                            : 'outline'
                                                    }
                                                    onClick={() =>
                                                        togglePosition(
                                                            t.id,
                                                            p.key,
                                                        )
                                                    }
                                                >
                                                    {p.label}
                                                </Button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                    <p
                        className={`text-sm ${extra > 0 ? 'text-amber-700 dark:text-amber-400' : 'text-muted-foreground'}`}
                    >
                        Total {cellHours} jam.
                        {extra > 0 &&
                            (can.approve
                                ? ` Longshift +${extra} jam, langsung disetujui atas nama Anda.`
                                : ` Longshift +${extra} jam, menunggu persetujuan MOD.`)}
                    </p>
                    <Button
                        className="h-12"
                        onClick={() =>
                            router.post(
                                '/schedule/day',
                                {
                                    user_id: cell.person.id,
                                    date: cell.date,
                                    entries: cell.entries,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setCell(null),
                                },
                            )
                        }
                    >
                        Simpan
                    </Button>
                </Modal>
            )}

            {manageTypes && (
                <Modal
                    open
                    onClose={() => setManageTypes(false)}
                    title="Pola shift"
                >
                    <div className="grid gap-2">
                        {types.map((t) => (
                            <div
                                key={t.id}
                                className="flex items-center justify-between gap-3 rounded-xl border p-3"
                            >
                                <p
                                    className={
                                        t.is_active
                                            ? ''
                                            : 'text-muted-foreground'
                                    }
                                >
                                    <span className="font-medium">
                                        {t.name}
                                    </span>{' '}
                                    · {t.starts_at}–{t.ends_at} · {t.hours} jam
                                    {!t.is_active && ' · nonaktif'}
                                </p>
                                <Button
                                    variant="ghost"
                                    className="h-11"
                                    onClick={() =>
                                        setTypeForm({
                                            id: t.id,
                                            name: t.name,
                                            starts_at: t.starts_at,
                                            ends_at: t.ends_at,
                                            is_active: t.is_active,
                                        })
                                    }
                                >
                                    Ubah
                                </Button>
                            </div>
                        ))}
                        <Button
                            variant="outline"
                            className="h-11"
                            onClick={() =>
                                setTypeForm({
                                    name: '',
                                    starts_at: '09:00',
                                    ends_at: '18:00',
                                    is_active: true,
                                })
                            }
                        >
                            <Plus /> Pola shift baru
                        </Button>
                    </div>
                </Modal>
            )}

            {typeForm && (
                <Modal
                    open
                    onClose={() => setTypeForm(null)}
                    title={typeForm.id ? 'Ubah pola shift' : 'Pola shift baru'}
                >
                    <Field label="Nama">
                        <Input
                            className="h-11"
                            value={typeForm.name}
                            placeholder="mis. Shift 1"
                            onChange={(e) =>
                                setTypeForm({
                                    ...typeForm,
                                    name: e.target.value,
                                })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Mulai">
                            <Input
                                className="h-11"
                                type="time"
                                value={typeForm.starts_at}
                                onChange={(e) =>
                                    setTypeForm({
                                        ...typeForm,
                                        starts_at: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field
                            label="Selesai"
                            hint="Lebih awal dari jam mulai = lewat tengah malam."
                        >
                            <Input
                                className="h-11"
                                type="time"
                                value={typeForm.ends_at}
                                onChange={(e) =>
                                    setTypeForm({
                                        ...typeForm,
                                        ends_at: e.target.value,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    {typeForm.id && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={typeForm.is_active}
                                onChange={(e) =>
                                    setTypeForm({
                                        ...typeForm,
                                        is_active: e.target.checked,
                                    })
                                }
                            />
                            Aktif (bisa dipilih untuk jadwal baru)
                        </label>
                    )}
                    <Button
                        className="h-12"
                        disabled={!typeForm.name.trim()}
                        onClick={() => {
                            const o = {
                                preserveScroll: true,
                                onSuccess: () => setTypeForm(null),
                            };
                            if (typeForm.id) {
                                router.put(
                                    `/schedule/types/${typeForm.id}`,
                                    typeForm,
                                    o,
                                );
                            } else {
                                router.post('/schedule/types', typeForm, o);
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
