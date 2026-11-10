import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import { NewIngredientDialog } from '@/components/pos/new-ingredient';
import {
    Combobox,
    PageHeader,
    ResponsiveTable,
    Select,
    Tabs,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { foodCostPct, suggestedPrice, verdict } from '@/lib/pricing';
import { InfoTip } from '@/components/pos/info-tip';
import { cn } from '@/lib/utils';

type Ingredient = { id: number; name: string; unit: string; avg_cost: number };
type Line = {
    variant_id?: number | null;
    ingredient_id: number | '';
    qty: number | '';
};
type Variant = { id: number; name: string; price: number; cost: number };
type Product = {
    id: number;
    name: string;
    category: string;
    price: number;
    is_active: boolean;
    cost: number;
    variants: Variant[];
    lines: { variant_id: number | null; ingredient_id: number; qty: number }[];
};
type Modifier = {
    id: number;
    name: string;
    group: string;
    lines: { ingredient_id: number; qty: number }[];
};

const pct = (price: number, cost: number) =>
    price > 0 ? `${(((price - cost) / price) * 100).toFixed(0)}%` : '-';

export default function RecipesPage({
    enabled,
    targetFoodCost,
    canSetPrice,
    ingredients,
    products,
    modifiers,
}: {
    enabled: boolean;
    targetFoodCost: number;
    canSetPrice: boolean;
    ingredients: Ingredient[];
    products: Product[];
    modifiers: Modifier[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [tab, setTab] = useState<'menu' | 'modifier'>('menu');
    const [q, setQ] = useState('');
    const [importing, setImporting] = useState(false);
    const [creating, setCreating] = useState<{
        idx: number;
        name: string;
    } | null>(null);
    const [edit, setEdit] = useState<{
        kind: 'product' | 'modifier';
        id: number;
        title: string;
        variants: Variant[];
        lines: Line[];
    } | null>(null);
    const ing = (id: number | '') => ingredients.find((i) => i.id === id);
    const cost = (lines: Line[], variantId: number | null) =>
        lines
            .filter(
                (l) =>
                    l.ingredient_id !== '' &&
                    (l.variant_id == null || l.variant_id === variantId),
            )
            .reduce(
                (s, l) =>
                    s +
                    Number(l.qty || 0) * (ing(l.ingredient_id)?.avg_cost ?? 0),
                0,
            );
    const shown = products.filter((p) =>
        p.name.toLowerCase().includes(q.toLowerCase()),
    );

    const applyPrice = (
        productId: number,
        price: number,
        variantId: number | null,
    ) =>
        router.post(
            `/admin/recipes/products/${productId}/price`,
            { price, variant_id: variantId },
            {
                preserveScroll: true,
                onSuccess: () =>
                    setEdit((e) =>
                        e && variantId
                            ? {
                                  ...e,
                                  variants: e.variants.map((v) =>
                                      v.id === variantId ? { ...v, price } : v,
                                  ),
                              }
                            : e,
                    ),
            },
        );

    const save = () => {
        if (!edit) {
            return;
        }

        const url =
            edit.kind === 'product'
                ? `/admin/recipes/products/${edit.id}`
                : `/admin/recipes/modifiers/${edit.id}`;
        router.post(
            url,
            {
                lines: edit.lines
                    .filter((l) => l.ingredient_id !== '' && Number(l.qty) > 0)
                    .map((l) => ({
                        ingredient_id: l.ingredient_id,
                        qty: l.qty,
                        ...(edit.kind === 'product'
                            ? { variant_id: l.variant_id ?? null }
                            : {}),
                    })),
            },
            { preserveScroll: true, onSuccess: () => setEdit(null) },
        );
    };

    return (
        <>
            <Head title="Resep & HPP" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Resep & HPP"
                    subtitle="Bahan yang dipakai tiap porsi. HPP dihitung dari biaya rata-rata bahan."
                    actions={
                        <>
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => setImporting(true)}
                            >
                                <FileSpreadsheet /> Impor resep (Excel)
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <Link href="/admin/inventory">
                                    Stok & bahan
                                </Link>
                            </Button>
                        </>
                    }
                />
                {!enabled && (
                    <p
                        role="status"
                        className="rounded-2xl border bg-muted p-4 text-sm"
                    >
                        Stok bahan belum aktif di penjualan. Isi resep dulu,
                        lalu aktifkan di{' '}
                        <Link
                            href="/admin/settings"
                            className="font-semibold underline"
                        >
                            Pengaturan › Stok & HPP
                        </Link>
                        .
                    </p>
                )}
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'menu', label: 'Menu' },
                        { id: 'modifier', label: 'Bahan tambahan (modifier)' },
                    ]}
                />

                {tab === 'menu' && (
                    <>
                        <Input
                            className="h-11 max-w-sm"
                            placeholder="Cari menu…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                        <ResponsiveTable
                            toolbar={false}
                            headers={[
                                'Menu',
                                'Kategori',
                                'Harga',
                                'HPP',
                                'Margin',
                                'Harga disarankan',
                                'Aksi',
                            ]}
                            numeric={[2, 3, 4, 5]}
                            empty="Tidak ada menu."
                            rows={shown.map((p) => [
                                <span key={p.id} className="font-medium">
                                    {p.name}
                                </span>,
                                p.category,
                                rupiah(p.price),
                                p.lines.length === 0 ? (
                                    <span
                                        key="n"
                                        className="text-amber-700 dark:text-amber-400"
                                    >
                                        Belum ada resep
                                    </span>
                                ) : (
                                    rupiah(p.cost)
                                ),
                                p.lines.length === 0 ? (
                                    '-'
                                ) : (
                                    <b key="m">{pct(p.price, p.cost)}</b>
                                ),
                                p.lines.length === 0 ? (
                                    '-'
                                ) : (
                                    <Advice
                                        key="s"
                                        price={p.price}
                                        cost={p.cost}
                                        target={targetFoodCost}
                                    />
                                ),
                                <span key="a" className="flex justify-end">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-9"
                                        onClick={() =>
                                            setEdit({
                                                kind: 'product',
                                                id: p.id,
                                                title: p.name,
                                                variants: p.variants,
                                                lines: p.lines.map((l) => ({
                                                    ...l,
                                                })),
                                            })
                                        }
                                    >
                                        Atur resep
                                    </Button>
                                </span>,
                            ])}
                        />
                    </>
                )}

                {tab === 'modifier' && (
                    <ResponsiveTable
                        headers={['Modifier', 'Grup', 'Bahan tambahan', 'Aksi']}
                        numeric={[2]}
                        empty="Belum ada modifier."
                        rows={modifiers.map((m) => [
                            <span key={m.id} className="font-medium">
                                {m.name}
                            </span>,
                            m.group,
                            m.lines.length > 0
                                ? `${m.lines.length} bahan`
                                : '-',
                            <span key="a" className="flex justify-end">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-9"
                                    onClick={() =>
                                        setEdit({
                                            kind: 'modifier',
                                            id: m.id,
                                            title: `${m.group}: ${m.name}`,
                                            variants: [],
                                            lines: m.lines.map((l) => ({
                                                ...l,
                                            })),
                                        })
                                    }
                                >
                                    Atur bahan
                                </Button>
                            </span>,
                        ])}
                    />
                )}
            </div>

            {importing && (
                <ImportDialog
                    type="recipes"
                    onClose={() => setImporting(false)}
                    onDone={() => router.reload()}
                />
            )}

            {creating && edit && (
                <NewIngredientDialog
                    name={creating.name}
                    onClose={() => setCreating(null)}
                    onCreated={(i) => {
                        router.reload({ only: ['ingredients'] });
                        setEdit({
                            ...edit,
                            lines: edit.lines.map((x, j) =>
                                j === creating.idx
                                    ? { ...x, ingredient_id: i.id }
                                    : x,
                            ),
                        });
                    }}
                />
            )}
            {edit && (
                <Modal
                    open
                    onClose={() => setEdit(null)}
                    title={
                        edit.kind === 'product'
                            ? `Resep ${edit.title}`
                            : `Bahan tambahan ${edit.title}`
                    }
                    description={
                        edit.kind === 'product'
                            ? 'Jumlah bahan untuk 1 porsi, dalam satuan di kanan kolom jumlah (g, ml, atau pcs).'
                            : 'Bahan ekstra yang terpakai tiap kali modifier ini dipilih.'
                    }
                    className="sm:max-w-2xl"
                >
                    {edit.kind === 'product' && edit.variants.length > 0 && (
                        <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                            Menu ini punya varian
                            <InfoTip label="Info varian di resep">
                                Baris dengan varian tertentu hanya dipakai saat
                                varian itu dipesan. Pilih Semua untuk bahan yang
                                dipakai di semua varian.
                            </InfoTip>
                        </p>
                    )}
                    {edit.lines.map((l, idx) => (
                        <div key={idx} className="grid gap-1">
                            <div
                                className={
                                    edit.kind === 'product' &&
                                    edit.variants.length > 0
                                        ? 'grid grid-cols-[1fr_6.5rem_2.75rem] items-center gap-2 sm:grid-cols-[7rem_1fr_6.5rem_2.75rem]'
                                        : 'grid grid-cols-[1fr_6.5rem_2.75rem] items-center gap-2'
                                }
                            >
                                {edit.kind === 'product' &&
                                    edit.variants.length > 0 && (
                                        <div className="col-span-3 sm:col-span-1">
                                            <Select
                                                aria-label="Varian"
                                                value={l.variant_id ?? ''}
                                                onChange={(e) =>
                                                    setEdit({
                                                        ...edit,
                                                        lines: edit.lines.map(
                                                            (x, j) =>
                                                                j === idx
                                                                    ? {
                                                                          ...x,
                                                                          variant_id:
                                                                              e
                                                                                  .target
                                                                                  .value
                                                                                  ? Number(
                                                                                        e
                                                                                            .target
                                                                                            .value,
                                                                                    )
                                                                                  : null,
                                                                      }
                                                                    : x,
                                                        ),
                                                    })
                                                }
                                            >
                                                <option value="">Semua</option>
                                                {edit.variants.map((v) => (
                                                    <option
                                                        key={v.id}
                                                        value={v.id}
                                                    >
                                                        {v.name}
                                                    </option>
                                                ))}
                                            </Select>
                                        </div>
                                    )}
                                <Combobox
                                    aria-label="Bahan"
                                    placeholder="Cari bahan…"
                                    onCreate={(name) =>
                                        setCreating({ idx, name })
                                    }
                                    createLabel="Buat bahan baru"
                                    options={ingredients.map((i) => ({
                                        value: i.id,
                                        label: i.name,
                                        hint: i.unit,
                                    }))}
                                    value={l.ingredient_id}
                                    onChange={(v) =>
                                        setEdit({
                                            ...edit,
                                            lines: edit.lines.map((x, j) =>
                                                j === idx
                                                    ? {
                                                          ...x,
                                                          ingredient_id:
                                                              v === ''
                                                                  ? ''
                                                                  : Number(v),
                                                      }
                                                    : x,
                                            ),
                                        })
                                    }
                                />
                                <div className="relative">
                                    <Input
                                        className="h-11 pr-12"
                                        aria-label="Jumlah"
                                        placeholder={
                                            ing(l.ingredient_id)
                                                ? 'Jumlah'
                                                : 'Pilih bahan'
                                        }
                                        type="number"
                                        min={0}
                                        step="any"
                                        value={l.qty}
                                        onChange={(e) =>
                                            setEdit({
                                                ...edit,
                                                lines: edit.lines.map((x, j) =>
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
                                    <span
                                        aria-hidden
                                        className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-medium text-muted-foreground"
                                    >
                                        {ing(l.ingredient_id)?.unit ?? ''}
                                    </span>
                                </div>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-11"
                                    aria-label="Hapus baris"
                                    onClick={() =>
                                        setEdit({
                                            ...edit,
                                            lines: edit.lines.filter(
                                                (_, j) => j !== idx,
                                            ),
                                        })
                                    }
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                            {ing(l.ingredient_id) && Number(l.qty) > 0 && (
                                <p className="pl-1 text-xs text-muted-foreground tabular-nums">
                                    {l.qty} {ing(l.ingredient_id)?.unit} ×{' '}
                                    {rupiah(
                                        Math.round(
                                            (ing(l.ingredient_id)?.avg_cost ??
                                                0) * 100,
                                        ) / 100,
                                    )}
                                    /{ing(l.ingredient_id)?.unit} ={' '}
                                    <span className="font-medium text-foreground">
                                        {rupiah(
                                            Math.round(
                                                Number(l.qty) *
                                                    (ing(l.ingredient_id)
                                                        ?.avg_cost ?? 0),
                                            ),
                                        )}
                                    </span>
                                    {(ing(l.ingredient_id)?.avg_cost ?? 0) ===
                                        0 && ' · harga bahan belum diisi'}
                                </p>
                            )}
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        className="h-10"
                        onClick={() =>
                            setEdit({
                                ...edit,
                                lines: [
                                    ...edit.lines,
                                    {
                                        variant_id: null,
                                        ingredient_id: '',
                                        qty: '',
                                    },
                                ],
                            })
                        }
                    >
                        <Plus /> Tambah bahan
                    </Button>
                    {edit.kind === 'product' && (
                        <div className="grid gap-2">
                            {(edit.variants.length === 0
                                ? [
                                      {
                                          key: 0,
                                          name: 'Per porsi',
                                          price:
                                              products.find(
                                                  (x) => x.id === edit.id,
                                              )?.price ?? 0,
                                          cost: Math.round(
                                              cost(edit.lines, null),
                                          ),
                                      },
                                  ]
                                : edit.variants.map((v) => ({
                                      key: v.id,
                                      name: v.name,
                                      price: v.price,
                                      cost: Math.round(cost(edit.lines, v.id)),
                                  }))
                            ).map((r) => (
                                <PriceCard
                                    key={r.key}
                                    title={
                                        edit.variants.length > 0 ? r.name : null
                                    }
                                    price={r.price}
                                    cost={r.cost}
                                    target={targetFoodCost}
                                    onApply={
                                        canSetPrice
                                            ? (rec) =>
                                                  applyPrice(
                                                      edit.id,
                                                      rec,
                                                      edit.variants.length > 0
                                                          ? r.key
                                                          : null,
                                                  )
                                            : undefined
                                    }
                                />
                            ))}
                        </div>
                    )}
                    <Button className="h-12" onClick={save}>
                        Simpan
                    </Button>
                </Modal>
            )}
        </>
    );
}

const money = (n: number) => rupiah(Math.round(n));

/** Compact advice for the list: a plain verdict and the price to aim for. */
function Advice({
    price,
    cost,
    target,
}: {
    price: number;
    cost: number;
    target: number;
}) {
    const rec = suggestedPrice(cost, target);
    const v = verdict(price, cost, target);

    if (rec === null) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <span className="grid justify-items-end leading-tight">
            <b className="tabular-nums">{rupiah(rec)}</b>
            <span
                className={cn(
                    'text-xs font-medium',
                    v === 'good'
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : 'text-amber-700 dark:text-amber-400',
                )}
            >
                {price <= 0
                    ? 'Belum ada harga'
                    : v === 'good'
                      ? 'Harga sudah cukup'
                      : 'Harga terlalu rendah'}
            </span>
        </span>
    );
}

/** The whole picture for one price: cost, profit, margin, food cost, and what to charge to reach the target. */
function PriceCard({
    title,
    price,
    cost,
    target,
    onApply,
}: {
    title: string | null;
    price: number;
    cost: number;
    target: number;
    /** Takes the suggested price as the selling price. */
    onApply?: (price: number) => void;
}) {
    const rec = suggestedPrice(cost, target);
    const food = foodCostPct(price, cost);
    const v = verdict(price, cost, target);
    const profit = price - cost;

    return (
        <section className="grid gap-2 rounded-xl border bg-muted/40 p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">
                    {title ? `Varian ${title}` : 'Harga dan margin'}
                </h3>
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    Target food cost {target}%
                    <InfoTip label="Info rumus harga">
                        Harga disarankan = HPP ÷ target food cost, dibulatkan ke
                        atas per Rp500. Contoh: HPP Rp16.250 ÷ 35% = Rp46.429,
                        jadi Rp46.500. Target diubah di Pengaturan &gt; Stok
                        &amp; HPP.
                    </InfoTip>
                </span>
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 tabular-nums">
                <dt className="text-muted-foreground">HPP bahan</dt>
                <dd className="text-right font-medium">{money(cost)}</dd>
                <dt className="text-muted-foreground">Harga jual sekarang</dt>
                <dd className="text-right font-medium">{money(price)}</dd>
                <dt className="text-muted-foreground">Laba per porsi</dt>
                <dd className="text-right font-medium">
                    {cost > 0 && price > 0 ? money(profit) : '-'}
                </dd>
                <dt className="text-muted-foreground">Margin</dt>
                <dd className="text-right font-medium">
                    {cost > 0 && price > 0
                        ? `${Math.round((profit / price) * 100)}%`
                        : '-'}
                </dd>
                <dt className="text-muted-foreground">Food cost</dt>
                <dd
                    className={cn(
                        'text-right font-medium',
                        v === 'low' && 'text-amber-700 dark:text-amber-400',
                    )}
                >
                    {food === null || cost <= 0 ? '-' : `${Math.round(food)}%`}
                </dd>
            </dl>
            {rec === null ? (
                <p className="text-muted-foreground">
                    Isi bahan dan harganya dulu untuk melihat harga yang
                    disarankan.
                </p>
            ) : (
                <p
                    className={cn(
                        'rounded-lg p-2.5 font-medium',
                        v === 'good'
                            ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200'
                            : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
                    )}
                >
                    Harga disarankan {rupiah(rec)}
                    {price <= 0
                        ? ' · menu ini belum punya harga jual'
                        : v === 'good'
                          ? ` · harga sekarang sudah cukup (food cost ${Math.round(food ?? 0)}%)`
                          : ` · harga sekarang ${rupiah(price)} terlalu rendah, naikkan ${rupiah(rec - price)}`}
                </p>
            )}
            {rec !== null && onApply && rec !== price && (
                <Button
                    type="button"
                    variant={price <= 0 || v === 'low' ? 'default' : 'outline'}
                    className="h-11"
                    onClick={() => onApply(rec)}
                >
                    Pakai {rupiah(rec)} sebagai harga jual
                </Button>
            )}
        </section>
    );
}
