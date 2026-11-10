import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { ImagePicker } from '@/components/pos/image-picker';
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
import { StockShare } from '@/components/pos/stock-share';

type History = {
    id: number;
    purchased_on: string;
    supplier: string | null;
    total: number;
    status: string;
    items: number;
    review_note: string | null;
    paid_from: string | null;
    receipt: string | null;
};
type Repeat = {
    supplier_id: number | null;
    on: string;
    items: { ingredient_id: number; qty: number; unit: string | null }[];
};
type Draft = {
    date: string;
    supplier: number | '';
    lines: PLine[];
    paidFrom: string;
    saved: string;
};

const STATUS: Record<string, { label: string; cls: string }> = {
    pending: {
        label: 'Menunggu persetujuan',
        cls: 'bg-amber-100 dark:bg-amber-950/50 dark:text-amber-200 text-amber-900',
    },
    approved: {
        label: 'Masuk stok',
        cls: 'bg-emerald-100 dark:bg-emerald-950/50 dark:text-emerald-200 text-emerald-900',
    },
    rejected: {
        label: 'Ditolak',
        cls: 'bg-red-100 dark:bg-red-950/50 dark:text-red-200 text-red-900',
    },
};

const PAID: Record<string, string> = {
    drawer: 'Laci kas',
    own: 'Uang sendiri',
};

export default function PurchasePage({
    needsApproval,
    ingredients,
    canCreateIngredient,
    canPayFromDrawer,
    priceAlertPercent,
    lastPrices,
    lastPurchase,
    lowIds,
    history,
    suppliers,
}: {
    needsApproval: boolean;
    ingredients: PIngredient[];
    canCreateIngredient: boolean;
    canPayFromDrawer: boolean;
    priceAlertPercent: number;
    lastPrices: Record<number, number>;
    lastPurchase: Repeat | null;
    lowIds: number[];
    history: History[];
    suppliers: { id: number; name: string }[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [supplier, setSupplier] = useState<number | ''>('');
    const [lines, setLines] = useState<PLine[]>([blankLine()]);
    const [paidFrom, setPaidFrom] = useState('');
    const [receipt, setReceipt] = useState<File | null>(null);
    const [restored, setRestored] = useState(false);
    const userId = usePage<{ auth: { user: { id: number } | null } }>().props
        .auth.user?.id;
    const draftKey = `purchase-draft-${userId ?? 0}`;
    const loaded = useRef(false);
    const [importing, setImporting] = useState(false);
    // ingredients added from the list while typing this purchase
    const [added, setAdded] = useState<PIngredient[]>([]);
    const all = [
        ...ingredients,
        ...added.filter((a) => !ingredients.some((i) => i.id === a.id)),
    ];
    const ready = readyLines(lines);

    // The unsent purchase is kept on this device, so closing the page by mistake does not lose it.
    useEffect(() => {
        try {
            const raw = localStorage.getItem(draftKey);
            const d = raw ? (JSON.parse(raw) as Draft) : null;

            if (d && d.lines.some((l) => l.ingredient_id !== '')) {
                setDate(d.date);
                setSupplier(d.supplier);
                setLines(d.lines);
                setPaidFrom(
                    canPayFromDrawer || d.paidFrom !== 'drawer'
                        ? d.paidFrom
                        : '',
                );
                setRestored(true);
            } else if (lowIds.length > 0) {
                setLines(
                    lowIds.map((id) => ({
                        ...blankLine(),
                        ingredient_id: id,
                        unit: ingredients.find((i) => i.id === id)?.unit ?? '',
                    })),
                );
            }
        } catch {
            // storage blocked: the page works without a draft
        }

        loaded.current = true;
    }, []);
    useEffect(() => {
        if (!loaded.current) {
            return;
        }

        try {
            const empty =
                lines.length === 1 &&
                lines[0].ingredient_id === '' &&
                lines[0].qty === '' &&
                lines[0].total === '';

            if (empty) {
                localStorage.removeItem(draftKey);
            } else {
                localStorage.setItem(
                    draftKey,
                    JSON.stringify({
                        date,
                        supplier,
                        lines,
                        paidFrom,
                        saved: new Date().toISOString(),
                    } satisfies Draft),
                );
            }
        } catch {
            // ignore
        }
    }, [date, supplier, lines, paidFrom, draftKey]);

    const repeatLast = () => {
        if (!lastPurchase) {
            return;
        }

        setSupplier(lastPurchase.supplier_id ?? '');
        setLines(
            lastPurchase.items.map((i) => ({
                ...blankLine(),
                ingredient_id: i.ingredient_id,
                qty: i.qty,
                unit: i.unit ?? '',
            })),
        );
        setRestored(false);
    };
    const reset = () => {
        setLines([blankLine()]);
        setReceipt(null);
        setPaidFrom('');
        setRestored(false);

        try {
            localStorage.removeItem(draftKey);
        } catch {
            // ignore
        }
    };
    const submit = () =>
        router.post(
            '/stock/purchase',
            {
                purchased_on: date,
                supplier_id: supplier || null,
                paid_from: paidFrom || null,
                receipt: receipt ?? undefined,
                items: purchaseItems(lines),
            },
            { preserveScroll: true, forceFormData: true, onSuccess: reset },
        );

    return (
        <>
            <Head title="Belanja Bahan" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
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
                <StockShare />
                {needsApproval && (
                    <p
                        role="status"
                        className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950 dark:bg-amber-950/50 dark:text-amber-200"
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

                {restored && (
                    <p
                        role="status"
                        className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted p-3 text-sm"
                    >
                        Belanja yang belum dikirim dipulihkan dari perangkat
                        ini.
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-9"
                            onClick={reset}
                        >
                            Mulai baru
                        </Button>
                    </p>
                )}
                <div className="flex flex-wrap gap-2">
                    {lastPurchase && lastPurchase.items.length > 0 && (
                        <Button
                            variant="outline"
                            className="h-11"
                            onClick={repeatLast}
                        >
                            Ulangi belanja terakhir ({lastPurchase.items.length}{' '}
                            bahan)
                        </Button>
                    )}
                </div>

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
                    ingredients={all}
                    lines={lines}
                    onChange={setLines}
                    canCreate={canCreateIngredient}
                    onCreated={(i) => setAdded((a) => [...a, i])}
                    lastPrices={lastPrices}
                    alertPercent={priceAlertPercent}
                />

                <div className="grid gap-3 sm:grid-cols-2">
                    <Field
                        label="Dibayar pakai"
                        hint={
                            canPayFromDrawer
                                ? 'Laci kas: uang keluar langsung tercatat di shift yang berjalan.'
                                : 'Hanya catatan. Untuk membayar dari laci kas, shift harus terbuka dan akunmu perlu izinnya.'
                        }
                    >
                        <Select
                            value={paidFrom}
                            onChange={(e) => setPaidFrom(e.target.value)}
                        >
                            <option value="">Tidak dicatat</option>
                            {canPayFromDrawer && (
                                <option value="drawer">Uang laci kas</option>
                            )}
                            <option value="own">
                                Uang sendiri (diganti kemudian)
                            </option>
                        </Select>
                    </Field>
                    <Field label="Foto nota (opsional)">
                        <ImagePicker
                            aspect="aspect-[4/3]"
                            previewClass="max-w-40"
                            file={receipt}
                            onChange={setReceipt}
                        />
                    </Field>
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
                            'Dibayar',
                            'Status',
                        ]}
                        numeric={[2, 3]}
                        empty="Belum ada catatan belanja."
                        rows={history.map((h) => [
                            h.purchased_on,
                            h.supplier ?? '-',
                            h.items,
                            rupiah(h.total),
                            <span key={`p${h.id}`}>
                                {h.paid_from ? PAID[h.paid_from] : '-'}
                                {h.receipt && (
                                    <>
                                        {' · '}
                                        <a
                                            className="underline"
                                            href={h.receipt}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Nota
                                        </a>
                                    </>
                                )}
                            </span>,
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
