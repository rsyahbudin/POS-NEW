import { Head, Link, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import { Advice, PriceCard } from '@/components/pos/price-card';
import {
    RecipeLines,
    recipeCost,
    recipePayload,
    type RecipeLine,
    type RecipeVariant,
} from '@/components/pos/recipe-lines';
import {
    PageHeader,
    ResponsiveTable,
    Tabs,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

type Ingredient = { id: number; name: string; unit: string; avg_cost: number };
type Product = {
    id: number;
    name: string;
    category: string;
    price: number;
    is_active: boolean;
    cost: number;
    variants: RecipeVariant[];
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
    const [edit, setEdit] = useState<{
        kind: 'product' | 'modifier';
        id: number;
        title: string;
        variants: RecipeVariant[];
        lines: RecipeLine[];
        price: number;
    } | null>(null);
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
                    setEdit((e) => {
                        if (!e) {
                            return e;
                        }
                        if (variantId) {
                            return {
                                ...e,
                                variants: e.variants.map((v) =>
                                    v.id === variantId ? { ...v, price } : v,
                                ),
                            };
                        }

                        return { ...e, price };
                    }),
            },
        );

    const save = (close = true) => {
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
                lines: recipePayload(
                    edit.lines,
                    edit.kind === 'product',
                ),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (close) {
                        setEdit(null);
                    }
                },
            },
        );
    };

    return (
        <>
            <Head title="Resep & HPP" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Resep & HPP"
                    subtitle="Bahan tiap porsi seperti resep masak. HPP dan harga disarankan dihitung di bawah."
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
                                                price: p.price,
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
                                            price: 0,
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
                            ? 'Daftar bahan untuk 1 porsi. HPP dan harga disarankan muncul di bawah setelah bahan terisi.'
                            : 'Bahan ekstra yang terpakai tiap kali modifier ini dipilih.'
                    }
                    className="sm:max-w-2xl"
                >
                    <RecipeLines
                        lines={edit.lines}
                        onChange={(lines) => setEdit({ ...edit, lines })}
                        ingredients={ingredients}
                        variants={edit.variants}
                        showVariants={edit.kind === 'product'}
                    />
                    {edit.kind === 'product' && (
                        <div className="grid gap-2">
                            {(edit.variants.length === 0
                                ? [
                                      {
                                          key: 0 as number,
                                          name: null as string | null,
                                          price: edit.price,
                                          cost: Math.round(
                                              recipeCost(
                                                  edit.lines,
                                                  ingredients,
                                                  null,
                                              ),
                                          ),
                                      },
                                  ]
                                : edit.variants.map((v) => ({
                                      key: v.id,
                                      name: v.name,
                                      price: v.price,
                                      cost: Math.round(
                                          recipeCost(
                                              edit.lines,
                                              ingredients,
                                              v.id,
                                          ),
                                      ),
                                  }))
                            ).map((r) => (
                                <PriceCard
                                    key={r.key}
                                    title={r.name}
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
                    <Button className="h-12" onClick={() => save(true)}>
                        Simpan
                    </Button>
                </Modal>
            )}
        </>
    );
}
