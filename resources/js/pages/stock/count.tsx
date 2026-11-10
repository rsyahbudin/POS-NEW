import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, FileSpreadsheet } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import { PageHeader, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { StockShare } from '@/components/pos/stock-share';

type Ingredient = {
    id: number;
    name: string;
    unit: string;
    choices: { name: string; factor: number }[];
    counted: number | null;
    system: number | null;
};
type History = {
    id: number;
    status: string;
    at: string | null;
    lines: number;
    review_note: string | null;
};
type Props = {
    draft: { id: number; note: string | null };
    blind: boolean;
    needsApproval: boolean;
    ingredients: Ingredient[];
    history: History[];
};

const STATUS: Record<string, { label: string; cls: string }> = {
    submitted: {
        label: 'Menunggu persetujuan',
        cls: 'bg-amber-100 dark:bg-amber-950/50 dark:text-amber-200 text-amber-900',
    },
    approved: {
        label: 'Disetujui',
        cls: 'bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-200 text-emerald-900',
    },
    rejected: {
        label: 'Ditolak',
        cls: 'bg-red-100 dark:bg-red-950/50 dark:text-red-200 text-red-900',
    },
};
const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

export default function StockCount({
    draft,
    blind,
    needsApproval,
    ingredients,
    history,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [values, setValues] = useState<Record<number, string>>(
        Object.fromEntries(
            ingredients
                .filter((i) => i.counted !== null)
                .map((i) => [i.id, String(i.counted)]),
        ),
    );
    const [units, setUnits] = useState<Record<number, string>>({});
    const [note, setNote] = useState(draft.note ?? '');
    const [q, setQ] = useState('');
    const [importing, setImporting] = useState(false);
    const [onlyEmpty, setOnlyEmpty] = useState(false);
    const [reviewing, setReviewing] = useState(false);
    const [savedAt, setSavedAt] = useState<string | null>(null);
    const first = useRef(true);
    const filled = Object.values(values).filter((v) => v !== '').length;
    const [sortBy, setSortBy] = useState<{
        key: 'name' | 'system' | 'filled';
        dir: 1 | -1;
    } | null>(null);
    const toggleSort = (key: 'name' | 'system' | 'filled') =>
        setSortBy((s) =>
            s?.key !== key
                ? { key, dir: 1 }
                : s.dir === 1
                  ? { key, dir: -1 }
                  : null,
        );
    const empty = ingredients.filter(
        (i) => values[i.id] === undefined || values[i.id] === '',
    ).length;
    // difference to the system for every filled line, in the ingredient's base unit
    const diffs = ingredients
        .filter((i) => values[i.id] !== undefined && values[i.id] !== '')
        .map((i) => {
            const factor =
                i.choices.find((c) => c.name === (units[i.id] ?? i.unit))
                    ?.factor ?? 1;
            const counted = Number(values[i.id]) * factor;

            return {
                ing: i,
                counted,
                diff: i.system === null ? null : counted - i.system,
            };
        });
    const off = diffs
        .filter((d) => d.diff !== null && Math.abs(d.diff) > 0.0005)
        .sort((a, b) => Math.abs(b.diff ?? 0) - Math.abs(a.diff ?? 0));
    const shown = ingredients
        .filter((i) => i.name.toLowerCase().includes(q.toLowerCase()))
        .filter(
            (i) =>
                !onlyEmpty || values[i.id] === undefined || values[i.id] === '',
        )
        .sort((a, b) => {
            if (!sortBy) {
                return 0;
            }

            const va =
                sortBy.key === 'name'
                    ? a.name
                    : sortBy.key === 'system'
                      ? (a.system ?? 0)
                      : values[a.id]
                        ? 1
                        : 0;
            const vb =
                sortBy.key === 'name'
                    ? b.name
                    : sortBy.key === 'system'
                      ? (b.system ?? 0)
                      : values[b.id]
                        ? 1
                        : 0;

            return typeof va === 'string'
                ? va.localeCompare(String(vb), 'id') * sortBy.dir
                : ((va as number) - (vb as number)) * sortBy.dir;
        });
    const arrow = (key: 'name' | 'system' | 'filled') =>
        sortBy?.key !== key ? (
            <ArrowUpDown className="size-3.5 opacity-30" />
        ) : sortBy.dir === 1 ? (
            <ArrowUp className="size-3.5 text-primary" />
        ) : (
            <ArrowDown className="size-3.5 text-primary" />
        );
    const body = () => ({
        lines: ingredients.map((i) => ({
            ingredient_id: i.id,
            counted:
                values[i.id] === undefined || values[i.id] === ''
                    ? null
                    : Number(values[i.id]),
            unit: units[i.id] ?? i.unit,
        })),
        note: note || null,
    });

    // Typing is saved by itself a moment after the last change, so a closed page does not lose the count.
    useEffect(() => {
        if (first.current) {
            first.current = false;

            return;
        }

        const timer = setTimeout(() => {
            router.post(
                '/stock/count/save',
                { ...body(), auto: true },
                {
                    preserveScroll: true,
                    preserveState: true,
                    only: [],
                    onSuccess: () =>
                        setSavedAt(
                            new Date().toLocaleTimeString('id-ID', {
                                hour: '2-digit',
                                minute: '2-digit',
                            }),
                        ),
                },
            );
        }, 1500);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [values, units, note]);

    return (
        <>
            <Head title="Stok Opname" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Stok opname"
                    subtitle={
                        blind
                            ? 'Hitung bahan di tempat, lalu isi jumlah sebenarnya. Angka sistem disembunyikan.'
                            : 'Hitung bahan di tempat, lalu isi jumlah sebenarnya.'
                    }
                    actions={
                        <Button asChild variant="outline" className="h-11">
                            <Link href="/stock">Daftar stok</Link>
                        </Button>
                    }
                />
                <StockShare />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        className="h-11 max-w-xs"
                        placeholder="Cari bahan…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    <Button
                        type="button"
                        variant={onlyEmpty ? 'default' : 'outline'}
                        className="h-11"
                        aria-pressed={onlyEmpty}
                        onClick={() => setOnlyEmpty((v) => !v)}
                    >
                        Belum diisi ({empty})
                    </Button>
                    <Button
                        variant="outline"
                        className="h-11"
                        onClick={() => setImporting(true)}
                    >
                        <FileSpreadsheet /> Isi dari Excel
                    </Button>
                </div>
                <div className="overflow-x-auto rounded-2xl border bg-card">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/60 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 hover:text-primary"
                                        onClick={() => toggleSort('name')}
                                    >
                                        Bahan {arrow('name')}
                                    </button>
                                </th>
                                {!blind && (
                                    <th className="px-3 py-2 text-right font-medium">
                                        <button
                                            type="button"
                                            className="inline-flex flex-row-reverse items-center gap-1 hover:text-primary"
                                            onClick={() => toggleSort('system')}
                                        >
                                            Di sistem {arrow('system')}
                                        </button>
                                    </th>
                                )}
                                <th className="w-44 px-3 py-2 font-medium">
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 hover:text-primary"
                                        onClick={() => toggleSort('filled')}
                                    >
                                        Hitungan {arrow('filled')}
                                    </button>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((i) => (
                                <tr
                                    key={i.id}
                                    className={cn(
                                        'border-t',
                                        values[i.id] ? 'bg-primary/5' : '',
                                    )}
                                >
                                    <td className="px-3 py-2 font-medium">
                                        {i.name}
                                    </td>
                                    {!blind && (
                                        <td className="px-3 py-2 text-right text-muted-foreground tabular-nums">
                                            {i.system !== null
                                                ? `${num(i.system)} ${i.unit}`
                                                : '-'}
                                        </td>
                                    )}
                                    <td className="p-2">
                                        <div className="flex items-center gap-2">
                                            <Input
                                                className="h-11 text-right text-base"
                                                inputMode="decimal"
                                                type="number"
                                                min={0}
                                                step="any"
                                                placeholder="0"
                                                aria-label={`Jumlah ${i.name}`}
                                                value={values[i.id] ?? ''}
                                                onChange={(e) =>
                                                    setValues({
                                                        ...values,
                                                        [i.id]: e.target.value,
                                                    })
                                                }
                                            />
                                            {i.choices.length > 1 ? (
                                                <Select
                                                    aria-label={`Satuan ${i.name}`}
                                                    className="h-11 w-24 px-2"
                                                    value={
                                                        units[i.id] ?? i.unit
                                                    }
                                                    onChange={(e) =>
                                                        setUnits({
                                                            ...units,
                                                            [i.id]: e.target
                                                                .value,
                                                        })
                                                    }
                                                >
                                                    {i.choices.map((c) => (
                                                        <option
                                                            key={c.name}
                                                            value={c.name}
                                                        >
                                                            {c.name}
                                                        </option>
                                                    ))}
                                                </Select>
                                            ) : (
                                                <span className="w-9 text-xs text-muted-foreground">
                                                    {i.unit}
                                                </span>
                                            )}
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <Input
                    className="h-11"
                    placeholder="Catatan (opsional)"
                    value={note}
                    maxLength={200}
                    onChange={(e) => setNote(e.target.value)}
                />

                {history.length > 0 && (
                    <section>
                        <h2 className="mb-2 font-semibold">
                            Hitungan saya sebelumnya
                        </h2>
                        <ul className="grid gap-1 text-sm">
                            {history.map((h) => (
                                <li
                                    key={h.id}
                                    className="flex flex-wrap items-center justify-between gap-2 rounded-lg border bg-card p-2"
                                >
                                    <span>
                                        {h.at} · {h.lines} bahan
                                        {h.review_note &&
                                            h.review_note !== 'Otomatis' &&
                                            ` · ${h.review_note}`}
                                    </span>
                                    <span
                                        className={cn(
                                            'rounded px-2 py-0.5 text-xs font-medium',
                                            STATUS[h.status]?.cls,
                                        )}
                                    >
                                        {STATUS[h.status]?.label ?? h.status}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>

            <div className="sticky bottom-0 z-20 border-t bg-card px-4 py-3">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="flex-1 text-sm text-muted-foreground">
                        {filled} dari {ingredients.length} terisi
                        {needsApproval ? ' · perlu persetujuan manager' : ''}
                        {savedAt ? ` · tersimpan ${savedAt}` : ''}
                    </span>
                    <Button
                        variant="outline"
                        className="h-12"
                        onClick={() =>
                            router.post('/stock/count/save', body(), {
                                preserveScroll: true,
                            })
                        }
                    >
                        Simpan draf
                    </Button>
                    <Button
                        className="h-12"
                        disabled={filled === 0}
                        onClick={() => setReviewing(true)}
                    >
                        Periksa dan kirim
                    </Button>
                </div>
            </div>
            {reviewing && (
                <Modal
                    open
                    onClose={() => setReviewing(false)}
                    title="Periksa hitungan"
                >
                    <p className="text-sm">
                        {filled} bahan akan dikirim.
                        {empty > 0 &&
                            ` ${empty} bahan belum diisi dan tidak ikut dihitung.`}{' '}
                        Setelah dikirim tidak bisa diubah.
                    </p>
                    {!blind && (
                        <section className="grid gap-1">
                            <h3 className="text-sm font-semibold">
                                {off.length === 0
                                    ? 'Semua sama dengan sistem.'
                                    : `${off.length} bahan beda dari sistem`}
                            </h3>
                            <ul className="grid max-h-60 gap-1 overflow-y-auto text-sm">
                                {off.map((d) => (
                                    <li
                                        key={d.ing.id}
                                        className="flex items-center justify-between gap-2 rounded-lg border p-2"
                                    >
                                        <span className="font-medium">
                                            {d.ing.name}
                                        </span>
                                        <span
                                            className={cn(
                                                'tabular-nums',
                                                (d.diff ?? 0) < 0
                                                    ? 'text-destructive'
                                                    : 'text-emerald-700 dark:text-emerald-400',
                                            )}
                                        >
                                            {(d.diff ?? 0) > 0 ? '+' : ''}
                                            {num(d.diff ?? 0)} {d.ing.unit}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                    <Button
                        className="h-12"
                        onClick={() =>
                            router.post('/stock/count/submit', body(), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setReviewing(false);
                                    // the sent count is done: start clean and do not autosave the old numbers again
                                    first.current = true;
                                    setValues({});
                                    setUnits({});
                                    setNote('');
                                },
                            })
                        }
                    >
                        Kirim hitungan
                    </Button>
                    <Button
                        variant="ghost"
                        className="h-11"
                        onClick={() => setReviewing(false)}
                    >
                        Kembali
                    </Button>
                </Modal>
            )}
            {importing && (
                <ImportDialog
                    type="count"
                    onClose={() => setImporting(false)}
                    onDone={() => window.location.reload()}
                />
            )}
        </>
    );
}
