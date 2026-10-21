import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import {
    PurchaseLines,
    blankLine,
    purchaseItems,
    readyLines,
} from '@/components/pos/purchase-lines';
import type { PLine } from '@/components/pos/purchase-lines';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
    Tabs,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

type Ingredient = {
    id: number;
    name: string;
    unit: string;
    stock: number;
    avg_cost: number;
    min_stock: number;
    value: number;
    is_active: boolean;
    low: boolean;
    packs: { name: string; factor: number }[];
    choices: { name: string; factor: number }[];
};
type Purchase = {
    id: number;
    supplier: string | null;
    purchased_on: string;
    note: string | null;
    total: number;
    items_count: number;
    user: string | null;
    status: string;
    review_note: string | null;
    items: {
        name: string;
        unit: string;
        qty: number;
        unit_cost: number;
        total: number;
    }[];
};
type Movement = {
    id: number;
    ingredient_id: number;
    type: string;
    qty: number;
    unit_cost: number;
    note: string | null;
    user: string | null;
    at: string | null;
};
type CountLine = {
    ingredient: string;
    unit: string;
    system: number | null;
    counted: number;
    diff: number;
    value: number;
};
type Count = {
    id: number;
    status: string;
    user: string | null;
    at: string | null;
    review_note: string | null;
    reviewer: string | null;
    lines: CountLine[];
};
type Props = {
    enabled: boolean;
    baseUnits: { key: string; label: string }[];
    counts: Count[];
    ingredients: Ingredient[];
    purchases: Purchase[];
    movements: Movement[];
};

const TYPE: Record<string, string> = {
    purchase: 'Pembelian',
    sale: 'Terjual',
    void_return: 'Batal (kembali)',
    waste: 'Terbuang',
    opname: 'Hitung stok',
};
const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

export default function InventoryPage({
    enabled,
    baseUnits,
    counts,
    ingredients,
    purchases,
    movements,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [tab, setTab] = useState<
        'stock' | 'purchases' | 'counts' | 'history'
    >('stock');
    const pendingCounts = counts.filter((c) => c.status === 'submitted').length;
    const [importing, setImporting] = useState<string | null>(null);
    const [edit, setEdit] = useState<{
        id?: number;
        name: string;
        unit: string;
        min_stock: number | '';
        stock: number | '';
        unit_cost: number | '';
        is_active: boolean;
        packs: { name: string; factor: number | '' }[];
    } | null>(null);
    const [count, setCount] = useState<{
        ing: Ingredient;
        value: number | '';
        note: string;
    } | null>(null);
    const [waste, setWaste] = useState<{
        ing: Ingredient;
        qty: number | '';
        note: string;
    } | null>(null);
    const [buy, setBuy] = useState<{
        supplier: string;
        purchased_on: string;
        note: string;
        items: PLine[];
    } | null>(null);
    const nameOf = (id: number) =>
        ingredients.find((i) => i.id === id)?.name ?? '';
    const opts = { preserveScroll: true };
    const totalValue = ingredients.reduce((s, i) => s + i.value, 0);
    const low = ingredients.filter((i) => i.low);

    return (
        <>
            <Head title="Stok & Bahan" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Stok & bahan"
                    subtitle="Bahan baku, pembelian, dan stok opname. Stok berkurang otomatis dari resep saat menu terjual."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <Link href="/admin/recipes">Resep & HPP</Link>
                            </Button>
                            <Button
                                className="h-11"
                                onClick={() =>
                                    setBuy({
                                        supplier: '',
                                        purchased_on: new Date()
                                            .toISOString()
                                            .slice(0, 10),
                                        note: '',
                                        items: [blankLine()],
                                    })
                                }
                            >
                                <Plus /> Catat pembelian
                            </Button>
                        </>
                    }
                />
                {!enabled && (
                    <p
                        role="status"
                        className="rounded-2xl border bg-muted p-4 text-sm"
                    >
                        Stok bahan belum dipakai oleh penjualan. Aktifkan di{' '}
                        <Link
                            href="/admin/settings"
                            className="font-semibold underline"
                        >
                            Pengaturan › Stok & HPP
                        </Link>{' '}
                        setelah resep terisi. Data di sini bisa disiapkan lebih
                        dulu.
                    </p>
                )}
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <Stat label="Jumlah bahan" value={ingredients.length} />
                    <Stat
                        label="Nilai stok"
                        value={rupiah(totalValue)}
                        hint="Stok × biaya rata-rata"
                    />
                    <Stat
                        label="Stok menipis"
                        value={low.length}
                        hint={
                            low
                                .slice(0, 3)
                                .map((i) => i.name)
                                .join(', ') || 'Aman'
                        }
                    />
                </div>

                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'stock', label: 'Bahan' },
                        {
                            id: 'purchases',
                            label:
                                purchases.filter((p) => p.status === 'pending')
                                    .length > 0
                                    ? `Pembelian (${purchases.filter((p) => p.status === 'pending').length} menunggu)`
                                    : 'Pembelian',
                        },
                        {
                            id: 'counts',
                            label:
                                pendingCounts > 0
                                    ? `Opname staf (${pendingCounts})`
                                    : 'Opname staf',
                        },
                        { id: 'history', label: 'Riwayat stok' },
                    ]}
                />

                {tab === 'stock' && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => setImporting('ingredients')}
                            >
                                <FileSpreadsheet /> Impor bahan (Excel)
                            </Button>
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() =>
                                    setEdit({
                                        name: '',
                                        unit: 'g',
                                        min_stock: '',
                                        stock: '',
                                        unit_cost: '',
                                        is_active: true,
                                        packs: [],
                                    })
                                }
                            >
                                <Plus /> Tambah bahan
                            </Button>
                        </div>
                        <ResponsiveTable
                            headers={[
                                'Bahan',
                                'Stok',
                                'Biaya rata-rata',
                                'Nilai stok',
                                'Aksi',
                            ]}
                            numeric={[1, 2, 3]}
                            empty="Belum ada bahan. Tambahkan bahan lalu isi resep di menu Resep & HPP."
                            rows={ingredients.map((i) => [
                                <span key={i.id} className="font-medium">
                                    {i.name}
                                    {!i.is_active && (
                                        <span className="ml-1 text-xs font-normal text-muted-foreground">
                                            (nonaktif)
                                        </span>
                                    )}
                                </span>,
                                <span
                                    key="s"
                                    className={
                                        i.low
                                            ? 'font-semibold text-destructive'
                                            : ''
                                    }
                                >
                                    {num(i.stock)} {i.unit}
                                    {i.low && ' · menipis'}
                                </span>,
                                `${rupiah(i.avg_cost)}/${i.unit}`,
                                rupiah(i.value),
                                <span
                                    key="a"
                                    className="flex flex-wrap justify-end gap-1.5"
                                >
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-9"
                                        onClick={() =>
                                            setCount({
                                                ing: i,
                                                value: '',
                                                note: '',
                                            })
                                        }
                                    >
                                        Hitung
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-9"
                                        onClick={() =>
                                            setWaste({
                                                ing: i,
                                                qty: '',
                                                note: '',
                                            })
                                        }
                                    >
                                        Buang
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-9"
                                        onClick={() =>
                                            setEdit({
                                                id: i.id,
                                                name: i.name,
                                                unit: i.unit,
                                                min_stock: i.min_stock,
                                                stock: '',
                                                unit_cost: '',
                                                is_active: i.is_active,
                                                packs: i.packs.map((u) => ({
                                                    ...u,
                                                })),
                                            })
                                        }
                                    >
                                        Ubah
                                    </Button>
                                </span>,
                            ])}
                        />
                    </>
                )}

                {tab === 'purchases' && (
                    <>
                        <div>
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => setImporting('purchase')}
                            >
                                <FileSpreadsheet /> Impor pembelian (Excel)
                            </Button>
                        </div>
                        <ResponsiveTable
                            headers={[
                                'Tanggal',
                                'Pemasok',
                                'Dicatat oleh',
                                'Bahan',
                                'Total',
                                'Status',
                            ]}
                            numeric={[4]}
                            empty="Belum ada pembelian."
                            rows={purchases.map((p) => [
                                p.purchased_on,
                                p.supplier ?? '-',
                                p.user ?? '-',
                                <span
                                    key={p.id}
                                    className="block text-xs leading-5"
                                >
                                    {p.items.map((i, n) => (
                                        <span key={n} className="block">
                                            {i.name} {num(i.qty)} {i.unit} ×{' '}
                                            {rupiah(i.unit_cost)}
                                        </span>
                                    ))}
                                </span>,
                                rupiah(p.total),
                                p.status === 'pending' ? (
                                    <span
                                        key="a"
                                        className="flex flex-wrap justify-end gap-1.5"
                                    >
                                        <Button
                                            size="sm"
                                            className="h-9"
                                            onClick={() =>
                                                router.post(
                                                    `/admin/inventory/purchases/${p.id}/approve`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Setujui
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-9"
                                            onClick={() => {
                                                const reason =
                                                    prompt('Alasan menolak?');

                                                if (reason) {
                                                    router.post(
                                                        `/admin/inventory/purchases/${p.id}/reject`,
                                                        { reason },
                                                        opts,
                                                    );
                                                }
                                            }}
                                        >
                                            Tolak
                                        </Button>
                                    </span>
                                ) : p.status === 'rejected' ? (
                                    <span key="r" className="text-destructive">
                                        Ditolak
                                        {p.review_note
                                            ? `: ${p.review_note}`
                                            : ''}
                                    </span>
                                ) : (
                                    <span key="o" className="text-emerald-700">
                                        Masuk stok
                                    </span>
                                ),
                            ])}
                        />
                    </>
                )}

                {tab === 'counts' && (
                    <ul className="grid gap-3">
                        {counts.map((c) => (
                            <li
                                key={c.id}
                                className="grid gap-2 rounded-xl border bg-card p-3"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="font-semibold">
                                        {c.user ?? '-'} · {c.at}
                                    </p>
                                    <span
                                        className={
                                            c.status === 'submitted'
                                                ? 'rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900'
                                                : c.status === 'approved'
                                                  ? 'rounded bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-900'
                                                  : 'rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900'
                                        }
                                    >
                                        {c.status === 'submitted'
                                            ? 'Menunggu persetujuan'
                                            : c.status === 'approved'
                                              ? `Disetujui${c.reviewer ? ` oleh ${c.reviewer}` : ''}`
                                              : `Ditolak${c.review_note ? `: ${c.review_note}` : ''}`}
                                    </span>
                                </div>
                                <ResponsiveTable
                                    headers={[
                                        'Bahan',
                                        'Sistem',
                                        'Hitungan',
                                        'Selisih',
                                        'Nilai selisih',
                                    ]}
                                    numeric={[1, 2, 3, 4]}
                                    rows={c.lines.map((l) => [
                                        l.ingredient,
                                        l.system === null
                                            ? '-'
                                            : `${num(l.system)} ${l.unit}`,
                                        `${num(l.counted)} ${l.unit}`,
                                        `${l.diff > 0 ? '+' : ''}${num(l.diff)} ${l.unit}`,
                                        rupiah(l.value),
                                    ])}
                                />
                                {c.status === 'submitted' && (
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            className="h-11"
                                            onClick={() =>
                                                router.post(
                                                    `/admin/inventory/counts/${c.id}/approve`,
                                                    {},
                                                    opts,
                                                )
                                            }
                                        >
                                            Setujui & sesuaikan stok
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="h-11"
                                            onClick={() => {
                                                const reason =
                                                    prompt('Alasan menolak?');

                                                if (reason) {
                                                    router.post(
                                                        `/admin/inventory/counts/${c.id}/reject`,
                                                        { reason },
                                                        opts,
                                                    );
                                                }
                                            }}
                                        >
                                            Tolak
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                        {counts.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Belum ada hitungan dari staf.
                            </li>
                        )}
                    </ul>
                )}

                {tab === 'history' && (
                    <ResponsiveTable
                        headers={[
                            'Waktu',
                            'Bahan',
                            'Jenis',
                            'Jumlah',
                            'Oleh / catatan',
                        ]}
                        numeric={[3]}
                        rows={movements.map((m) => [
                            m.at ?? '',
                            nameOf(m.ingredient_id),
                            TYPE[m.type] ?? m.type,
                            `${m.qty > 0 ? '+' : ''}${num(m.qty)}`,
                            [m.user, m.note].filter(Boolean).join(' · ') || '-',
                        ])}
                    />
                )}
            </div>

            {importing && (
                <ImportDialog
                    type={importing}
                    onClose={() => setImporting(null)}
                    onDone={() => router.reload()}
                />
            )}

            {edit && (
                <Modal
                    open
                    onClose={() => setEdit(null)}
                    title={edit.id ? 'Ubah bahan' : 'Tambah bahan'}
                >
                    <Field label="Nama bahan" error={errors?.name}>
                        <Input
                            className="h-11"
                            value={edit.name}
                            placeholder="mis. Biji kopi"
                            onChange={(e) =>
                                setEdit({ ...edit, name: e.target.value })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field
                            label="Satuan dasar"
                            hint="Dipakai stok dan resep. Pilih satuan terkecil (sirup = ml)."
                        >
                            <Select
                                value={edit.unit}
                                onChange={(e) =>
                                    setEdit({ ...edit, unit: e.target.value })
                                }
                            >
                                {[
                                    ...baseUnits.map((u) => u.key),
                                    ...(baseUnits.some(
                                        (u) => u.key === edit.unit,
                                    )
                                        ? []
                                        : [edit.unit]),
                                ].map((k) => (
                                    <option key={k} value={k}>
                                        {baseUnits.find((u) => u.key === k)
                                            ?.label ?? k}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Batas stok menipis">
                            <Input
                                className="h-11"
                                type="number"
                                min={0}
                                value={edit.min_stock}
                                onChange={(e) =>
                                    setEdit({
                                        ...edit,
                                        min_stock:
                                            e.target.value === ''
                                                ? ''
                                                : Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                    </div>
                    {!edit.id && (
                        <div className="grid grid-cols-2 gap-2">
                            <Field label="Stok awal">
                                <Input
                                    className="h-11"
                                    type="number"
                                    min={0}
                                    value={edit.stock}
                                    onChange={(e) =>
                                        setEdit({
                                            ...edit,
                                            stock:
                                                e.target.value === ''
                                                    ? ''
                                                    : Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Harga per satuan (Rp)">
                                <Input
                                    className="h-11"
                                    type="number"
                                    min={0}
                                    step="any"
                                    value={edit.unit_cost}
                                    onChange={(e) =>
                                        setEdit({
                                            ...edit,
                                            unit_cost:
                                                e.target.value === ''
                                                    ? ''
                                                    : Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                        </div>
                    )}
                    <div className="grid gap-2 rounded-lg border p-3">
                        <p className="text-sm font-medium">
                            Kemasan beli (opsional)
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Kemasan saat membeli atau menghitung, mis. botol =
                            750 {edit.unit}. Orang bisa memilih "botol" dan
                            sistem mengubahnya ke {edit.unit}.
                        </p>
                        {edit.packs.map((pk, idx) => (
                            <div
                                key={idx}
                                className="grid grid-cols-[1fr_8rem_auto] items-center gap-2"
                            >
                                <Input
                                    className="h-11"
                                    aria-label="Nama kemasan"
                                    placeholder="mis. botol, dus, karung"
                                    maxLength={20}
                                    value={pk.name}
                                    onChange={(e) =>
                                        setEdit({
                                            ...edit,
                                            packs: edit.packs.map((x, j) =>
                                                j === idx
                                                    ? {
                                                          ...x,
                                                          name: e.target.value,
                                                      }
                                                    : x,
                                            ),
                                        })
                                    }
                                />
                                <div className="flex items-center gap-1.5">
                                    <Input
                                        className="h-11"
                                        aria-label={`Isi dalam ${edit.unit}`}
                                        type="number"
                                        min={0}
                                        step="any"
                                        placeholder="isi"
                                        value={pk.factor}
                                        onChange={(e) =>
                                            setEdit({
                                                ...edit,
                                                packs: edit.packs.map((x, j) =>
                                                    j === idx
                                                        ? {
                                                              ...x,
                                                              factor:
                                                                  e.target
                                                                      .value ===
                                                                  ''
                                                                      ? ''
                                                                      : Number(
                                                                            e
                                                                                .target
                                                                                .value,
                                                                        ),
                                                          }
                                                        : x,
                                                ),
                                            })
                                        }
                                    />
                                    <span className="text-xs text-muted-foreground">
                                        {edit.unit}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-11"
                                    aria-label="Hapus kemasan"
                                    onClick={() =>
                                        setEdit({
                                            ...edit,
                                            packs: edit.packs.filter(
                                                (_, j) => j !== idx,
                                            ),
                                        })
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        ))}
                        {edit.packs.length < 6 && (
                            <div>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10"
                                    onClick={() =>
                                        setEdit({
                                            ...edit,
                                            packs: [
                                                ...edit.packs,
                                                { name: '', factor: '' },
                                            ],
                                        })
                                    }
                                >
                                    <Plus /> Tambah kemasan
                                </Button>
                            </div>
                        )}
                    </div>
                    {edit.id && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={edit.is_active}
                                onChange={(e) =>
                                    setEdit({
                                        ...edit,
                                        is_active: e.target.checked,
                                    })
                                }
                            />
                            Aktif
                        </label>
                    )}
                    <Button
                        className="h-12"
                        disabled={
                            edit.name.trim() === '' || edit.unit.trim() === ''
                        }
                        onClick={() => {
                            const body = {
                                ...edit,
                                min_stock: edit.min_stock || 0,
                                packs: edit.packs.filter(
                                    (pk) =>
                                        pk.name.trim() !== '' &&
                                        Number(pk.factor) > 0,
                                ),
                            };
                            const o = {
                                ...opts,
                                onSuccess: () => setEdit(null),
                            };

                            if (edit.id) {
                                router.put(
                                    `/admin/inventory/ingredients/${edit.id}`,
                                    body,
                                    o,
                                );
                            } else {
                                router.post(
                                    '/admin/inventory/ingredients',
                                    body,
                                    o,
                                );
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}

            {count && (
                <Modal
                    open
                    onClose={() => setCount(null)}
                    title={`Hitung stok ${count.ing.name}`}
                    description={`Di sistem: ${num(count.ing.stock)} ${count.ing.unit}`}
                >
                    <Field label={`Stok sebenarnya (${count.ing.unit})`}>
                        <Input
                            className="h-11"
                            type="number"
                            min={0}
                            step="any"
                            autoFocus
                            value={count.value}
                            onChange={(e) =>
                                setCount({
                                    ...count,
                                    value:
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                })
                            }
                        />
                    </Field>
                    <Field label="Catatan">
                        <Input
                            className="h-11"
                            value={count.note}
                            onChange={(e) =>
                                setCount({ ...count, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={count.value === ''}
                        onClick={() =>
                            router.post(
                                `/admin/inventory/ingredients/${count.ing.id}/count`,
                                {
                                    counted: count.value,
                                    note: count.note || null,
                                },
                                { ...opts, onSuccess: () => setCount(null) },
                            )
                        }
                    >
                        Sesuaikan stok
                    </Button>
                </Modal>
            )}

            {waste && (
                <Modal
                    open
                    onClose={() => setWaste(null)}
                    title={`Buang ${waste.ing.name}`}
                >
                    <Field label={`Jumlah (${waste.ing.unit})`}>
                        <Input
                            className="h-11"
                            type="number"
                            min={0}
                            step="any"
                            autoFocus
                            value={waste.qty}
                            onChange={(e) =>
                                setWaste({
                                    ...waste,
                                    qty:
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                })
                            }
                        />
                    </Field>
                    <Field label="Alasan (wajib)">
                        <Input
                            className="h-11"
                            value={waste.note}
                            placeholder="tumpah, basi, rusak"
                            onChange={(e) =>
                                setWaste({ ...waste, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={waste.qty === '' || waste.note.trim() === ''}
                        onClick={() =>
                            router.post(
                                `/admin/inventory/ingredients/${waste.ing.id}/waste`,
                                { qty: waste.qty, note: waste.note },
                                { ...opts, onSuccess: () => setWaste(null) },
                            )
                        }
                    >
                        Catat terbuang
                    </Button>
                </Modal>
            )}

            {buy && (
                <Modal
                    open
                    onClose={() => setBuy(null)}
                    title="Catat pembelian"
                    description="Stok bertambah dan biaya rata-rata bahan dihitung ulang."
                    className="sm:max-w-3xl"
                >
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Tanggal">
                            <Input
                                className="h-11"
                                type="date"
                                value={buy.purchased_on}
                                onChange={(e) =>
                                    setBuy({
                                        ...buy,
                                        purchased_on: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Pemasok">
                            <Input
                                className="h-11"
                                value={buy.supplier}
                                onChange={(e) =>
                                    setBuy({ ...buy, supplier: e.target.value })
                                }
                            />
                        </Field>
                    </div>
                    <PurchaseLines
                        ingredients={ingredients.filter((i) => i.is_active)}
                        lines={buy.items}
                        onChange={(items) => setBuy({ ...buy, items })}
                    />
                    <Button
                        className="h-12"
                        disabled={readyLines(buy.items).length === 0}
                        onClick={() =>
                            router.post(
                                '/admin/inventory/purchases',
                                {
                                    purchased_on: buy.purchased_on,
                                    supplier: buy.supplier || null,
                                    note: buy.note || null,
                                    items: purchaseItems(buy.items),
                                },
                                { ...opts, onSuccess: () => setBuy(null) },
                            )
                        }
                    >
                        Simpan pembelian
                    </Button>
                </Modal>
            )}
        </>
    );
}
