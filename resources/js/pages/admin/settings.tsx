import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '@/lib/api';
import { Field, PageHeader, Select, Tabs } from '@/components/pos/ui';
import { ReceiptView } from '@/components/pos/receipt-view';
import { Button } from '@/components/ui/button';
import type { Receipt } from '@/types/pos';
import { Input } from '@/components/ui/input';

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
        banner: string | null;
        tagline: string | null;
        opening_hours: string | null;
        phone: string | null;
        instagram: string | null;
        wifi_name: string | null;
        wifi_password: string | null;
    };
    settings: Record<string, string | number | boolean | null>;
    catalog: CatalogTab[];
};

export default function SettingsPage({ outlet, settings, catalog }: Props) {
    const [tab, setTab] = useState<string>('outlet');
    const errors = usePage().props.errors as Record<string, string>;
    const active = catalog.find((c) => c.key === tab);

    return (
        <>
            <Head title="Pengaturan" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Pengaturan"
                    subtitle="Opsi sistem yang jarang diubah. Diskon & promo serta metode bayar & pajak punya halaman sendiri di menu. Perubahan tercatat di audit log."
                />
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'outlet', label: 'Outlet' },
                        ...catalog.map((c) => ({ id: c.key, label: c.label })),
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
        banner: null as File | null,
        remove_banner: false,
        tagline: outlet.tagline ?? '',
        opening_hours: outlet.opening_hours ?? '',
        phone: outlet.phone ?? '',
        instagram: outlet.instagram ?? '',
        wifi_name: outlet.wifi_name ?? '',
        wifi_password: outlet.wifi_password ?? '',
    });
    const text = (
        key: keyof typeof f,
        label: string,
        hint?: string,
        ph?: string,
    ) => (
        <Field label={label} hint={hint}>
            <Input
                className="h-11"
                value={String(f[key] ?? '')}
                placeholder={ph}
                onChange={(e) => setF({ ...f, [key]: e.target.value })}
            />
        </Field>
    );

    return (
        <div className="grid max-w-xl gap-4">
            <section className="grid gap-3 rounded-xl border bg-card p-4">
                <h2 className="font-semibold">Profil outlet</h2>
                {text('name', 'Nama outlet')}
                {text('address', 'Alamat')}
                {text('npwp', 'NPWP (opsional)')}
                <Field label="Logo">
                    <input
                        type="file"
                        accept="image/*"
                        onChange={(e) =>
                            setF({ ...f, logo: e.target.files?.[0] ?? null })
                        }
                    />
                </Field>
            </section>
            <section className="grid gap-3 rounded-xl border bg-card p-4">
                <div>
                    <h2 className="font-semibold">
                        Tampil di halaman QR pelanggan
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Semua kolom opsional. Yang diisi akan tampil sebagai
                        banner dan tombol Info kafe di HP pelanggan.
                    </p>
                </div>
                <Field
                    label="Banner"
                    hint="Gambar lebar, mis. foto interior atau promo. Disarankan 1400 × 600 px."
                >
                    {outlet.banner && !f.remove_banner && (
                        <img
                            src={outlet.banner}
                            alt="Banner saat ini"
                            className="mb-2 aspect-[7/3] w-full rounded-xl object-cover"
                        />
                    )}
                    <input
                        type="file"
                        accept="image/*"
                        onChange={(e) =>
                            setF({
                                ...f,
                                banner: e.target.files?.[0] ?? null,
                                remove_banner: false,
                            })
                        }
                    />
                    {outlet.banner && (
                        <label className="mt-2 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-4"
                                checked={f.remove_banner}
                                onChange={(e) =>
                                    setF({
                                        ...f,
                                        remove_banner: e.target.checked,
                                        banner: null,
                                    })
                                }
                            />
                            Hapus banner
                        </label>
                    )}
                </Field>
                {text(
                    'tagline',
                    'Slogan singkat',
                    undefined,
                    'Mis. Kopi lokal, suasana hangat',
                )}
                {text(
                    'opening_hours',
                    'Jam buka',
                    undefined,
                    'Mis. Setiap hari 08.00–22.00',
                )}
                {text(
                    'phone',
                    'Telepon / WhatsApp',
                    undefined,
                    'Mis. 0812-3456-7890',
                )}
                {text('instagram', 'Instagram', undefined, '@namakafe')}
                {text('wifi_name', 'Nama WiFi')}
                {text(
                    'wifi_password',
                    'Password WiFi',
                    'Ditampilkan ke pelanggan di Info kafe.',
                )}
            </section>
            <Button
                className="h-12"
                onClick={() =>
                    router.post(
                        '/admin/settings/outlet',
                        {
                            ...f,
                            logo: f.logo ?? undefined,
                            banner: f.banner ?? undefined,
                            remove_banner: f.remove_banner ? 1 : 0,
                        },
                        { forceFormData: true, preserveScroll: true },
                    )
                }
            >
                Simpan
            </Button>
        </div>
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
