import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { Field, PageHeader, Select } from '@/components/pos/ui';
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

export default function PaymentsTaxPage({
    paymentMethods,
    cashAccounts,
    taxRules,
}: {
    paymentMethods: PM[];
    cashAccounts: Acc[];
    taxRules: Tax[];
}) {
    const errors = usePage().props.errors as Record<string, string>;

    return (
        <>
            <Head title="Pembayaran & Pajak" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-6 p-4 md:p-6">
                <PageHeader
                    title="Pembayaran & pajak"
                    subtitle="Metode bayar beserta rekening penerimanya, lalu pajak dan service charge. Perubahan tercatat di audit log."
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Metode bayar</h2>
                    <Methods methods={paymentMethods} accounts={cashAccounts} />
                </section>
                <section className="grid gap-2">
                    <h2 className="text-lg font-semibold">Pajak & biaya</h2>
                    <Taxes rules={taxRules} />
                </section>
            </div>
        </>
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
