import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
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
};
type Purchase = {
    id: number;
    supplier: string | null;
    purchased_on: string;
    note: string | null;
    total: number;
    items_count: number;
    user: string | null;
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
    counts: Count[];
    ingredients: Ingredient[];
    purchases: Purchase[];
    movements: Movement[];
};
type Line = {
    ingredient_id: number | '';
    qty: number | '';
    unit_cost: number | '';
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
        items: Line[];
    } | null>(null);
    const nameOf = (id: number) =>
        ingredients.find((i) => i.id === id)?.name ?? '';
    const opts = { preserveScroll: true };
    const totalValue = ingredients.reduce((s, i) => s + i.value, 0);
    const low = ingredients.filter((i) => i.low);
    const buyTotal =
        buy?.items.reduce(
            (s, l) => s + Number(l.qty || 0) * Number(l.unit_cost || 0),
            0,
        ) ?? 0;

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
                                        items: [
                                            {
                                                ingredient_id: '',
                                                qty: '',
                                                unit_cost: '',
                                            },
                                        ],
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
                        { id: 'purchases', label: 'Pembelian' },
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
                                    })
                                }
                            >
                                <Plus /> Tambah bahan
                            </Button>
                        </div>
                        <ul className="grid gap-2">
                            {ingredients.map((i) => (
                                <li
                                    key={i.id}
                                    className="flex flex-wrap items-center gap-2 rounded-xl border bg-card p-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold">
                                            {i.name}{' '}
                                            {!i.is_active && (
                                                <span className="text-xs font-normal text-muted-foreground">
                                                    (nonaktif)
                                                </span>
                                            )}
                                        </p>
                                        <p
                                            className={
                                                i.low
                                                    ? 'text-sm font-medium text-destructive'
                                                    : 'text-sm text-muted-foreground'
                                            }
                                        >
                                            {num(i.stock)} {i.unit}
                                            {i.low && ' · menipis'} ·{' '}
                                            {rupiah(i.avg_cost)}/{i.unit} ·
                                            nilai {rupiah(i.value)}
                                        </p>
                                    </div>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-10"
                                        onClick={() =>
                                            setCount({
                                                ing: i,
                                                value: '',
                                                note: '',
                                            })
                                        }
                                    >
                                        Hitung stok
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-10"
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
                                        className="h-10"
                                        onClick={() =>
                                            setEdit({
                                                id: i.id,
                                                name: i.name,
                                                unit: i.unit,
                                                min_stock: i.min_stock,
                                                stock: '',
                                                unit_cost: '',
                                                is_active: i.is_active,
                                            })
                                        }
                                    >
                                        Ubah
                                    </Button>
                                </li>
                            ))}
                            {ingredients.length === 0 && (
                                <li className="text-sm text-muted-foreground">
                                    Belum ada bahan. Tambahkan bahan lalu isi
                                    resep di menu Resep & HPP.
                                </li>
                            )}
                        </ul>
                    </>
                )}

                {tab === 'purchases' && (
                    <div>
                        <Button
                            variant="outline"
                            className="h-11"
                            onClick={() => setImporting('purchase')}
                        >
                            <FileSpreadsheet /> Impor pembelian (Excel)
                        </Button>
                    </div>
                )}

                {tab === 'purchases' && (
                    <ResponsiveTable
                        headers={[
                            'Tanggal',
                            'Pemasok',
                            'Item',
                            'Total',
                            'Dicatat oleh',
                        ]}
                        numeric={[2, 3]}
                        rows={purchases.map((p) => [
                            p.purchased_on,
                            p.supplier ?? '-',
                            p.items_count,
                            rupiah(p.total),
                            p.user ?? '-',
                        ])}
                    />
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
                        <Field label="Satuan" hint="g, ml, pcs, ...">
                            <Input
                                className="h-11"
                                value={edit.unit}
                                maxLength={12}
                                onChange={(e) =>
                                    setEdit({ ...edit, unit: e.target.value })
                                }
                            />
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
                    className="sm:max-w-2xl"
                >
                    <div className="grid grid-cols-2 gap-2">
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
                    {buy.items.map((l, idx) => {
                        const unit = ingredients.find(
                            (i) => i.id === l.ingredient_id,
                        )?.unit;

                        return (
                            <div
                                key={idx}
                                className="grid grid-cols-[1fr_6rem_7.5rem_2.75rem] items-end gap-2"
                            >
                                <Field label={idx === 0 ? 'Bahan' : ''}>
                                    <Select
                                        value={l.ingredient_id}
                                        onChange={(e) =>
                                            setBuy({
                                                ...buy,
                                                items: buy.items.map((x, j) =>
                                                    j === idx
                                                        ? {
                                                              ...x,
                                                              ingredient_id: e
                                                                  .target.value
                                                                  ? Number(
                                                                        e.target
                                                                            .value,
                                                                    )
                                                                  : '',
                                                          }
                                                        : x,
                                                ),
                                            })
                                        }
                                    >
                                        <option value="">Pilih…</option>
                                        {ingredients
                                            .filter((i) => i.is_active)
                                            .map((i) => (
                                                <option key={i.id} value={i.id}>
                                                    {i.name}
                                                </option>
                                            ))}
                                    </Select>
                                </Field>
                                <Field
                                    label={
                                        idx === 0
                                            ? `Jumlah${unit ? ` (${unit})` : ''}`
                                            : ''
                                    }
                                >
                                    <Input
                                        className="h-11"
                                        type="number"
                                        min={0}
                                        step="any"
                                        value={l.qty}
                                        onChange={(e) =>
                                            setBuy({
                                                ...buy,
                                                items: buy.items.map((x, j) =>
                                                    j === idx
                                                        ? {
                                                              ...x,
                                                              qty:
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
                                </Field>
                                <Field label={idx === 0 ? 'Harga/satuan' : ''}>
                                    <Input
                                        className="h-11"
                                        type="number"
                                        min={0}
                                        step="any"
                                        value={l.unit_cost}
                                        onChange={(e) =>
                                            setBuy({
                                                ...buy,
                                                items: buy.items.map((x, j) =>
                                                    j === idx
                                                        ? {
                                                              ...x,
                                                              unit_cost:
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
                                </Field>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-11"
                                    aria-label="Hapus baris"
                                    disabled={buy.items.length === 1}
                                    onClick={() =>
                                        setBuy({
                                            ...buy,
                                            items: buy.items.filter(
                                                (_, j) => j !== idx,
                                            ),
                                        })
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        );
                    })}
                    <Button
                        type="button"
                        variant="outline"
                        className="h-10"
                        onClick={() =>
                            setBuy({
                                ...buy,
                                items: [
                                    ...buy.items,
                                    {
                                        ingredient_id: '',
                                        qty: '',
                                        unit_cost: '',
                                    },
                                ],
                            })
                        }
                    >
                        <Plus /> Tambah baris
                    </Button>
                    <p className="text-right text-lg font-bold tabular-nums">
                        Total {rupiah(Math.round(buyTotal))}
                    </p>
                    <Button
                        className="h-12"
                        disabled={buy.items.some(
                            (l) =>
                                l.ingredient_id === '' ||
                                !l.qty ||
                                l.unit_cost === '',
                        )}
                        onClick={() =>
                            router.post(
                                '/admin/inventory/purchases',
                                {
                                    ...buy,
                                    supplier: buy.supplier || null,
                                    note: buy.note || null,
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
