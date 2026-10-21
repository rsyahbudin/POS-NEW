import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { PageHeader, Select, Tabs } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

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
    ingredients,
    products,
    modifiers,
}: {
    enabled: boolean;
    ingredients: Ingredient[];
    products: Product[];
    modifiers: Modifier[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [tab, setTab] = useState<'menu' | 'modifier'>('menu');
    const [q, setQ] = useState('');
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
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Resep & HPP"
                    subtitle="Bahan yang dipakai tiap porsi. HPP dihitung dari biaya rata-rata bahan."
                    actions={
                        <Button asChild variant="outline" className="h-11">
                            <Link href="/admin/inventory">Stok & bahan</Link>
                        </Button>
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
                        <ul className="grid gap-2">
                            {shown.map((p) => (
                                <li
                                    key={p.id}
                                    className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="font-semibold">
                                            {p.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {p.category} · harga{' '}
                                            {rupiah(p.price)}
                                        </p>
                                    </div>
                                    {p.lines.length === 0 ? (
                                        <span className="text-sm text-amber-700">
                                            Belum ada resep
                                        </span>
                                    ) : (
                                        <span className="text-sm tabular-nums">
                                            HPP {rupiah(p.cost)} · margin{' '}
                                            <b>{pct(p.price, p.cost)}</b>
                                        </span>
                                    )}
                                    <Button
                                        variant="outline"
                                        className="h-10"
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
                                </li>
                            ))}
                        </ul>
                    </>
                )}

                {tab === 'modifier' && (
                    <ul className="grid gap-2">
                        {modifiers.map((m) => (
                            <li
                                key={m.id}
                                className="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold">{m.name}</p>
                                    <p className="text-sm text-muted-foreground">
                                        {m.group}
                                        {m.lines.length > 0 &&
                                            ` · ${m.lines.length} bahan`}
                                    </p>
                                </div>
                                <Button
                                    variant="outline"
                                    className="h-10"
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
                            </li>
                        ))}
                        {modifiers.length === 0 && (
                            <li className="text-sm text-muted-foreground">
                                Belum ada modifier.
                            </li>
                        )}
                    </ul>
                )}
            </div>

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
                            ? 'Jumlah bahan untuk 1 porsi. Baris dengan varian tertentu hanya dipakai saat varian itu dipesan.'
                            : 'Bahan ekstra yang terpakai tiap kali modifier ini dipilih.'
                    }
                    className="sm:max-w-2xl"
                >
                    {edit.lines.map((l, idx) => (
                        <div
                            key={idx}
                            className={
                                edit.kind === 'product' &&
                                edit.variants.length > 0
                                    ? 'grid grid-cols-[7rem_1fr_6.5rem_2.75rem] items-center gap-2'
                                    : 'grid grid-cols-[1fr_6.5rem_2.75rem] items-center gap-2'
                            }
                        >
                            {edit.kind === 'product' &&
                                edit.variants.length > 0 && (
                                    <Select
                                        aria-label="Varian"
                                        value={l.variant_id ?? ''}
                                        onChange={(e) =>
                                            setEdit({
                                                ...edit,
                                                lines: edit.lines.map((x, j) =>
                                                    j === idx
                                                        ? {
                                                              ...x,
                                                              variant_id: e
                                                                  .target.value
                                                                  ? Number(
                                                                        e.target
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
                                            <option key={v.id} value={v.id}>
                                                {v.name}
                                            </option>
                                        ))}
                                    </Select>
                                )}
                            <Select
                                aria-label="Bahan"
                                value={l.ingredient_id}
                                onChange={(e) =>
                                    setEdit({
                                        ...edit,
                                        lines: edit.lines.map((x, j) =>
                                            j === idx
                                                ? {
                                                      ...x,
                                                      ingredient_id: e.target
                                                          .value
                                                          ? Number(
                                                                e.target.value,
                                                            )
                                                          : '',
                                                  }
                                                : x,
                                        ),
                                    })
                                }
                            >
                                <option value="">Pilih bahan…</option>
                                {ingredients.map((i) => (
                                    <option key={i.id} value={i.id}>
                                        {i.name} ({i.unit})
                                    </option>
                                ))}
                            </Select>
                            <Input
                                className="h-11"
                                aria-label="Jumlah"
                                placeholder="Jumlah"
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
                                                          e.target.value === ''
                                                              ? ''
                                                              : Number(
                                                                    e.target
                                                                        .value,
                                                                ),
                                                  }
                                                : x,
                                        ),
                                    })
                                }
                            />
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
                        <p className="rounded-lg bg-muted p-3 text-sm tabular-nums">
                            HPP{' '}
                            {edit.variants.length > 0 ? 'varian' : 'per porsi'}:{' '}
                            {edit.variants.length === 0
                                ? rupiah(Math.round(cost(edit.lines, null)))
                                : edit.variants
                                      .map(
                                          (v) =>
                                              `${v.name} ${rupiah(Math.round(cost(edit.lines, v.id)))} (harga ${rupiah(v.price)})`,
                                      )
                                      .join(' · ')}
                        </p>
                    )}
                    <Button className="h-12" onClick={save}>
                        Simpan
                    </Button>
                </Modal>
            )}
        </>
    );
}
