import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowDown, ArrowUp, ArrowUpDown, FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { PageHeader, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

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
        cls: 'bg-amber-100 text-amber-900',
    },
    approved: { label: 'Disetujui', cls: 'bg-emerald-100 text-emerald-900' },
    rejected: { label: 'Ditolak', cls: 'bg-red-100 text-red-900' },
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
    const shown = ingredients
        .filter((i) => i.name.toLowerCase().includes(q.toLowerCase()))
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
                        onClick={() =>
                            confirm(
                                `Kirim hitungan ${filled} bahan? Setelah dikirim tidak bisa diubah.`,
                            ) &&
                            router.post('/stock/count/submit', body(), {
                                preserveScroll: true,
                            })
                        }
                    >
                        Kirim hitungan
                    </Button>
                </div>
            </div>
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
