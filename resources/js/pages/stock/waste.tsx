import { Head, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import {
    Combobox,
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
    Tabs,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

type Reason = { id: number; name: string; is_active: boolean };
type Ingredient = {
    id: number;
    name: string;
    unit: string;
    choices: { name: string; factor: number }[];
};
type Product = {
    id: number;
    name: string;
    variants: { id: number; name: string }[];
};
type Recent = {
    id: number;
    at: string;
    label: string;
    qty: number;
    unit: string;
    reason: string;
    cost: number;
};

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

export default function WastePage({
    reasons,
    ingredients,
    products,
    recent,
    canManage,
}: {
    reasons: Reason[];
    ingredients: Ingredient[];
    products: Product[];
    recent: Recent[];
    canManage: boolean;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const active = reasons.filter((r) => r.is_active);
    const [tab, setTab] = useState<'ingredient' | 'product'>('ingredient');
    const [ing, setIng] = useState<{
        id: number | '';
        qty: number | '';
        unit: string;
        reason: number | '';
        note: string;
    }>({ id: '', qty: '', unit: '', reason: '', note: '' });
    const [prod, setProd] = useState<{
        id: number | '';
        variant: number | '';
        portions: number | '';
        reason: number | '';
        note: string;
    }>({ id: '', variant: '', portions: 1, reason: '', note: '' });
    const [newReason, setNewReason] = useState('');
    const picked = ingredients.find((i) => i.id === ing.id);
    const product = products.find((p) => p.id === prod.id);
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Bahan Terbuang" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Bahan terbuang (waste)"
                    subtitle="Catat bahan atau menu yang terbuang beserta alasannya. Stok berkurang dan nilainya masuk laporan waste."
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'ingredient', label: 'Bahan baku' },
                        { id: 'product', label: 'Menu jadi' },
                    ]}
                />

                {tab === 'ingredient' ? (
                    <section className="grid max-w-xl gap-3 rounded-xl border bg-card p-4">
                        <Field label="Bahan">
                            <Combobox
                                aria-label="Bahan"
                                placeholder="Cari bahan…"
                                options={ingredients.map((i) => ({
                                    value: i.id,
                                    label: i.name,
                                    hint: i.unit,
                                }))}
                                value={ing.id}
                                onChange={(v) => {
                                    const id = v === '' ? '' : Number(v);
                                    setIng({
                                        ...ing,
                                        id,
                                        unit:
                                            ingredients.find((i) => i.id === id)
                                                ?.unit ?? '',
                                    });
                                }}
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            <Field label="Jumlah">
                                <Input
                                    className="h-11"
                                    type="number"
                                    min={0}
                                    step="any"
                                    inputMode="decimal"
                                    value={ing.qty}
                                    onChange={(e) =>
                                        setIng({
                                            ...ing,
                                            qty:
                                                e.target.value === ''
                                                    ? ''
                                                    : Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                            <Field label="Satuan">
                                <Select
                                    value={ing.unit}
                                    disabled={!picked}
                                    onChange={(e) =>
                                        setIng({ ...ing, unit: e.target.value })
                                    }
                                >
                                    {picked?.choices.map((c) => (
                                        <option key={c.name} value={c.name}>
                                            {c.name}
                                        </option>
                                    ))}
                                </Select>
                            </Field>
                        </div>
                        <Field label="Alasan">
                            <Select
                                value={ing.reason}
                                onChange={(e) =>
                                    setIng({
                                        ...ing,
                                        reason: e.target.value
                                            ? Number(e.target.value)
                                            : '',
                                    })
                                }
                            >
                                <option value="">Pilih alasan…</option>
                                {active.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Catatan (opsional)">
                            <Input
                                className="h-11"
                                value={ing.note}
                                maxLength={200}
                                onChange={(e) =>
                                    setIng({ ...ing, note: e.target.value })
                                }
                            />
                        </Field>
                        <Button
                            className="h-12"
                            disabled={
                                ing.id === '' || !ing.qty || ing.reason === ''
                            }
                            onClick={() =>
                                router.post(
                                    '/stock/waste/ingredient',
                                    {
                                        ingredient_id: ing.id,
                                        qty: ing.qty,
                                        unit: ing.unit || null,
                                        reason_id: ing.reason,
                                        note: ing.note || null,
                                    },
                                    {
                                        ...opts,
                                        onSuccess: () =>
                                            setIng({
                                                id: '',
                                                qty: '',
                                                unit: '',
                                                reason: '',
                                                note: '',
                                            }),
                                    },
                                )
                            }
                        >
                            Catat terbuang
                        </Button>
                    </section>
                ) : (
                    <section className="grid max-w-xl gap-3 rounded-xl border bg-card p-4">
                        <p className="text-sm text-muted-foreground">
                            Untuk menu yang sudah jadi (salah buat, tumpah,
                            tidak jadi diambil). Bahan di resepnya ikut
                            dikurangi.
                        </p>
                        <Field label="Menu">
                            <Combobox
                                aria-label="Menu"
                                placeholder="Cari menu…"
                                options={products.map((p) => ({
                                    value: p.id,
                                    label: p.name,
                                }))}
                                value={prod.id}
                                onChange={(v) =>
                                    setProd({
                                        ...prod,
                                        id: v === '' ? '' : Number(v),
                                        variant: '',
                                    })
                                }
                            />
                        </Field>
                        <div className="grid grid-cols-2 gap-3">
                            {product && product.variants.length > 0 && (
                                <Field label="Varian">
                                    <Select
                                        value={prod.variant}
                                        onChange={(e) =>
                                            setProd({
                                                ...prod,
                                                variant: e.target.value
                                                    ? Number(e.target.value)
                                                    : '',
                                            })
                                        }
                                    >
                                        <option value="">Pilih…</option>
                                        {product.variants.map((v) => (
                                            <option key={v.id} value={v.id}>
                                                {v.name}
                                            </option>
                                        ))}
                                    </Select>
                                </Field>
                            )}
                            <Field label="Jumlah porsi">
                                <Input
                                    className="h-11"
                                    type="number"
                                    min={1}
                                    value={prod.portions}
                                    onChange={(e) =>
                                        setProd({
                                            ...prod,
                                            portions:
                                                e.target.value === ''
                                                    ? ''
                                                    : Number(e.target.value),
                                        })
                                    }
                                />
                            </Field>
                        </div>
                        <Field label="Alasan">
                            <Select
                                value={prod.reason}
                                onChange={(e) =>
                                    setProd({
                                        ...prod,
                                        reason: e.target.value
                                            ? Number(e.target.value)
                                            : '',
                                    })
                                }
                            >
                                <option value="">Pilih alasan…</option>
                                {active.map((r) => (
                                    <option key={r.id} value={r.id}>
                                        {r.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Catatan (opsional)">
                            <Input
                                className="h-11"
                                value={prod.note}
                                maxLength={200}
                                onChange={(e) =>
                                    setProd({ ...prod, note: e.target.value })
                                }
                            />
                        </Field>
                        <Button
                            className="h-12"
                            disabled={
                                prod.id === '' ||
                                !prod.portions ||
                                prod.reason === '' ||
                                (!!product &&
                                    product.variants.length > 0 &&
                                    prod.variant === '')
                            }
                            onClick={() =>
                                router.post(
                                    '/stock/waste/product',
                                    {
                                        product_id: prod.id,
                                        variant_id: prod.variant || null,
                                        portions: prod.portions,
                                        reason_id: prod.reason,
                                        note: prod.note || null,
                                    },
                                    {
                                        ...opts,
                                        onSuccess: () =>
                                            setProd({
                                                id: '',
                                                variant: '',
                                                portions: 1,
                                                reason: '',
                                                note: '',
                                            }),
                                    },
                                )
                            }
                        >
                            Catat terbuang
                        </Button>
                    </section>
                )}

                <section className="grid gap-2">
                    <h2 className="font-semibold">Catatan saya</h2>
                    <ResponsiveTable
                        headers={['Waktu', 'Item', 'Jumlah', 'Alasan', 'Nilai']}
                        numeric={[2, 4]}
                        empty="Belum ada catatan."
                        rows={recent.map((r) => [
                            r.at,
                            r.label,
                            `${num(r.qty)} ${r.unit}`,
                            r.reason,
                            rupiah(r.cost),
                        ])}
                    />
                </section>

                {canManage && (
                    <section className="grid max-w-xl gap-3 rounded-xl border bg-card p-4">
                        <h2 className="font-semibold">Alasan waste</h2>
                        <p className="text-xs text-muted-foreground">
                            Daftar ini yang dipilih staf. Alasan yang
                            dinonaktifkan tidak muncul lagi, tapi catatan lama
                            tetap utuh.
                        </p>
                        <ul className="grid gap-1.5">
                            {reasons.map((r) => (
                                <li
                                    key={r.id}
                                    className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                                >
                                    <span
                                        className={
                                            r.is_active
                                                ? ''
                                                : 'text-muted-foreground line-through'
                                        }
                                    >
                                        {r.name}
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="h-9"
                                        onClick={() =>
                                            router.put(
                                                `/stock/waste/reasons/${r.id}`,
                                                {
                                                    name: r.name,
                                                    is_active: !r.is_active,
                                                },
                                                opts,
                                            )
                                        }
                                    >
                                        {r.is_active
                                            ? 'Nonaktifkan'
                                            : 'Aktifkan'}
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <div className="flex gap-2">
                            <Input
                                className="h-11"
                                placeholder="Alasan baru, mis. Dibawa pulang"
                                value={newReason}
                                maxLength={40}
                                onChange={(e) => setNewReason(e.target.value)}
                            />
                            <Button
                                className="h-11"
                                disabled={newReason.trim() === ''}
                                onClick={() =>
                                    router.post(
                                        '/stock/waste/reasons',
                                        { name: newReason },
                                        {
                                            ...opts,
                                            onSuccess: () => setNewReason(''),
                                        },
                                    )
                                }
                            >
                                <Plus /> Tambah
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
