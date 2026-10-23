import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '@/lib/api';
import {
    Field,
    MoneyInput,
    PageHeader,
    Select,
    Tabs,
} from '@/components/pos/ui';
import { ReceiptView } from '@/components/pos/receipt-view';
import { Button } from '@/components/ui/button';
import type { Receipt } from '@/types/pos';
import { Input } from '@/components/ui/input';

type Acc = { id: number; code: string; name: string };
type PM = {
    id: number;
    name: string;
    type: string;
    account_id: number;
    is_active: boolean;
};
type Tax = {
    id: number;
    name: string;
    kind: string;
    rate_bp: number;
    sequence: number;
    base: string;
    is_inclusive: boolean;
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
    is_active: boolean;
};
type Field_ = {
    key: string;
    type: 'bool' | 'int' | 'text' | 'time' | 'select' | 'secret' | 'list';
    label: string;
    hint: string;
    options?: [string, string][];
    min?: number;
    max?: number;
};
type CatalogTab = {
    key: string;
    label: string;
    groups: { title: string; fields: Field_[] }[];
};
type Props = {
    outlet: {
        name: string;
        address: string | null;
        npwp: string | null;
        logo: string | null;
    };
    settings: Record<string, string | number | boolean | null>;
    catalog: CatalogTab[];
    paymentMethods: PM[];
    cashAccounts: Acc[];
    taxRules: Tax[];
    promotions: Promo[];
    discountPresets: DPreset[] | null;
};

type DPreset = {
    id: number;
    name: string;
    type: 'percent' | 'fixed';
    value: number;
    needs_pin: boolean;
    is_active: boolean;
};

export default function SettingsPage({
    outlet,
    settings,
    catalog,
    paymentMethods,
    cashAccounts,
    taxRules,
    promotions,
    discountPresets,
}: Props) {
    const [tab, setTab] = useState<string>('outlet');
    const errors = usePage().props.errors as Record<string, string>;
    const active = catalog.find((c) => c.key === tab);

    return (
        <>
            <Head title="Pengaturan" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Pengaturan"
                    subtitle="Semua pengaturan operasional diubah di sini, tanpa perlu ubah kode. Perubahan tercatat di audit log."
                />
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'outlet', label: 'Outlet' },
                        ...catalog.map((c) => ({ id: c.key, label: c.label })),
                        { id: 'pay', label: 'Metode bayar' },
                        { id: 'tax', label: 'Pajak & biaya' },
                        { id: 'promo', label: 'Promo' },
                    ]}
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                {tab === 'outlet' && <OutletForm outlet={outlet} />}
                {active && (
                    <CatalogForm
                        key={active.key}
                        tab={active}
                        settings={settings}
                        outlet={outlet}
                    />
                )}
                {tab === 'pay' && (
                    <Methods methods={paymentMethods} accounts={cashAccounts} />
                )}
                {tab === 'tax' && <Taxes rules={taxRules} />}
                {tab === 'promo' && (
                    <>
                        {discountPresets && (
                            <DiscountPresets presets={discountPresets} />
                        )}
                        <Promos promos={promotions} />
                    </>
                )}
            </div>
        </>
    );
}

function OutletForm({ outlet }: { outlet: Props['outlet'] }) {
    const [f, setF] = useState({
        name: outlet.name,
        address: outlet.address ?? '',
        npwp: outlet.npwp ?? '',
        logo: null as File | null,
    });

    return (
        <section className="grid max-w-xl gap-3 rounded-xl border bg-card p-4">
            <Field label="Nama outlet">
                <Input
                    className="h-11"
                    value={f.name}
                    onChange={(e) => setF({ ...f, name: e.target.value })}
                />
            </Field>
            <Field label="Alamat">
                <Input
                    className="h-11"
                    value={f.address}
                    onChange={(e) => setF({ ...f, address: e.target.value })}
                />
            </Field>
            <Field label="NPWP (opsional)">
                <Input
                    className="h-11"
                    value={f.npwp}
                    onChange={(e) => setF({ ...f, npwp: e.target.value })}
                />
            </Field>
            <Field label="Logo">
                <input
                    type="file"
                    accept="image/*"
                    onChange={(e) =>
                        setF({ ...f, logo: e.target.files?.[0] ?? null })
                    }
                />
            </Field>
            <Button
                className="h-12"
                onClick={() =>
                    router.post(
                        '/admin/settings/outlet',
                        { ...f, logo: f.logo ?? undefined },
                        { forceFormData: true, preserveScroll: true },
                    )
                }
            >
                Simpan
            </Button>
        </section>
    );
}

function sampleReceipt(
    v: Record<string, string | number | boolean | null>,
    outlet: Props['outlet'],
): Receipt {
    const on = (k: string) => v[k] === true;

    return {
        copy: false,
        outlet: {
            name: outlet.name,
            address: outlet.address,
            npwp: on('receipt.show_npwp')
                ? (outlet.npwp ?? '00.000.000.0-000.000')
                : null,
        },
        header: v['receipt.header'] ? String(v['receipt.header']) : null,
        show_link: on('receipt.show_link'),
        order_number: 'ORD-261020-0001',
        label: null,
        is_split: false,
        date: '20 Okt 2026 12:30',
        cashier: on('receipt.show_cashier') ? 'Kasir' : null,
        table: on('receipt.show_table') ? 'I-1' : null,
        call_number: on('receipt.show_call_number') ? 'A-001' : null,
        items: [
            {
                name: 'Americano',
                qty: 2,
                unit_price: 27000,
                line_total: 54000,
                modifiers: ['Extra Shot'],
            },
            {
                name: 'Croissant',
                qty: 1,
                unit_price: 25000,
                line_total: 25000,
                modifiers: [],
            },
        ],
        subtotal: 79000,
        discount_total: 0,
        charges: [],
        rounding: 0,
        grand_total: 79000,
        payments: [
            { method: 'Tunai', amount: 79000, tendered: 100000, change: 21000 },
        ],
        change: 21000,
        footer: v['receipt.footer'] ? String(v['receipt.footer']) : null,
        public_url: 'https://contoh.com/r/01ABC',
    };
}

function CatalogForm({
    tab,
    settings,
    outlet,
}: {
    tab: CatalogTab;
    settings: Props['settings'];
    outlet: Props['outlet'];
}) {
    const fields = tab.groups.flatMap((g) => g.fields);
    const [v, setV] = useState<
        Record<string, string | number | boolean | null>
    >(
        Object.fromEntries(
            fields.map((f) => [
                f.key,
                settings[f.key] ?? (f.type === 'bool' ? false : ''),
            ]),
        ),
    );

    return (
        <div
            className={
                tab.key === 'pos'
                    ? 'grid items-start gap-4 lg:grid-cols-[minmax(0,40rem)_380px]'
                    : ''
            }
        >
            <div className="grid max-w-2xl gap-4">
                {tab.groups.map((g) => (
                    <section
                        key={g.title}
                        className="grid gap-4 rounded-xl border bg-card p-4"
                    >
                        <h2 className="font-semibold">{g.title}</h2>
                        {g.fields.map((f) =>
                            f.type === 'bool' ? (
                                <label
                                    key={f.key}
                                    className="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
                                >
                                    <input
                                        type="checkbox"
                                        role="switch"
                                        className="mt-0.5 size-6 shrink-0"
                                        checked={v[f.key] === true}
                                        onChange={(e) =>
                                            setV({
                                                ...v,
                                                [f.key]: e.target.checked,
                                            })
                                        }
                                    />
                                    <span>
                                        <span className="block font-medium">
                                            {f.label}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {f.hint}
                                        </span>
                                    </span>
                                </label>
                            ) : (
                                <Field
                                    key={f.key}
                                    label={f.label}
                                    hint={f.hint}
                                >
                                    {f.type === 'select' ? (
                                        <Select
                                            value={String(v[f.key] ?? '')}
                                            onChange={(e) =>
                                                setV({
                                                    ...v,
                                                    [f.key]: e.target.value,
                                                })
                                            }
                                        >
                                            {f.options?.map(([val, label]) => (
                                                <option key={val} value={val}>
                                                    {label}
                                                </option>
                                            ))}
                                        </Select>
                                    ) : (
                                        <Input
                                            className="h-11"
                                            type={
                                                f.type === 'time'
                                                    ? 'time'
                                                    : f.type === 'int'
                                                      ? 'number'
                                                      : f.type === 'secret'
                                                        ? 'password'
                                                        : 'text'
                                            }
                                            min={f.min}
                                            max={f.max}
                                            value={String(v[f.key] ?? '')}
                                            onChange={(e) =>
                                                setV({
                                                    ...v,
                                                    [f.key]: e.target.value,
                                                })
                                            }
                                        />
                                    )}
                                </Field>
                            ),
                        )}
                    </section>
                ))}
                {tab.key === 'notif' && <NotificationTests />}
                <Button
                    className="h-12"
                    onClick={() =>
                        router.post(
                            '/admin/settings',
                            {
                                values: Object.fromEntries(
                                    Object.entries(v).map(([k, x]) => [
                                        k,
                                        x === '' ? null : x,
                                    ]),
                                ),
                            },
                            { preserveScroll: true },
                        )
                    }
                >
                    Simpan pengaturan
                </Button>
            </div>
            {tab.key === 'pos' && (
                <aside
                    className="sticky top-4 rounded-2xl border bg-muted p-3"
                    aria-label="Pratinjau struk"
                >
                    <p className="mb-2 text-center text-xs font-medium text-muted-foreground">
                        Pratinjau struk
                    </p>
                    <div className="overflow-hidden rounded-xl border">
                        <ReceiptView r={sampleReceipt(v, outlet)} />
                    </div>
                </aside>
            )}
        </div>
    );
}

function Methods({ methods, accounts }: { methods: PM[]; accounts: Acc[] }) {
    const [n, setN] = useState({
        name: '',
        type: 'qris',
        account_id: accounts[0]?.id ?? 0,
    });

    return (
        <div className="grid max-w-2xl gap-3">
            {methods.map((m) => (
                <MethodRow key={m.id} m={m} accounts={accounts} />
            ))}
            <section className="grid gap-2 rounded-xl border bg-card p-3">
                <h3 className="font-medium">Metode baru</h3>
                <Input
                    className="h-11"
                    placeholder="mis. QRIS BCA"
                    value={n.name}
                    onChange={(e) => setN({ ...n, name: e.target.value })}
                />
                <div className="grid grid-cols-2 gap-2">
                    <Select
                        value={n.type}
                        onChange={(e) => setN({ ...n, type: e.target.value })}
                    >
                        <option value="cash">Tunai</option>
                        <option value="qris">QRIS</option>
                        <option value="transfer">Transfer bank</option>
                    </Select>
                    <Select
                        value={n.account_id}
                        onChange={(e) =>
                            setN({ ...n, account_id: Number(e.target.value) })
                        }
                    >
                        {accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </Select>
                </div>
                <p className="text-xs text-muted-foreground">
                    Akun penerima menentukan uang masuk ke rekening mana. Tambah
                    rekening baru di menu Akuntansi › Akun.
                </p>
                <Button
                    className="h-11"
                    disabled={!n.name.trim()}
                    onClick={() =>
                        router.post(
                            '/admin/payment-methods',
                            { ...n, is_active: true },
                            {
                                preserveScroll: true,
                                onSuccess: () => setN({ ...n, name: '' }),
                            },
                        )
                    }
                >
                    Tambah
                </Button>
            </section>
        </div>
    );
}

function MethodRow({ m, accounts }: { m: PM; accounts: Acc[] }) {
    const [f, setF] = useState(m);

    return (
        <div className="grid gap-2 rounded-xl border bg-card p-3 sm:grid-cols-[1fr_140px_1fr_auto]">
            <Input
                className="h-11"
                value={f.name}
                onChange={(e) => setF({ ...f, name: e.target.value })}
                aria-label="Nama"
            />
            <Select
                value={f.type}
                onChange={(e) => setF({ ...f, type: e.target.value })}
                aria-label="Tipe"
            >
                <option value="cash">Tunai</option>
                <option value="qris">QRIS</option>
                <option value="transfer">Transfer</option>
            </Select>
            <Select
                value={f.account_id}
                onChange={(e) =>
                    setF({ ...f, account_id: Number(e.target.value) })
                }
                aria-label="Akun penerima"
            >
                {accounts.map((a) => (
                    <option key={a.id} value={a.id}>
                        {a.name}
                    </option>
                ))}
            </Select>
            <div className="flex items-center gap-2">
                <label className="flex items-center gap-1 text-sm">
                    <input
                        type="checkbox"
                        className="size-5"
                        checked={f.is_active}
                        onChange={(e) =>
                            setF({ ...f, is_active: e.target.checked })
                        }
                    />
                    Aktif
                </label>
                <Button
                    variant="outline"
                    className="h-11"
                    onClick={() =>
                        router.put(`/admin/payment-methods/${m.id}`, f, {
                            preserveScroll: true,
                        })
                    }
                >
                    Simpan
                </Button>
            </div>
        </div>
    );
}

function Taxes({ rules }: { rules: Tax[] }) {
    const [n, setN] = useState({
        name: '',
        kind: 'tax',
        rate_bp: 1000,
        sequence: rules.length + 1,
        base: 'cumulative',
        is_inclusive: false,
        is_active: false,
    });

    return (
        <div className="grid max-w-3xl gap-3">
            <p className="text-sm text-muted-foreground">
                Urutan default: Subtotal → Diskon → Service Charge → Pajak.
                Tarif dalam basis poin (1000 = 10%). Semua nonaktif saat
                go-live.
            </p>
            {rules.map((r) => (
                <TaxRow key={r.id} r={r} />
            ))}
            <section className="grid gap-2 rounded-xl border bg-card p-3">
                <h3 className="font-medium">Komponen baru</h3>
                <Input
                    className="h-11"
                    placeholder="Nama (mis. PPN)"
                    value={n.name}
                    onChange={(e) => setN({ ...n, name: e.target.value })}
                />
                <Button
                    className="h-11"
                    disabled={!n.name.trim()}
                    onClick={() =>
                        router.post('/admin/tax-rules', n, {
                            preserveScroll: true,
                            onSuccess: () => setN({ ...n, name: '' }),
                        })
                    }
                >
                    Tambah (nonaktif)
                </Button>
            </section>
        </div>
    );
}

function TaxRow({ r }: { r: Tax }) {
    const [f, setF] = useState(r);

    return (
        <div className="grid gap-2 rounded-xl border bg-card p-3">
            <div className="grid gap-2 sm:grid-cols-4">
                <Field label="Nama">
                    <Input
                        className="h-11"
                        value={f.name}
                        onChange={(e) => setF({ ...f, name: e.target.value })}
                    />
                </Field>
                <Field label="Jenis">
                    <Select
                        value={f.kind}
                        onChange={(e) => setF({ ...f, kind: e.target.value })}
                    >
                        <option value="tax">Pajak</option>
                        <option value="service">Service charge</option>
                    </Select>
                </Field>
                <Field label="Tarif (bp)" hint={`${f.rate_bp / 100}%`}>
                    <Input
                        className="h-11"
                        type="number"
                        value={f.rate_bp}
                        onChange={(e) =>
                            setF({ ...f, rate_bp: Number(e.target.value) })
                        }
                    />
                </Field>
                <Field label="Urutan">
                    <Input
                        className="h-11"
                        type="number"
                        value={f.sequence}
                        onChange={(e) =>
                            setF({ ...f, sequence: Number(e.target.value) })
                        }
                    />
                </Field>
            </div>
            <div className="flex flex-wrap items-center gap-4">
                <Select
                    className="w-64"
                    value={f.base}
                    onChange={(e) => setF({ ...f, base: e.target.value })}
                    aria-label="Basis"
                >
                    <option value="after_discount">
                        Basis: subtotal setelah diskon
                    </option>
                    <option value="cumulative">
                        Basis: subtotal + komponen sebelumnya
                    </option>
                </Select>
                <label className="flex items-center gap-1 text-sm">
                    <input
                        type="checkbox"
                        className="size-5"
                        checked={f.is_inclusive}
                        onChange={(e) =>
                            setF({ ...f, is_inclusive: e.target.checked })
                        }
                    />
                    Sudah termasuk di harga
                </label>
                <label className="flex items-center gap-1 text-sm">
                    <input
                        type="checkbox"
                        className="size-5"
                        checked={f.is_active}
                        onChange={(e) =>
                            setF({ ...f, is_active: e.target.checked })
                        }
                    />
                    Aktif
                </label>
                <Button
                    className="h-11"
                    onClick={() =>
                        router.put(`/admin/tax-rules/${r.id}`, f, {
                            preserveScroll: true,
                        })
                    }
                >
                    Simpan
                </Button>
            </div>
        </div>
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

function Promos({ promos }: { promos: Promo[] }) {
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
                    jadwal; jam boleh melewati tengah malam (22.00–02.00). Bila
                    beberapa promo cocok, hanya yang pertama dipakai.
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
                            {promoWhen(p)}
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

/** Sends a real test message to each channel; the form must be saved first because the server uses what is stored. */
function NotificationTests() {
    const [busy, setBusy] = useState<string | null>(null);
    const send = async (channel: string) => {
        setBusy(channel);

        try {
            const res = await api<{ message: string }>(
                'POST',
                '/admin/settings/test-notification',
                { channel },
            );
            toast.success(res.message);
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.message : 'Gagal mengirim tes.',
            );
        } finally {
            setBusy(null);
        }
    };

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <h2 className="font-semibold">Uji pengiriman</h2>
            <p className="text-sm text-muted-foreground">
                Simpan pengaturan di bawah dulu, lalu kirim pesan uji supaya
                token, email, atau nomor yang salah ketahuan sekarang.
            </p>
            <div className="flex flex-wrap gap-2">
                {[
                    ['telegram', 'Telegram'],
                    ['email', 'Email'],
                    ['whatsapp', 'WhatsApp'],
                ].map(([key, label]) => (
                    <Button
                        key={key}
                        type="button"
                        variant="outline"
                        className="h-11"
                        disabled={busy !== null}
                        onClick={() => void send(key)}
                    >
                        {busy === key ? 'Mengirim…' : `Kirim tes ke ${label}`}
                    </Button>
                ))}
            </div>
        </section>
    );
}
