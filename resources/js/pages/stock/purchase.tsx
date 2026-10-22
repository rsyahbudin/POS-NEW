import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import {
    PurchaseLines,
    blankLine,
    purchaseItems,
    readyLines,
} from '@/components/pos/purchase-lines';
import type { PIngredient, PLine } from '@/components/pos/purchase-lines';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

type History = {
    id: number;
    purchased_on: string;
    supplier: string | null;
    total: number;
    status: string;
    items: number;
    review_note: string | null;
};

const STATUS: Record<string, { label: string; cls: string }> = {
    pending: {
        label: 'Menunggu persetujuan',
        cls: 'bg-amber-100 text-amber-900',
    },
    approved: { label: 'Masuk stok', cls: 'bg-emerald-100 text-emerald-900' },
    rejected: { label: 'Ditolak', cls: 'bg-red-100 text-red-900' },
};

export default function PurchasePage({
    needsApproval,
    ingredients,
    history,
    suppliers,
}: {
    needsApproval: boolean;
    ingredients: PIngredient[];
    history: History[];
    suppliers: { id: number; name: string }[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [supplier, setSupplier] = useState<number | ''>('');
    const [lines, setLines] = useState<PLine[]>([blankLine()]);
    const [importing, setImporting] = useState(false);
    const ready = readyLines(lines);
    const submit = () =>
        router.post(
            '/stock/purchase',
            {
                purchased_on: date,
                supplier_id: supplier || null,
                items: purchaseItems(lines),
            },
            { preserveScroll: true, onSuccess: () => setLines([blankLine()]) },
        );

    return (
        <>
            <Head title="Belanja Bahan" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Belanja bahan"
                    subtitle="Catat bahan yang dibeli: jumlah, satuan, dan total harga yang dibayar."
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
                    <Field label="Pemasok">
                        <Select
                            value={supplier}
                            onChange={(e) =>
                                setSupplier(
                                    e.target.value
                                        ? Number(e.target.value)
                                        : '',
                                )
                            }
                        >
                            <option value="">Tanpa pemasok</option>
                            {suppliers.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                </div>

                <PurchaseLines
                    ingredients={ingredients}
                    lines={lines}
                    onChange={setLines}
                />
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
