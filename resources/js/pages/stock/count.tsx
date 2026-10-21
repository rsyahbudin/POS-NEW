import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Ingredient = {
    id: number;
    name: string;
    unit: string;
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
    const [note, setNote] = useState(draft.note ?? '');
    const [q, setQ] = useState('');
    const filled = Object.values(values).filter((v) => v !== '').length;
    const shown = ingredients.filter((i) =>
        i.name.toLowerCase().includes(q.toLowerCase()),
    );
    const body = () => ({
        lines: ingredients.map((i) => ({
            ingredient_id: i.id,
            counted:
                values[i.id] === undefined || values[i.id] === ''
                    ? null
                    : Number(values[i.id]),
        })),
        note: note || null,
    });

    return (
        <>
            <Head title="Stok Opname" />
            <div className="grid gap-4 p-4">
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
                <Input
                    className="h-11 max-w-xs"
                    placeholder="Cari bahan…"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
                <ul className="grid gap-2">
                    {shown.map((i) => (
                        <li
                            key={i.id}
                            className={cn(
                                'flex items-center gap-3 rounded-xl border bg-card p-3',
                                values[i.id] ? 'border-primary/40' : '',
                            )}
                        >
                            <div className="min-w-0 flex-1">
                                <p className="font-semibold">{i.name}</p>
                                {i.system !== null && (
                                    <p className="text-xs text-muted-foreground">
                                        Di sistem {num(i.system)} {i.unit}
                                    </p>
                                )}
                            </div>
                            <Input
                                className="h-12 w-28 text-right text-lg"
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
                            <span className="w-10 text-sm text-muted-foreground">
                                {i.unit}
                            </span>
                        </li>
                    ))}
                </ul>
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
        </>
    );
}
