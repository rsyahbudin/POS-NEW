import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { ImagePicker } from '@/components/pos/image-picker';
import { ImportDialog } from '@/components/pos/import-dialog';
import {
    PurchaseLines,
    blankLine,
    pickedLines,
    purchaseItems,
    readyLines,
    suggestedBuy,
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
    step: 1 | 2;
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

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

function linesFromLow(ids: number[], ingredients: PIngredient[]): PLine[] {
    return ids.map((id) => {
        const ing = ingredients.find((i) => i.id === id);
        const buy = ing ? suggestedBuy(ing) : null;

        return {
            ...blankLine(),
            ingredient_id: id,
            qty: buy?.qty ?? '',
            unit: buy?.unit ?? ing?.unit ?? '',
        };
    });
}

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
    const [step, setStep] = useState<1 | 2>(1);
    const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
    const [supplier, setSupplier] = useState<number | ''>('');
    const [lines, setLines] = useState<PLine[]>([blankLine()]);
    const [paidFrom, setPaidFrom] = useState('');
    const [receipt, setReceipt] = useState<File | null>(null);
    const [restored, setRestored] = useState(false);
    const [draftClash, setDraftClash] = useState<{
        draft: Draft;
        low: PLine[];
    } | null>(null);
    const [q, setQ] = useState('');
    const userId = usePage<{ auth: { user: { id: number } | null } }>().props
        .auth.user?.id;
    const draftKey = `purchase-draft-${userId ?? 0}`;
    const loaded = useRef(false);
    const [importing, setImporting] = useState(false);
    const [added, setAdded] = useState<PIngredient[]>([]);
    const all = [
        ...ingredients,
        ...added.filter((a) => !ingredients.some((i) => i.id === a.id)),
    ];
    const picked = pickedLines(lines);
    const ready = readyLines(lines);
    const selected = useMemo(
        () =>
            new Set(
                picked
                    .map((l) => l.ingredient_id)
                    .filter((id): id is number => id !== ''),
            ),
        [picked],
    );
    const lowList = useMemo(
        () =>
            all.filter(
                (i) =>
                    lowIds.includes(i.id) ||
                    (i.stock !== undefined &&
                        i.min_stock !== undefined &&
                        Number(i.stock) <= Number(i.min_stock)),
            ),
        [all, lowIds],
    );
    const searchable = useMemo(() => {
        const needle = q.trim().toLowerCase();

        return all
            .filter((i) => !needle || i.name.toLowerCase().includes(needle))
            .slice(0, needle ? 40 : 0);
    }, [all, q]);

    useEffect(() => {
        try {
            const raw = localStorage.getItem(draftKey);
            const d = raw ? (JSON.parse(raw) as Draft) : null;
            const hasDraft = !!(d && d.lines.some((l) => l.ingredient_id !== ''));
            const low = lowIds.length > 0 ? linesFromLow(lowIds, ingredients) : [];

            if (hasDraft && low.length > 0) {
                setDraftClash({ draft: d!, low });
            } else if (hasDraft) {
                applyDraft(d!);
            } else if (low.length > 0) {
                setLines(low);
                setStep(1);
            }
        } catch {
            // storage blocked
        }

        loaded.current = true;
        // eslint-disable-next-line react-hooks/exhaustive-deps -- load once
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

            if (empty && step === 1) {
                localStorage.removeItem(draftKey);
            } else {
                localStorage.setItem(
                    draftKey,
                    JSON.stringify({
                        step,
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
    }, [step, date, supplier, lines, paidFrom, draftKey]);

    const applyDraft = (d: Draft) => {
        setDate(d.date);
        setSupplier(d.supplier);
        setLines(d.lines);
        setPaidFrom(
            canPayFromDrawer || d.paidFrom !== 'drawer' ? d.paidFrom : '',
        );
        setStep(d.step === 2 ? 2 : 1);
        setRestored(true);
        setDraftClash(null);
    };

    const toggleIngredient = (ing: PIngredient) => {
        if (selected.has(ing.id)) {
            const next = lines.filter((l) => l.ingredient_id !== ing.id);
            setLines(next.length ? next : [blankLine()]);

            return;
        }

        const buy = suggestedBuy(ing);
        const row: PLine = {
            ...blankLine(),
            ingredient_id: ing.id,
            qty: buy?.qty ?? '',
            unit: buy?.unit ?? ing.unit,
        };
        const base =
            lines.length === 1 && lines[0].ingredient_id === '' ? [] : lines;
        setLines([...base, row]);
    };

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
        setStep(1);
    };

    const reset = () => {
        setLines([blankLine()]);
        setReceipt(null);
        setPaidFrom('');
        setRestored(false);
        setStep(1);
        setDraftClash(null);

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
                    subtitle={
                        step === 1
                            ? 'Langkah 1: pilih bahan yang dibeli.'
                            : 'Langkah 2: isi jumlah, satuan, dan total harga.'
                    }
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

                {draftClash && (
                    <p
                        role="status"
                        className="grid gap-2 rounded-lg bg-muted p-3 text-sm"
                    >
                        Ada belanja belum dikirim di perangkat ini, dan tautan
                        &quot;yang menipis&quot; juga terbuka.
                        <span className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                className="h-9"
                                onClick={() => applyDraft(draftClash.draft)}
                            >
                                Pakai draft
                            </Button>
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-9"
                                onClick={() => {
                                    setLines(draftClash.low);
                                    setStep(1);
                                    setRestored(false);
                                    setDraftClash(null);
                                }}
                            >
                                Ganti dengan yang menipis
                            </Button>
                        </span>
                    </p>
                )}

                {restored && !draftClash && (
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

                <ol className="flex items-center gap-2" aria-label="Langkah">
                    {[
                        { id: 1 as const, label: 'Pilih bahan' },
                        { id: 2 as const, label: 'Isi harga' },
                    ].map((s) => (
                        <li key={s.id} className="flex flex-1 items-center gap-2">
                            <span
                                className={cn(
                                    'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                                    step === s.id
                                        ? 'bg-primary text-primary-foreground'
                                        : step > s.id
                                          ? 'bg-primary/20 text-primary'
                                          : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {s.id}
                            </span>
                            <span
                                className={cn(
                                    'text-sm',
                                    step === s.id
                                        ? 'font-semibold'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {s.label}
                            </span>
                            {s.id === 1 && (
                                <span
                                    aria-hidden
                                    className="h-px flex-1 bg-border"
                                />
                            )}
                        </li>
                    ))}
                </ol>

                {step === 1 && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            {lastPurchase && lastPurchase.items.length > 0 && (
                                <Button
                                    variant="outline"
                                    className="h-11"
                                    onClick={repeatLast}
                                >
                                    Ulangi belanja terakhir (
                                    {lastPurchase.items.length} bahan)
                                </Button>
                            )}
                            {lowList.length > 0 && (
                                <Button
                                    variant="outline"
                                    className="h-11"
                                    onClick={() =>
                                        setLines(
                                            linesFromLow(
                                                lowList.map((i) => i.id),
                                                all,
                                            ),
                                        )
                                    }
                                >
                                    Centang yang menipis ({lowList.length})
                                </Button>
                            )}
                        </div>

                        {lowList.length > 0 && (
                            <section className="grid gap-2">
                                <h2 className="font-semibold">
                                    Stok menipis
                                </h2>
                                <ul className="grid gap-2 sm:grid-cols-2">
                                    {lowList.map((ing) => {
                                        const on = selected.has(ing.id);
                                        const buy = suggestedBuy(ing);

                                        return (
                                            <li key={ing.id}>
                                                <button
                                                    type="button"
                                                    aria-pressed={on}
                                                    onClick={() =>
                                                        toggleIngredient(ing)
                                                    }
                                                    className={cn(
                                                        'flex w-full items-start gap-3 rounded-xl border p-3 text-left transition-colors',
                                                        on
                                                            ? 'border-primary bg-primary/5'
                                                            : 'bg-card hover:bg-muted/50',
                                                    )}
                                                >
                                                    <span
                                                        aria-hidden
                                                        className={cn(
                                                            'mt-0.5 flex size-5 shrink-0 items-center justify-center rounded border text-xs',
                                                            on
                                                                ? 'border-primary bg-primary text-primary-foreground'
                                                                : 'border-muted-foreground/40',
                                                        )}
                                                    >
                                                        {on ? '✓' : ''}
                                                    </span>
                                                    <span className="min-w-0 flex-1">
                                                        <span className="font-medium">
                                                            {ing.name}
                                                        </span>
                                                        <span className="mt-0.5 block text-xs text-muted-foreground">
                                                            Stok {num(Number(ing.stock ?? 0))}{' '}
                                                            {ing.unit}
                                                            {buy
                                                                ? ` · saran beli ${num(buy.qty)} ${buy.unit}`
                                                                : ''}
                                                        </span>
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            </section>
                        )}

                        <section className="grid gap-2">
                            <h2 className="font-semibold">Tambah bahan lain</h2>
                            <div className="relative max-w-md">
                                <Search
                                    aria-hidden
                                    className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                                />
                                <Input
                                    className="h-11 pl-9"
                                    placeholder="Cari nama bahan…"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                />
                            </div>
                            {searchable.length > 0 && (
                                <ul className="grid max-h-64 gap-1 overflow-y-auto rounded-xl border p-2">
                                    {searchable.map((ing) => {
                                        const on = selected.has(ing.id);

                                        return (
                                            <li key={ing.id}>
                                                <button
                                                    type="button"
                                                    aria-pressed={on}
                                                    onClick={() =>
                                                        toggleIngredient(ing)
                                                    }
                                                    className={cn(
                                                        'flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2.5 text-left text-sm',
                                                        on
                                                            ? 'bg-primary/10 font-medium'
                                                            : 'hover:bg-muted',
                                                    )}
                                                >
                                                    <span>{ing.name}</span>
                                                    <span className="text-xs text-muted-foreground">
                                                        {on
                                                            ? 'Dipilih'
                                                            : ing.unit}
                                                    </span>
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </section>

                        {picked.length > 0 && (
                            <p className="text-sm text-muted-foreground">
                                {picked.length} bahan dipilih
                            </p>
                        )}

                        <Button
                            className="h-12"
                            disabled={picked.length === 0}
                            onClick={() => setStep(2)}
                        >
                            Lanjut isi harga ({picked.length})
                        </Button>
                    </>
                )}

                {step === 2 && (
                    <>
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
                                    onChange={(e) =>
                                        setPaidFrom(e.target.value)
                                    }
                                >
                                    <option value="">Tidak dicatat</option>
                                    {canPayFromDrawer && (
                                        <option value="drawer">
                                            Uang laci kas
                                        </option>
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
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                className="h-12"
                                onClick={() => setStep(1)}
                            >
                                Kembali
                            </Button>
                            <Button
                                className="h-12"
                                disabled={ready.length === 0}
                                onClick={submit}
                            >
                                Kirim belanja ({ready.length} bahan)
                            </Button>
                        </div>
                    </>
                )}

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
