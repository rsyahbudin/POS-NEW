import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Combobox, Field, PageHeader, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type MenuChoices = {
    categories: { id: number; name: string }[];
    products: { id: number; name: string; category_id: number }[];
};
type DPreset = {
    id: number;
    name: string;
    type: 'percent' | 'fixed';
    value: number;
    needs_pin: boolean;
    is_active: boolean;
};
type Promo = {
    id: number;
    name: string;
    type: string;
    value: number;
    starts_at: string;
    ends_at: string;
    days: number[] | null;
    starts_on: string | null;
    ends_on: string | null;
    scope: 'order' | 'category' | 'product';
    category_ids: number[] | null;
    product_ids: number[] | null;
    combinable: boolean;
    is_active: boolean;
};

export default function DiscountsPage({
    discountPresets,
    promotions,
    menuChoices,
}: {
    discountPresets: DPreset[];
    promotions: Promo[];
    menuChoices: MenuChoices;
}) {
    return (
        <>
            <Head title="Diskon & Promo" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-8 p-4 md:p-6">
                <PageHeader
                    title="Diskon & promo"
                    subtitle="Diskon yang tinggal diketuk kasir, dan promo otomatis pada jam, hari, atau tanggal tertentu."
                />
                <DiscountPresets presets={discountPresets} />
                <Promos promos={promotions} menu={menuChoices} />
            </div>
        </>
    );
}

function DiscountPresets({ presets }: { presets: DPreset[] }) {
    const errors = usePage().props.errors as Record<string, string>;
    const [n, setN] = useState({
        name: '',
        type: 'percent' as 'percent' | 'fixed',
        value: '' as number | '',
        needs_pin: false,
    });
    const show = (p: DPreset) =>
        p.type === 'percent'
            ? `${(p.value / 100).toLocaleString('id-ID')}%`
            : `Rp${p.value.toLocaleString('id-ID')}`;
    const opts = { preserveScroll: true };

    return (
        <section className="grid max-w-2xl gap-3">
            <div>
                <h2 className="text-lg font-semibold">Daftar diskon kasir</h2>
                <p className="text-sm text-muted-foreground">
                    Diskon bernama yang tinggal diketuk kasir, misalnya Staff
                    10% atau Owner 20%. Untuk membuat kasir hanya bisa memilih
                    dari daftar ini, cabut izin "Diskon manual" dari peran Kasir
                    di Peran & Akses.
                </p>
            </div>
            {presets.map((p) => (
                <div
                    key={p.id}
                    className="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card p-3 text-sm"
                >
                    <span
                        className={p.is_active ? '' : 'text-muted-foreground'}
                    >
                        <strong>{p.name}</strong> · {show(p)}
                        {p.needs_pin && ' · butuh PIN manager'}
                        {!p.is_active && ' (nonaktif)'}
                    </span>
                    <span className="flex gap-1">
                        <Button
                            variant="ghost"
                            className="h-10"
                            onClick={() =>
                                router.put(
                                    `/admin/discount-presets/${p.id}`,
                                    {
                                        name: p.name,
                                        type: p.type,
                                        value:
                                            p.type === 'percent'
                                                ? p.value / 100
                                                : p.value,
                                        needs_pin: !p.needs_pin,
                                        is_active: p.is_active,
                                    },
                                    opts,
                                )
                            }
                        >
                            {p.needs_pin ? 'Tanpa PIN' : 'Minta PIN'}
                        </Button>
                        <Button
                            variant="ghost"
                            className="h-10"
                            onClick={() =>
                                router.put(
                                    `/admin/discount-presets/${p.id}`,
                                    {
                                        name: p.name,
                                        type: p.type,
                                        value:
                                            p.type === 'percent'
                                                ? p.value / 100
                                                : p.value,
                                        needs_pin: p.needs_pin,
                                        is_active: !p.is_active,
                                    },
                                    opts,
                                )
                            }
                        >
                            {p.is_active ? 'Nonaktifkan' : 'Aktifkan'}
                        </Button>
                    </span>
                </div>
            ))}
            {presets.length === 0 && (
                <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada diskon terdaftar.
                </p>
            )}
            <div className="grid gap-2 rounded-xl border bg-card p-3">
                <h3 className="font-medium">Diskon baru</h3>
                <div className="grid gap-2 sm:grid-cols-[1fr_9rem_8rem]">
                    <Input
                        className="h-11"
                        placeholder="Nama, mis. Staff 10%"
                        aria-label="Nama diskon"
                        value={n.name}
                        maxLength={60}
                        onChange={(e) => setN({ ...n, name: e.target.value })}
                    />
                    <Select
                        aria-label="Jenis diskon"
                        value={n.type}
                        onChange={(e) =>
                            setN({
                                ...n,
                                type: e.target.value as 'percent' | 'fixed',
                            })
                        }
                    >
                        <option value="percent">Persen (%)</option>
                        <option value="fixed">Rupiah (Rp)</option>
                    </Select>
                    <Input
                        className="h-11 text-right"
                        type="number"
                        min={1}
                        max={n.type === 'percent' ? 100 : undefined}
                        aria-label="Nilai diskon"
                        placeholder={n.type === 'percent' ? '10' : '5000'}
                        value={n.value}
                        onChange={(e) =>
                            setN({
                                ...n,
                                value:
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                            })
                        }
                    />
                </div>
                {(errors?.name || errors?.value) && (
                    <p role="alert" className="text-sm text-destructive">
                        {errors.name ?? errors.value}
                    </p>
                )}
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="size-5"
                        checked={n.needs_pin}
                        onChange={(e) =>
                            setN({ ...n, needs_pin: e.target.checked })
                        }
                    />
                    Minta PIN manager saat dipakai kasir
                </label>
                <Button
                    className="h-11 justify-self-start"
                    disabled={n.name.trim() === '' || !n.value}
                    onClick={() =>
                        router.post(
                            '/admin/discount-presets',
                            { ...n },
                            {
                                ...opts,
                                onSuccess: () =>
                                    setN({
                                        name: '',
                                        type: 'percent',
                                        value: '',
                                        needs_pin: false,
                                    }),
                            },
                        )
                    }
                >
                    Tambah diskon
                </Button>
            </div>
        </section>
    );
}

const DAYS = [
    [1, 'Sen'],
    [2, 'Sel'],
    [3, 'Rab'],
    [4, 'Kam'],
    [5, 'Jum'],
    [6, 'Sab'],
    [7, 'Min'],
] as const;

const dateShort = (d: string) =>
    new Date(`${d}T00:00:00`).toLocaleDateString('id-ID', {
        day: 'numeric',
        month: 'short',
    });

function promoWhen(p: Promo): string {
    const hours = `${p.starts_at.slice(0, 5)}–${p.ends_at.slice(0, 5)}`;
    const days =
        p.days && p.days.length > 0 && p.days.length < 7
            ? DAYS.filter(([d]) => p.days?.includes(d))
                  .map(([, l]) => l)
                  .join(', ')
            : 'Setiap hari';
    const dates =
        p.starts_on || p.ends_on
            ? ` · ${p.starts_on ? dateShort(p.starts_on) : '…'} – ${p.ends_on ? dateShort(p.ends_on) : '…'}`
            : '';

    return `${hours} · ${days}${dates}`;
}

function promoWhat(p: Promo, menu: MenuChoices): string {
    const names =
        p.scope === 'category'
            ? menu.categories
                  .filter((c) => p.category_ids?.includes(c.id))
                  .map((c) => c.name)
            : p.scope === 'product'
              ? menu.products
                    .filter((x) => p.product_ids?.includes(x.id))
                    .map((x) => x.name)
              : [];
    const what =
        p.scope === 'order'
            ? 'Seluruh order'
            : names.length > 3
              ? `${names.slice(0, 3).join(', ')} +${names.length - 3}`
              : names.join(', ');

    return `${what} · ${p.combinable ? 'boleh digabung' : 'berdiri sendiri'}`;
}

function Promos({ promos, menu }: { promos: Promo[]; menu: MenuChoices }) {
    const errors = usePage().props.errors as Record<string, string>;
    const blank = {
        name: '',
        type: 'percent' as 'percent' | 'fixed',
        value: '' as number | '',
        starts_at: '15:00',
        ends_at: '17:00',
        days: [] as number[],
        starts_on: '',
        ends_on: '',
        scope: 'order' as 'order' | 'category' | 'product',
        category_ids: [] as number[],
        product_ids: [] as number[],
        combinable: false,
    };
    const [n, setN] = useState(blank);
    const amount = (p: Promo) =>
        p.type === 'percent'
            ? `${(p.value / 100).toLocaleString('id-ID')}%`
            : `Rp${p.value.toLocaleString('id-ID')}`;
    const toggleDay = (d: number) =>
        setN({
            ...n,
            days: n.days.includes(d)
                ? n.days.filter((x) => x !== d)
                : [...n.days, d],
        });
    const payload = () => ({
        name: n.name,
        type: n.type,
        value: n.type === 'percent' ? Number(n.value) * 100 : Number(n.value),
        starts_at: n.starts_at,
        ends_at: n.ends_at,
        days: n.days,
        starts_on: n.starts_on || null,
        ends_on: n.ends_on || null,
        scope: n.scope,
        category_ids: n.scope === 'category' ? n.category_ids : [],
        product_ids: n.scope === 'product' ? n.product_ids : [],
        combinable: n.combinable,
        is_active: true,
    });
    const first = Object.values(errors ?? {})[0];

    return (
        <div className="grid max-w-2xl gap-3">
            <div>
                <h2 className="text-lg font-semibold">Promo terjadwal</h2>
                <p className="text-sm text-muted-foreground">
                    Diskon otomatis pada jam, hari, dan tanggal yang Anda
                    tentukan, misalnya Happy Hour Senin–Jumat 15.00–17.00.
                    Diterapkan sekali pada order saat pesanan dikirim di dalam
                    jadwal; jam boleh melewati tengah malam (22.00–02.00). Promo
                    bisa untuk seluruh order, kategori, atau menu tertentu. Yang
                    boleh digabung dijumlahkan; yang tidak boleh digabung
                    berdiri sendiri.
                </p>
            </div>
            {promos.map((p) => (
                <div
                    key={p.id}
                    className="flex flex-wrap items-center justify-between gap-2 rounded-xl border bg-card p-3 text-sm"
                >
                    <span
                        className={p.is_active ? '' : 'text-muted-foreground'}
                    >
                        <strong>{p.name}</strong> · {amount(p)}
                        {!p.is_active && ' (nonaktif)'}
                        <span className="block text-xs text-muted-foreground">
                            {promoWhen(p)} · {promoWhat(p, menu)}
                        </span>
                    </span>
                    {p.is_active ? (
                        <Button
                            variant="ghost"
                            className="h-10"
                            onClick={() =>
                                router.delete(`/admin/promotions/${p.id}`, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Nonaktifkan
                        </Button>
                    ) : (
                        <Button
                            variant="ghost"
                            className="h-10"
                            onClick={() =>
                                router.put(
                                    `/admin/promotions/${p.id}`,
                                    {
                                        name: p.name,
                                        type: p.type,
                                        value: p.value,
                                        starts_at: p.starts_at.slice(0, 5),
                                        ends_at: p.ends_at.slice(0, 5),
                                        days: p.days ?? [],
                                        starts_on: p.starts_on,
                                        ends_on: p.ends_on,
                                        scope: p.scope,
                                        category_ids: p.category_ids ?? [],
                                        product_ids: p.product_ids ?? [],
                                        combinable: p.combinable,
                                        is_active: true,
                                    },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Aktifkan
                        </Button>
                    )}
                </div>
            ))}
            {promos.length === 0 && (
                <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                    Belum ada promo.
                </p>
            )}
            <section className="grid gap-3 rounded-xl border bg-card p-3">
                <h3 className="font-medium">Promo baru</h3>
                <Input
                    className="h-11"
                    value={n.name}
                    placeholder="Nama, mis. Happy Hour"
                    onChange={(e) => setN({ ...n, name: e.target.value })}
                    aria-label="Nama promo"
                    maxLength={60}
                />
                <div className="grid grid-cols-2 gap-2">
                    <Select
                        aria-label="Jenis diskon"
                        value={n.type}
                        onChange={(e) =>
                            setN({
                                ...n,
                                type: e.target.value as 'percent' | 'fixed',
                            })
                        }
                    >
                        <option value="percent">Persen (%)</option>
                        <option value="fixed">Rupiah (Rp)</option>
                    </Select>
                    <Input
                        className="h-11 text-right"
                        type="number"
                        min={1}
                        max={n.type === 'percent' ? 100 : undefined}
                        value={n.value}
                        placeholder={n.type === 'percent' ? '10' : '5000'}
                        aria-label="Nilai diskon"
                        onChange={(e) =>
                            setN({
                                ...n,
                                value:
                                    e.target.value === ''
                                        ? ''
                                        : Number(e.target.value),
                            })
                        }
                    />
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Jam mulai">
                        <Input
                            className="h-11"
                            type="time"
                            value={n.starts_at}
                            onChange={(e) =>
                                setN({ ...n, starts_at: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Jam selesai">
                        <Input
                            className="h-11"
                            type="time"
                            value={n.ends_at}
                            onChange={(e) =>
                                setN({ ...n, ends_at: e.target.value })
                            }
                        />
                    </Field>
                </div>
                <div>
                    <p className="mb-1.5 text-sm font-medium">
                        Hari{' '}
                        <span className="font-normal text-muted-foreground">
                            · kosongkan untuk setiap hari
                        </span>
                    </p>
                    <div
                        className="flex flex-wrap gap-1.5"
                        role="group"
                        aria-label="Hari berlaku"
                    >
                        {DAYS.map(([d, label]) => (
                            <button
                                key={d}
                                type="button"
                                aria-pressed={n.days.includes(d)}
                                onClick={() => toggleDay(d)}
                                className={`h-10 min-w-12 rounded-lg border px-3 text-sm font-medium transition-colors ${n.days.includes(d) ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-muted'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="grid gap-2">
                    <Field label="Berlaku untuk">
                        <Select
                            value={n.scope}
                            onChange={(e) =>
                                setN({
                                    ...n,
                                    scope: e.target.value as
                                        | 'order'
                                        | 'category'
                                        | 'product',
                                })
                            }
                        >
                            <option value="order">Seluruh order</option>
                            <option value="category">Kategori tertentu</option>
                            <option value="product">Menu tertentu</option>
                        </Select>
                    </Field>
                    {n.scope === 'category' && (
                        <div
                            className="flex flex-wrap gap-1.5"
                            role="group"
                            aria-label="Kategori"
                        >
                            {menu.categories.map((c) => (
                                <button
                                    key={c.id}
                                    type="button"
                                    aria-pressed={n.category_ids.includes(c.id)}
                                    onClick={() =>
                                        setN({
                                            ...n,
                                            category_ids:
                                                n.category_ids.includes(c.id)
                                                    ? n.category_ids.filter(
                                                          (x) => x !== c.id,
                                                      )
                                                    : [...n.category_ids, c.id],
                                        })
                                    }
                                    className={`h-10 rounded-lg border px-3 text-sm font-medium transition-colors ${n.category_ids.includes(c.id) ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-muted'}`}
                                >
                                    {c.name}
                                </button>
                            ))}
                        </div>
                    )}
                    {n.scope === 'product' && (
                        <div className="grid gap-2">
                            <Combobox
                                aria-label="Tambah menu"
                                placeholder="Cari menu untuk ditambahkan…"
                                options={menu.products
                                    .filter(
                                        (x) => !n.product_ids.includes(x.id),
                                    )
                                    .map((x) => ({
                                        value: x.id,
                                        label: x.name,
                                    }))}
                                value=""
                                onChange={(v) =>
                                    v !== '' &&
                                    setN({
                                        ...n,
                                        product_ids: [
                                            ...n.product_ids,
                                            Number(v),
                                        ],
                                    })
                                }
                            />
                            <div className="flex flex-wrap gap-1.5">
                                {n.product_ids.map((id) => (
                                    <button
                                        key={id}
                                        type="button"
                                        title="Hapus dari promo"
                                        onClick={() =>
                                            setN({
                                                ...n,
                                                product_ids:
                                                    n.product_ids.filter(
                                                        (x) => x !== id,
                                                    ),
                                            })
                                        }
                                        className="h-9 rounded-full border bg-accent px-3 text-sm text-accent-foreground"
                                    >
                                        {
                                            menu.products.find(
                                                (x) => x.id === id,
                                            )?.name
                                        }{' '}
                                        ×
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
                <div className="grid grid-cols-2 gap-2">
                    <Field label="Mulai tanggal (opsional)">
                        <Input
                            className="h-11"
                            type="date"
                            value={n.starts_on}
                            onChange={(e) =>
                                setN({ ...n, starts_on: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Sampai tanggal (opsional)">
                        <Input
                            className="h-11"
                            type="date"
                            value={n.ends_on}
                            onChange={(e) =>
                                setN({ ...n, ends_on: e.target.value })
                            }
                        />
                    </Field>
                </div>
                <label className="flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="mt-0.5 size-5"
                        checked={n.combinable}
                        onChange={(e) =>
                            setN({ ...n, combinable: e.target.checked })
                        }
                    />
                    <span>
                        Boleh digabung dengan promo lain
                        <span className="block text-xs text-muted-foreground">
                            Dicentang: diskonnya ditambahkan ke promo lain yang
                            sedang berlaku. Tidak dicentang: promo ini berdiri
                            sendiri dan membatalkan promo lain selama berlaku.
                        </span>
                    </span>
                </label>
                {first && (
                    <p role="alert" className="text-sm text-destructive">
                        {first}
                    </p>
                )}
                <Button
                    className="h-11"
                    disabled={n.name.trim() === '' || !n.value}
                    onClick={() =>
                        router.post('/admin/promotions', payload(), {
                            preserveScroll: true,
                            onSuccess: () => setN(blank),
                        })
                    }
                >
                    Tambah promo
                </Button>
            </section>
        </div>
    );
}
