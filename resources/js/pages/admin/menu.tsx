import { Head, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import {
    Field,
    MoneyInput,
    PageHeader,
    ResponsiveTable,
    Select,
    Tabs,
    TextArea,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api, ApiError } from '@/lib/api';
import { rupiah } from '@/lib/format';

type Variant = { id?: number; name: string; price: number };
type Product = {
    id: number;
    name: string;
    sku: string | null;
    description: string | null;
    category_id: number;
    station_id: number | null;
    printer_ids: number[];
    base_price: number;
    is_available: boolean;
    is_active: boolean;
    stock_qty: number | null;
    sort: number;
    image: string | null;
    variants: Variant[];
    modifier_group_ids: number[];
};
type Group = {
    id: number;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
    modifiers: { id?: number; name: string; price_delta: number }[];
};
type Props = {
    categories: { id: number; name: string; printer_ids: number[] }[];
    printers: { id: number; name: string }[];
    stations: { id: number; name: string; is_active: boolean }[];
    products: Product[];
    modifierGroups: Group[];
};

type Draft = {
    id?: number;
    name: string;
    sku: string;
    description: string;
    category_id: number | '';
    station_id: number | '';
    printer_ids: number[];
    base_price: number | '';
    stock_qty: number | '';
    is_available: boolean;
    variants: Variant[];
    modifier_group_ids: number[];
    image: File | null;
};

const blank = (cat?: number): Draft => ({
    name: '',
    sku: '',
    description: '',
    category_id: cat ?? '',
    station_id: '',
    printer_ids: [],
    base_price: '',
    stock_qty: '',
    is_available: true,
    variants: [],
    modifier_group_ids: [],
    image: null,
});

export default function MenuPage({
    categories,
    printers,
    stations,
    products,
    modifierGroups,
}: Props) {
    const [tab, setTab] = useState<
        'products' | 'categories' | 'modifiers' | 'stations'
    >('products');
    const [draft, setDraft] = useState<Draft | null>(null);
    const [importing, setImporting] = useState(false);
    const [group, setGroup] = useState<Group | null>(null);
    const errors = usePage().props.errors as Record<string, string>;
    const [local, setLocal] = useState<Record<number, boolean>>({});
    const [show, setShow] = useState<'all' | 'active' | 'inactive'>('all');
    const [q, setQ] = useState('');
    const shown = products.filter(
        (p) =>
            (show === 'all' || (show === 'active') === p.is_active) &&
            (!q.trim() ||
                p.name.toLowerCase().includes(q.trim().toLowerCase())),
    );

    const save = () => {
        if (!draft) return;
        const body: Record<
            string,
            string | number | boolean | File | null | Variant[] | number[]
        > = {
            name: draft.name,
            sku: draft.sku || null,
            description: draft.description || null,
            category_id: draft.category_id,
            station_id: draft.station_id || null,
            base_price: draft.base_price === '' ? 0 : draft.base_price,
            stock_qty: draft.stock_qty === '' ? null : draft.stock_qty,
            is_available: draft.is_available ? 1 : 0,
            variants: draft.variants,
            modifier_group_ids: draft.modifier_group_ids,
            printer_ids: draft.printer_ids,
        };
        if (draft.image) body.image = draft.image;
        router.post(
            draft.id ? `/admin/products/${draft.id}` : '/admin/products',
            body,
            {
                forceFormData: true,
                preserveScroll: true,
                onSuccess: () => setDraft(null),
            },
        );
    };

    const toggle = async (p: Product) => {
        const next = !(local[p.id] ?? p.is_available);
        setLocal((l) => ({ ...l, [p.id]: next }));
        try {
            await api('POST', `/pos/products/${p.id}/availability`, {
                is_available: next,
            });
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : 'Gagal');
            setLocal((l) => ({ ...l, [p.id]: !next }));
        }
    };

    return (
        <>
            <Head title="Menu" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Menu"
                    actions={
                        tab === 'products' ? (
                            <>
                                <Button
                                    variant="outline"
                                    className="h-11"
                                    onClick={() => setImporting(true)}
                                >
                                    <FileSpreadsheet /> Impor Excel
                                </Button>
                                <Button
                                    className="h-11"
                                    onClick={() =>
                                        setDraft(blank(categories[0]?.id))
                                    }
                                >
                                    <Plus /> Produk
                                </Button>
                            </>
                        ) : tab === 'modifiers' ? (
                            <Button
                                className="h-11"
                                onClick={() =>
                                    setGroup({
                                        id: 0,
                                        name: '',
                                        min_select: 0,
                                        max_select: 1,
                                        is_required: false,
                                        modifiers: [
                                            { name: '', price_delta: 0 },
                                        ],
                                    })
                                }
                            >
                                <Plus /> Grup modifier
                            </Button>
                        ) : undefined
                    }
                />
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'products', label: 'Produk' },
                        { id: 'categories', label: 'Kategori' },
                        { id: 'modifiers', label: 'Modifier' },
                        { id: 'stations', label: 'Stasiun dapur' },
                    ]}
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                {tab === 'products' && (
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            type="search"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                            placeholder="Cari menu"
                            aria-label="Cari menu"
                            className="h-11 min-w-48 flex-1 rounded-lg border bg-background px-3 text-sm"
                        />
                        <select
                            value={show}
                            onChange={(e) =>
                                setShow(e.target.value as typeof show)
                            }
                            aria-label="Tampilkan"
                            className="h-11 rounded-lg border bg-background px-3 text-sm"
                        >
                            <option value="all">Semua</option>
                            <option value="active">Aktif</option>
                            <option value="inactive">Nonaktif</option>
                        </select>
                    </div>
                )}
                {tab === 'products' && (
                    <ResponsiveTable
                        headers={[
                            'Menu',
                            'Kategori',
                            'SKU',
                            'Harga',
                            'Stok porsi',
                            'Status',
                            'Aksi',
                        ]}
                        numeric={[3, 4]}
                        empty="Belum ada menu."
                        rows={shown.map((p) => {
                            const on = local[p.id] ?? p.is_available;

                            return [
                                <span
                                    key={p.id}
                                    className="flex items-center gap-2"
                                >
                                    {p.image && (
                                        <img
                                            src={p.image}
                                            alt=""
                                            className="size-10 rounded-lg object-cover"
                                        />
                                    )}
                                    <span>
                                        <span className="font-medium">
                                            {p.name}
                                        </span>
                                        {p.variants.length > 0 && (
                                            <span className="block text-xs text-muted-foreground">
                                                {p.variants.length} varian
                                            </span>
                                        )}
                                    </span>
                                </span>,
                                categories.find((c) => c.id === p.category_id)
                                    ?.name ?? '-',
                                p.sku ?? '-',
                                rupiah(p.base_price),
                                p.stock_qty ?? '∞',
                                !p.is_active ? (
                                    <span
                                        key="n"
                                        className="font-semibold text-muted-foreground"
                                    >
                                        NONAKTIF
                                    </span>
                                ) : on ? (
                                    <span
                                        key="s"
                                        className="text-emerald-700 dark:text-emerald-400"
                                    >
                                        Tersedia
                                    </span>
                                ) : (
                                    <span
                                        key="h"
                                        className="font-semibold text-destructive"
                                    >
                                        HABIS
                                    </span>
                                ),
                                <span
                                    key="a"
                                    className="flex flex-wrap justify-end gap-1.5"
                                >
                                    <Button
                                        size="sm"
                                        variant={on ? 'outline' : 'destructive'}
                                        className="h-9"
                                        aria-pressed={!on}
                                        disabled={!p.is_active}
                                        onClick={() => toggle(p)}
                                    >
                                        {on ? 'Tandai habis' : 'Tersedia lagi'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-9"
                                        onClick={() =>
                                            router.post(
                                                `/admin/products/${p.id}/active`,
                                                { is_active: !p.is_active },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {p.is_active
                                            ? 'Nonaktifkan'
                                            : 'Aktifkan menu'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-9"
                                        onClick={() =>
                                            setDraft({
                                                id: p.id,
                                                name: p.name,
                                                sku: p.sku ?? '',
                                                description:
                                                    p.description ?? '',
                                                category_id: p.category_id,
                                                station_id: p.station_id ?? '',
                                                printer_ids: p.printer_ids,
                                                base_price: p.base_price,
                                                stock_qty: p.stock_qty ?? '',
                                                is_available: p.is_available,
                                                variants: p.variants,
                                                modifier_group_ids:
                                                    p.modifier_group_ids,
                                                image: null,
                                            })
                                        }
                                    >
                                        Ubah
                                    </Button>
                                    <Button
                                        size="icon"
                                        variant="ghost"
                                        className="size-9"
                                        aria-label={`Hapus ${p.name}`}
                                        onClick={() =>
                                            confirm(
                                                `Hapus ${p.name}? Order lama tetap aman.`,
                                            ) &&
                                            router.delete(
                                                `/admin/products/${p.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </span>,
                            ];
                        })}
                    />
                )}

                {tab === 'categories' && (
                    <Simple
                        items={categories}
                        addUrl="/admin/categories"
                        editUrl={(id) => `/admin/categories/${id}`}
                        delUrl={(id) => `/admin/categories/${id}`}
                        label="Kategori"
                    />
                )}
                {tab === 'categories' && printers.length > 0 && (
                    <section className="grid gap-2 rounded-xl border bg-card p-3">
                        <h2 className="font-semibold">
                            Printer tiket per kategori
                        </h2>
                        <p className="text-sm text-muted-foreground">
                            Pilih printer yang mencetak pesanan kategori ini.
                            Boleh lebih dari satu (mis. minuman tercetak di Bar
                            dan Kasir). Kosong = ikut printer station produk.
                            Satu menu bisa menimpa ini di form menu.
                        </p>
                        {categories.map((c) => (
                            <div
                                key={c.id}
                                className="flex flex-wrap items-center gap-x-4 gap-y-1 border-t pt-2"
                            >
                                <span className="w-40 font-medium">
                                    {c.name}
                                </span>
                                {printers.map((pr) => (
                                    <label
                                        key={pr.id}
                                        className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            className="size-5"
                                            checked={c.printer_ids.includes(
                                                pr.id,
                                            )}
                                            onChange={(e) =>
                                                router.post(
                                                    `/admin/categories/${c.id}/printers`,
                                                    {
                                                        printer_ids: e.target
                                                            .checked
                                                            ? [
                                                                  ...c.printer_ids,
                                                                  pr.id,
                                                              ]
                                                            : c.printer_ids.filter(
                                                                  (x) =>
                                                                      x !==
                                                                      pr.id,
                                                              ),
                                                    },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        />
                                        {pr.name}
                                    </label>
                                ))}
                            </div>
                        ))}
                    </section>
                )}
                {tab === 'stations' && (
                    <Simple
                        items={stations}
                        addUrl="/admin/stations"
                        editUrl={(id) => `/admin/stations/${id}`}
                        label="Stasiun"
                    />
                )}

                {tab === 'modifiers' && (
                    <div className="grid gap-2 sm:grid-cols-2">
                        {modifierGroups.map((g) => (
                            <div
                                key={g.id}
                                className="rounded-xl border bg-card p-3"
                            >
                                <p className="font-medium">
                                    {g.name}{' '}
                                    <span className="text-xs text-muted-foreground">
                                        · {g.is_required ? 'wajib' : 'opsional'}{' '}
                                        · {g.min_select}-{g.max_select}
                                    </span>
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {g.modifiers
                                        .map(
                                            (m) =>
                                                `${m.name}${m.price_delta ? ` (+${rupiah(m.price_delta)})` : ''}`,
                                        )
                                        .join(', ')}
                                </p>
                                <div className="mt-2 flex gap-2">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-10"
                                        onClick={() => setGroup(g)}
                                    >
                                        Ubah
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-10"
                                        onClick={() =>
                                            confirm('Hapus grup?') &&
                                            router.delete(
                                                `/admin/modifier-groups/${g.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Hapus
                                    </Button>
                                </div>
                            </div>
                        ))}
                    </div>
                )}
            </div>

            {draft && (
                <Modal
                    open
                    onClose={() => setDraft(null)}
                    title={draft.id ? 'Ubah produk' : 'Produk baru'}
                    className="sm:max-w-xl"
                >
                    <Field label="Nama" error={errors?.name}>
                        <Input
                            className="h-11"
                            value={draft.name}
                            onChange={(e) =>
                                setDraft({ ...draft, name: e.target.value })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Kategori">
                            <Select
                                value={draft.category_id}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        category_id: Number(e.target.value),
                                    })
                                }
                            >
                                {categories.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Stasiun dapur">
                            <Select
                                value={draft.station_id}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        station_id: e.target.value
                                            ? Number(e.target.value)
                                            : '',
                                    })
                                }
                            >
                                <option value="">—</option>
                                {stations.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        {printers.length > 0 && (
                            <Field
                                label="Cetak tiket di printer"
                                hint="Kosong = ikut printer kategori. Pilih lebih dari satu untuk mencetak di beberapa printer."
                            >
                                <div className="flex flex-wrap gap-2">
                                    {printers.map((pr) => (
                                        <label
                                            key={pr.id}
                                            className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm"
                                        >
                                            <input
                                                type="checkbox"
                                                className="size-5"
                                                checked={draft.printer_ids.includes(
                                                    pr.id,
                                                )}
                                                onChange={(e) =>
                                                    setDraft({
                                                        ...draft,
                                                        printer_ids: e.target
                                                            .checked
                                                            ? [
                                                                  ...draft.printer_ids,
                                                                  pr.id,
                                                              ]
                                                            : draft.printer_ids.filter(
                                                                  (x) =>
                                                                      x !==
                                                                      pr.id,
                                                              ),
                                                    })
                                                }
                                            />
                                            {pr.name}
                                        </label>
                                    ))}
                                </div>
                            </Field>
                        )}
                        <Field label="Harga dasar">
                            <MoneyInput
                                value={draft.base_price}
                                onChange={(v) =>
                                    setDraft({ ...draft, base_price: v })
                                }
                            />
                        </Field>
                        <Field label="SKU / kode" error={errors?.sku}>
                            <Input
                                className="h-11"
                                value={draft.sku}
                                onChange={(e) =>
                                    setDraft({ ...draft, sku: e.target.value })
                                }
                            />
                        </Field>
                        <Field
                            label="Stok porsi"
                            hint="Kosong = tidak terbatas"
                        >
                            <MoneyInput
                                value={draft.stock_qty}
                                onChange={(v) =>
                                    setDraft({ ...draft, stock_qty: v })
                                }
                            />
                        </Field>
                        <Field label="Foto (otomatis WebP ≤ 800 px)">
                            <input
                                type="file"
                                accept="image/*"
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        image: e.target.files?.[0] ?? null,
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Deskripsi">
                        <TextArea
                            value={draft.description}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    description: e.target.value,
                                })
                            }
                        />
                    </Field>

                    <fieldset className="grid gap-2">
                        <legend className="text-sm font-medium">
                            Varian (mengganti harga dasar)
                        </legend>
                        {draft.variants.map((v, i) => (
                            <div key={i} className="flex gap-2">
                                <Input
                                    className="h-11"
                                    placeholder="Hot / Iced"
                                    value={v.name}
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            variants: draft.variants.map(
                                                (x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              name: e.target
                                                                  .value,
                                                          }
                                                        : x,
                                            ),
                                        })
                                    }
                                />
                                <MoneyInput
                                    value={v.price}
                                    onChange={(n) =>
                                        setDraft({
                                            ...draft,
                                            variants: draft.variants.map(
                                                (x, j) =>
                                                    j === i
                                                        ? {
                                                              ...x,
                                                              price:
                                                                  n === ''
                                                                      ? 0
                                                                      : n,
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
                                    aria-label="Hapus varian"
                                    onClick={() =>
                                        setDraft({
                                            ...draft,
                                            variants: draft.variants.filter(
                                                (_, j) => j !== i,
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
                                setDraft({
                                    ...draft,
                                    variants: [
                                        ...draft.variants,
                                        {
                                            name: '',
                                            price:
                                                draft.base_price === ''
                                                    ? 0
                                                    : draft.base_price,
                                        },
                                    ],
                                })
                            }
                        >
                            + Varian
                        </Button>
                    </fieldset>

                    <fieldset className="grid gap-2 sm:grid-cols-2">
                        <legend className="mb-2 text-sm font-medium">
                            Grup modifier
                        </legend>
                        {modifierGroups.map((g) => (
                            <label
                                key={g.id}
                                className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm"
                            >
                                <input
                                    type="checkbox"
                                    className="size-5"
                                    checked={draft.modifier_group_ids.includes(
                                        g.id,
                                    )}
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            modifier_group_ids: e.target.checked
                                                ? [
                                                      ...draft.modifier_group_ids,
                                                      g.id,
                                                  ]
                                                : draft.modifier_group_ids.filter(
                                                      (x) => x !== g.id,
                                                  ),
                                        })
                                    }
                                />
                                {g.name}
                            </label>
                        ))}
                    </fieldset>
                    <label className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={draft.is_available}
                            onChange={(e) =>
                                setDraft({
                                    ...draft,
                                    is_available: e.target.checked,
                                })
                            }
                        />
                        Tersedia
                    </label>
                    <Button className="h-12" onClick={save}>
                        Simpan
                    </Button>
                </Modal>
            )}

            {group && (
                <Modal
                    open
                    onClose={() => setGroup(null)}
                    title={
                        group.id ? 'Ubah grup modifier' : 'Grup modifier baru'
                    }
                >
                    <Field label="Nama">
                        <Input
                            className="h-11"
                            value={group.name}
                            onChange={(e) =>
                                setGroup({ ...group, name: e.target.value })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Min pilihan">
                            <Input
                                className="h-11"
                                type="number"
                                min={0}
                                value={group.min_select}
                                onChange={(e) =>
                                    setGroup({
                                        ...group,
                                        min_select: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field label="Maks pilihan">
                            <Input
                                className="h-11"
                                type="number"
                                min={1}
                                value={group.max_select}
                                onChange={(e) =>
                                    setGroup({
                                        ...group,
                                        max_select: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <label className="flex min-h-11 items-center gap-2 rounded-lg border bg-card px-3 text-sm">
                        <input
                            type="checkbox"
                            className="size-5"
                            checked={group.is_required}
                            onChange={(e) =>
                                setGroup({
                                    ...group,
                                    is_required: e.target.checked,
                                })
                            }
                        />
                        Wajib dipilih
                    </label>
                    {group.modifiers.map((m, i) => (
                        <div key={i} className="flex gap-2">
                            <Input
                                className="h-11"
                                placeholder="Opsi"
                                value={m.name}
                                onChange={(e) =>
                                    setGroup({
                                        ...group,
                                        modifiers: group.modifiers.map(
                                            (x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          name: e.target.value,
                                                      }
                                                    : x,
                                        ),
                                    })
                                }
                            />
                            <Input
                                className="h-11 w-32 text-right"
                                type="number"
                                value={m.price_delta}
                                onChange={(e) =>
                                    setGroup({
                                        ...group,
                                        modifiers: group.modifiers.map(
                                            (x, j) =>
                                                j === i
                                                    ? {
                                                          ...x,
                                                          price_delta: Number(
                                                              e.target.value,
                                                          ),
                                                      }
                                                    : x,
                                        ),
                                    })
                                }
                                aria-label="Tambahan harga"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-11"
                                aria-label="Hapus opsi"
                                onClick={() =>
                                    setGroup({
                                        ...group,
                                        modifiers: group.modifiers.filter(
                                            (_, j) => j !== i,
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
                            setGroup({
                                ...group,
                                modifiers: [
                                    ...group.modifiers,
                                    { name: '', price_delta: 0 },
                                ],
                            })
                        }
                    >
                        + Opsi
                    </Button>
                    <Button
                        className="h-12"
                        onClick={() => {
                            const body = {
                                name: group.name,
                                min_select: group.min_select,
                                max_select: group.max_select,
                                is_required: group.is_required,
                                modifiers: group.modifiers.map((m) => ({
                                    name: m.name,
                                    price_delta: m.price_delta,
                                })),
                            };
                            const opts = {
                                preserveScroll: true,
                                onSuccess: () => setGroup(null),
                            };
                            if (group.id) {
                                router.put(
                                    `/admin/modifier-groups/${group.id}`,
                                    body,
                                    opts,
                                );
                            } else {
                                router.post(
                                    '/admin/modifier-groups',
                                    body,
                                    opts,
                                );
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}
            {importing && (
                <ImportDialog
                    type="products"
                    onClose={() => setImporting(false)}
                    onDone={() => router.reload()}
                />
            )}
        </>
    );
}

function Simple({
    items,
    addUrl,
    editUrl,
    delUrl,
    label,
}: {
    items: { id: number; name: string }[];
    addUrl: string;
    editUrl: (id: number) => string;
    delUrl?: (id: number) => string;
    label: string;
}) {
    const [name, setName] = useState('');

    return (
        <div className="grid max-w-xl gap-2">
            {items.map((c) => (
                <Row
                    key={c.id}
                    item={c}
                    editUrl={editUrl(c.id)}
                    delUrl={delUrl?.(c.id)}
                />
            ))}
            <div className="flex gap-2">
                <Input
                    className="h-11"
                    placeholder={`${label} baru`}
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
                <Button
                    className="h-11"
                    disabled={!name.trim()}
                    onClick={() =>
                        router.post(
                            addUrl,
                            { name },
                            {
                                preserveScroll: true,
                                onSuccess: () => setName(''),
                            },
                        )
                    }
                >
                    Tambah
                </Button>
            </div>
        </div>
    );
}

function Row({
    item,
    editUrl,
    delUrl,
}: {
    item: { id: number; name: string };
    editUrl: string;
    delUrl?: string;
}) {
    const [name, setName] = useState(item.name);

    return (
        <div className="flex gap-2">
            <Input
                className="h-11"
                value={name}
                onChange={(e) => setName(e.target.value)}
                aria-label="Nama"
            />
            <Button
                variant="outline"
                className="h-11"
                disabled={name === item.name}
                onClick={() =>
                    router.put(editUrl, { name }, { preserveScroll: true })
                }
            >
                Simpan
            </Button>
            {delUrl && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-11"
                    aria-label="Hapus"
                    onClick={() =>
                        confirm('Hapus?') &&
                        router.delete(delUrl, { preserveScroll: true })
                    }
                >
                    <Trash2 />
                </Button>
            )}
        </div>
    );
}
