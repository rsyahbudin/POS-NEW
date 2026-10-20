import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    Field,
    MoneyInput,
    PageHeader,
    Select,
    Tabs,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
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
    is_active: boolean;
};
type Props = {
    outlet: {
        name: string;
        address: string | null;
        npwp: string | null;
        logo: string | null;
    };
    settings: Record<string, string | number | null>;
    paymentMethods: PM[];
    cashAccounts: Acc[];
    taxRules: Tax[];
    promotions: Promo[];
};

export default function SettingsPage({
    outlet,
    settings,
    paymentMethods,
    cashAccounts,
    taxRules,
    promotions,
}: Props) {
    const [tab, setTab] = useState<
        'outlet' | 'ops' | 'pay' | 'tax' | 'promo' | 'notif'
    >('outlet');
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <>
            <Head title="Pengaturan" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Pengaturan"
                    subtitle="Perubahan tercatat di audit log."
                />
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'outlet', label: 'Outlet' },
                        { id: 'ops', label: 'Operasional' },
                        { id: 'pay', label: 'Metode bayar' },
                        { id: 'tax', label: 'Pajak & biaya' },
                        { id: 'promo', label: 'Promo' },
                        { id: 'notif', label: 'Notifikasi' },
                    ]}
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                {tab === 'outlet' && <OutletForm outlet={outlet} />}
                {tab === 'ops' && (
                    <SettingsForm
                        settings={settings}
                        fields={[
                            [
                                'operations.business_day_start',
                                'Jam mulai hari bisnis',
                                'time',
                            ],
                            [
                                'pricing.rounding',
                                'Pembulatan grand total',
                                'rounding',
                            ],
                            ['pricing.price_mode', 'Mode harga menu', 'mode'],
                            [
                                'discount.cashier_limit_percent',
                                'Batas diskon kasir tanpa PIN (%)',
                                'number',
                            ],
                            [
                                'qr.confirm_alert_seconds',
                                'Alarm QR belum dikonfirmasi (detik)',
                                'number',
                            ],
                            [
                                'qr.max_submits_per_window',
                                'Maks kiriman QR per meja / 10 menit',
                                'number',
                            ],
                            [
                                'kitchen.warn_minutes',
                                'KDS: kuning setelah (menit)',
                                'number',
                            ],
                            [
                                'kitchen.danger_minutes',
                                'KDS: merah setelah (menit)',
                                'number',
                            ],
                            ['receipt.footer', 'Catatan kaki struk', 'text'],
                        ]}
                    />
                )}
                {tab === 'notif' && (
                    <SettingsForm
                        settings={settings}
                        fields={[
                            [
                                'telegram.bot_token',
                                'Telegram bot token (Z-report)',
                                'password',
                            ],
                            [
                                'telegram.chat_id',
                                'Telegram chat ID owner',
                                'text',
                            ],
                        ]}
                    />
                )}
                {tab === 'pay' && (
                    <Methods methods={paymentMethods} accounts={cashAccounts} />
                )}
                {tab === 'tax' && <Taxes rules={taxRules} />}
                {tab === 'promo' && <Promos promos={promotions} />}
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

function SettingsForm({
    settings,
    fields,
}: {
    settings: Props['settings'];
    fields: [string, string, string][];
}) {
    const [v, setV] = useState<Record<string, string | number | null>>(
        Object.fromEntries(fields.map(([k]) => [k, settings[k] ?? ''])),
    );

    return (
        <section className="grid max-w-xl gap-3 rounded-xl border bg-card p-4">
            {fields.map(([key, label, kind]) => (
                <Field key={key} label={label}>
                    {kind === 'rounding' ? (
                        <Select
                            value={String(v[key])}
                            onChange={(e) =>
                                setV({ ...v, [key]: e.target.value })
                            }
                        >
                            <option value="0">Tanpa pembulatan</option>
                            <option value="100">Ke Rp100</option>
                            <option value="500">Ke Rp500</option>
                        </Select>
                    ) : kind === 'mode' ? (
                        <Select
                            value={String(v[key])}
                            onChange={(e) =>
                                setV({ ...v, [key]: e.target.value })
                            }
                        >
                            <option value="exclusive">
                                Belum termasuk pajak
                            </option>
                            <option value="inclusive">
                                Sudah termasuk pajak
                            </option>
                        </Select>
                    ) : (
                        <Input
                            className="h-11"
                            type={
                                kind === 'time'
                                    ? 'time'
                                    : kind === 'number'
                                      ? 'number'
                                      : kind === 'password'
                                        ? 'password'
                                        : 'text'
                            }
                            value={v[key] ?? ''}
                            onChange={(e) =>
                                setV({ ...v, [key]: e.target.value })
                            }
                        />
                    )}
                </Field>
            ))}
            <p className="text-xs text-muted-foreground">
                Mode harga & tarif pajak berlaku untuk order baru; order lama
                tidak berubah.
            </p>
            <Button
                className="h-12"
                onClick={() =>
                    router.post(
                        '/admin/settings',
                        Object.fromEntries(
                            Object.entries(v).map(([k, x]) => [
                                k,
                                x === '' ? null : x,
                            ]),
                        ),
                        { preserveScroll: true },
                    )
                }
            >
                Simpan
            </Button>
        </section>
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
                                {a.code} {a.name}
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
                        {a.code} {a.name}
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

function Promos({ promos }: { promos: Promo[] }) {
    const [n, setN] = useState({
        name: 'Happy Hour',
        type: 'percent',
        value: 1000,
        starts_at: '15:00',
        ends_at: '17:00',
        days: [] as number[],
        is_active: true,
    });

    return (
        <div className="grid max-w-2xl gap-3">
            {promos.map((p) => (
                <div
                    key={p.id}
                    className="flex items-center justify-between rounded-xl border bg-card p-3 text-sm"
                >
                    <span>
                        <strong>{p.name}</strong> ·{' '}
                        {p.type === 'percent'
                            ? `${p.value / 100}%`
                            : `Rp${p.value.toLocaleString('id-ID')}`}{' '}
                        · {p.starts_at.slice(0, 5)}–{p.ends_at.slice(0, 5)}{' '}
                        {p.is_active ? '' : '(nonaktif)'}
                    </span>
                    {p.is_active && (
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
                    )}
                </div>
            ))}
            <section className="grid gap-2 rounded-xl border bg-card p-3">
                <h3 className="font-medium">Promo terjadwal baru</h3>
                <Input
                    className="h-11"
                    value={n.name}
                    onChange={(e) => setN({ ...n, name: e.target.value })}
                    aria-label="Nama"
                />
                <div className="grid grid-cols-2 gap-2">
                    <Select
                        value={n.type}
                        onChange={(e) => setN({ ...n, type: e.target.value })}
                    >
                        <option value="percent">Persen</option>
                        <option value="fixed">Nominal</option>
                    </Select>
                    <MoneyInput
                        value={n.value}
                        onChange={(v) =>
                            setN({ ...n, value: v === '' ? 0 : v })
                        }
                        aria-label="Nilai (persen dalam basis poin: 1000 = 10%)"
                    />
                    <Input
                        className="h-11"
                        type="time"
                        value={n.starts_at}
                        onChange={(e) =>
                            setN({ ...n, starts_at: e.target.value })
                        }
                        aria-label="Mulai"
                    />
                    <Input
                        className="h-11"
                        type="time"
                        value={n.ends_at}
                        onChange={(e) =>
                            setN({ ...n, ends_at: e.target.value })
                        }
                        aria-label="Selesai"
                    />
                </div>
                <p className="text-xs text-muted-foreground">
                    Persen dalam basis poin (1000 = 10%). Diterapkan otomatis
                    pada order yang dikonfirmasi dalam jam promo.
                </p>
                <Button
                    className="h-11"
                    onClick={() =>
                        router.post('/admin/promotions', n, {
                            preserveScroll: true,
                        })
                    }
                >
                    Tambah promo
                </Button>
            </section>
        </div>
    );
}
