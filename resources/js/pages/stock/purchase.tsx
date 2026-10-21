import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import {
    Field,
    MoneyInput,
    PageHeader,
    ResponsiveTable,
    Select,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

type Ingredient = { id: number; name: string; unit: string };
type History = {
    id: number;
    purchased_on: string;
    supplier: string | null;
    total: number;
    status: string;
    items: number;
    review_note: string | null;
};
type Line = {
    ingredient_id: number | '';
    qty: number | '';
    total: number | '';
};

const STATUS: Record<string, { label: string; cls: string }> = {
    pending: {
        label: 'Menunggu persetujuan',
        cls: 'bg-amber-100 text-amber-900',
    },
    approved: { label: 'Masuk stok', cls: 'bg-emerald-100 text-emerald-900' },
    rejected: { label: 'Ditolak', cls: 'bg-red-100 text-red-900' },
};
const blankLine = (): Line => ({ ingredient_id: '', qty: '', total: '' });

export default function PurchasePage({
    needsApproval,
    ingredients,
    history,
}: {
    needsApproval: boolean;
    ingredients: Ingredient[];
    history: History[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [supplier, setSupplier] = useState('');
    const [lines, setLines] = useState<Line[]>([blankLine()]);
    const [importing, setImporting] = useState(false);
    const unit = (id: number | '') =>
        ingredients.find((i) => i.id === id)?.unit ?? '';
    const patch = (idx: number, p: Partial<Line>) =>
        setLines((ls) => ls.map((l, i) => (i === idx ? { ...l, ...p } : l)));
    const total = lines.reduce((s, l) => s + Number(l.total || 0), 0);
    const ready = lines.filter(
        (l) => l.ingredient_id !== '' && Number(l.qty) > 0 && l.total !== '',
    );
    const submit = () =>
        router.post(
            '/stock/purchase',
            {
                purchased_on: date,
                supplier: supplier || null,
                items: ready.map((l) => ({
                    ingredient_id: l.ingredient_id,
                    qty: l.qty,
                    unit_cost: Number(l.total) / Number(l.qty),
                })),
            },
            { preserveScroll: true, onSuccess: () => setLines([blankLine()]) },
        );

    return (
        <>
            <Head title="Belanja Bahan" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Belanja bahan"
                    subtitle="Catat bahan yang dibeli: jumlah dan total harga yang dibayar."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => setImporting(true)}
                            >
                                <FileSpreadsheet /> Isi dari Excel
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <Link href="/stock">Daftar stok</Link>
                            </Button>
                        </>
                    }
                />
                {needsApproval && (
                    <p
                        role="status"
                        className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        Stok baru bertambah setelah manager menyetujui catatan
                        belanja ini.
                    </p>
                )}
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Tanggal belanja">
                        <Input
                            className="h-11"
                            type="date"
                            value={date}
                            onChange={(e) => setDate(e.target.value)}
                        />
                    </Field>
                    <Field label="Toko / pemasok">
                        <Input
                            className="h-11"
                            value={supplier}
                            placeholder="mis. Pasar, Toko Maju"
                            onChange={(e) => setSupplier(e.target.value)}
                        />
                    </Field>
                </div>

                <div className="overflow-x-auto rounded-2xl border bg-card">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/60 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Bahan</th>
                                <th className="w-32 px-3 py-2 font-medium">
                                    Jumlah
                                </th>
                                <th className="w-44 px-3 py-2 font-medium">
                                    Total harga
                                </th>
                                <th className="w-12 px-1 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {lines.map((l, idx) => (
                                <tr key={idx} className="border-t align-top">
                                    <td className="min-w-48 p-2">
                                        <Select
                                            aria-label="Bahan"
                                            value={l.ingredient_id}
                                            onChange={(e) =>
                                                patch(idx, {
                                                    ingredient_id: e.target
                                                        .value
                                                        ? Number(e.target.value)
                                                        : '',
                                                })
                                            }
                                        >
                                            <option value="">
                                                Pilih bahan…
                                            </option>
                                            {ingredients.map((i) => (
                                                <option key={i.id} value={i.id}>
                                                    {i.name} ({i.unit})
                                                </option>
                                            ))}
                                        </Select>
                                    </td>
                                    <td className="p-2">
                                        <div className="flex items-center gap-1.5">
                                            <Input
                                                className="h-11"
                                                aria-label="Jumlah"
                                                type="number"
                                                min={0}
                                                step="any"
                                                inputMode="decimal"
                                                value={l.qty}
                                                onChange={(e) =>
                                                    patch(idx, {
                                                        qty:
                                                            e.target.value ===
                                                            ''
                                                                ? ''
                                                                : Number(
                                                                      e.target
                                                                          .value,
                                                                  ),
                                                    })
                                                }
                                            />
                                            <span className="w-8 text-xs text-muted-foreground">
                                                {unit(l.ingredient_id)}
                                            </span>
                                        </div>
                                    </td>
                                    <td className="p-2">
                                        <MoneyInput
                                            value={l.total}
                                            onChange={(v) =>
                                                patch(idx, { total: v })
                                            }
                                        />
                                    </td>
                                    <td className="p-1 text-center">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-11"
                                            aria-label="Hapus baris"
                                            disabled={lines.length === 1}
                                            onClick={() =>
                                                setLines((ls) =>
                                                    ls.filter(
                                                        (_, i) => i !== idx,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                        <tfoot>
                            <tr className="border-t bg-muted/40 font-semibold">
                                <td className="px-3 py-2">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        className="h-10"
                                        onClick={() =>
                                            setLines((ls) => [
                                                ...ls,
                                                blankLine(),
                                            ])
                                        }
                                    >
                                        <Plus /> Tambah baris
                                    </Button>
                                </td>
                                <td className="px-3 py-2 text-right">Total</td>
                                <td className="px-3 py-2 tabular-nums">
                                    {rupiah(total)}
                                </td>
                                <td />
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div>
                    <Button
                        className="h-12"
                        disabled={ready.length === 0}
                        onClick={submit}
                    >
                        Kirim belanja ({ready.length} bahan)
                    </Button>
                </div>

                <section className="grid gap-2">
                    <h2 className="font-semibold">Belanja saya sebelumnya</h2>
                    <ResponsiveTable
                        headers={[
                            'Tanggal',
                            'Toko / pemasok',
                            'Bahan',
                            'Total',
                            'Status',
                        ]}
                        numeric={[2, 3]}
                        empty="Belum ada catatan belanja."
                        rows={history.map((h) => [
                            h.purchased_on,
                            h.supplier ?? '-',
                            h.items,
                            rupiah(h.total),
                            <span
                                key={h.id}
                                className={cn(
                                    'rounded px-2 py-0.5 text-xs font-medium',
                                    STATUS[h.status]?.cls,
                                )}
                            >
                                {STATUS[h.status]?.label ?? h.status}
                                {h.status === 'rejected' && h.review_note
                                    ? `: ${h.review_note}`
                                    : ''}
                            </span>,
                        ])}
                    />
                </section>
            </div>

            {importing && (
                <ImportDialog
                    type="purchase"
                    onClose={() => setImporting(false)}
                    onDone={() => router.reload()}
                />
            )}
        </>
    );
}
