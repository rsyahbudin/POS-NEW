import { Head, router, usePage } from '@inertiajs/react';
import { ChevronDown, FileSpreadsheet, Plus } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import {
    Field,
    MoneyInput,
    PageHeader,
    Select,
    Stat,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

type Log = {
    id: number;
    type: string;
    happened_on: string;
    cost: number;
    note: string | null;
    user: string | null;
};
type Asset = {
    id: number;
    code: string;
    name: string;
    category: string | null;
    brand: string | null;
    model: string | null;
    serial: string | null;
    quantity: number;
    purchased_on: string | null;
    purchase_price: number;
    location: string | null;
    condition: string;
    notes: string | null;
    logs: Log[];
};
type Opt = { key: string; label: string };
type Props = {
    assets: Asset[];
    summary: { items: number; value: number; attention: number };
    conditions: Opt[];
    logTypes: Opt[];
    categories: string[];
    can: { manage: boolean };
};
type Form = {
    id?: number;
    code: string;
    name: string;
    category: string;
    brand: string;
    model: string;
    serial: string;
    quantity: number | '';
    purchased_on: string;
    purchase_price: number | '';
    location: string;
    condition: string;
    notes: string;
};

const BADGE: Record<string, string> = {
    good: 'bg-emerald-100 text-emerald-900',
    needs_service: 'bg-amber-100 text-amber-900',
    broken: 'bg-red-100 text-red-900',
    retired: 'bg-slate-200 text-slate-800',
};
const blank: Form = {
    code: '',
    name: '',
    category: '',
    brand: '',
    model: '',
    serial: '',
    quantity: 1,
    purchased_on: '',
    purchase_price: '',
    location: '',
    condition: 'good',
    notes: '',
};

export default function AssetsPage({
    assets,
    summary,
    conditions,
    logTypes,
    categories,
    can,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [q, setQ] = useState('');
    const [cat, setCat] = useState('');
    const [cond, setCond] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const [importing, setImporting] = useState(false);
    const [form, setForm] = useState<Form | null>(null);
    const [log, setLog] = useState<{
        asset: Asset;
        type: string;
        happened_on: string;
        cost: number | '';
        note: string;
        condition: string;
        location: string;
    } | null>(null);
    const label = (opts: Opt[], key: string) =>
        opts.find((o) => o.key === key)?.label ?? key;
    const shown = assets.filter(
        (a) =>
            `${a.name} ${a.code} ${a.brand ?? ''} ${a.model ?? ''} ${a.location ?? ''}`
                .toLowerCase()
                .includes(q.toLowerCase()) &&
            (!cat || a.category === cat) &&
            (!cond || a.condition === cond),
    );
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Aset & Alat" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Aset & alat"
                    subtitle="Daftar peralatan outlet, kondisinya, dan riwayat servis."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a href="/assets/export?format=xlsx">
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a href="/assets/export?format=pdf">
                                    Unduh PDF
                                </a>
                            </Button>
                            {can.manage && (
                                <Button
                                    variant="outline"
                                    className="h-11"
                                    onClick={() => setImporting(true)}
                                >
                                    <FileSpreadsheet /> Impor Excel
                                </Button>
                            )}
                            {can.manage && (
                                <Button
                                    className="h-11"
                                    onClick={() => setForm({ ...blank })}
                                >
                                    <Plus /> Aset
                                </Button>
                            )}
                        </>
                    }
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                    <Stat
                        label="Jumlah unit"
                        value={summary.items}
                        hint="Tidak termasuk yang tidak dipakai"
                    />
                    <Stat
                        label="Nilai perolehan"
                        value={rupiah(summary.value)}
                    />
                    <Stat
                        label="Perlu perhatian"
                        value={summary.attention}
                        hint="Perlu servis atau rusak"
                    />
                </div>

                <div className="grid gap-2 sm:grid-cols-3">
                    <Input
                        className="h-11"
                        placeholder="Cari nama, kode, lokasi…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    <Select
                        aria-label="Kategori"
                        value={cat}
                        onChange={(e) => setCat(e.target.value)}
                    >
                        <option value="">Semua kategori</option>
                        {categories.map((c) => (
                            <option key={c}>{c}</option>
                        ))}
                    </Select>
                    <Select
                        aria-label="Kondisi"
                        value={cond}
                        onChange={(e) => setCond(e.target.value)}
                    >
                        <option value="">Semua kondisi</option>
                        {conditions.map((c) => (
                            <option key={c.key} value={c.key}>
                                {c.label}
                            </option>
                        ))}
                    </Select>
                </div>

                <ul className="grid gap-2">
                    {shown.map((a) => (
                        <li key={a.id} className="rounded-xl border bg-card">
                            <div className="flex flex-wrap items-center gap-3 p-3">
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold">
                                        {a.name}{' '}
                                        <span className="text-xs font-normal text-muted-foreground">
                                            {a.code}
                                        </span>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {[
                                            a.category,
                                            [a.brand, a.model]
                                                .filter(Boolean)
                                                .join(' '),
                                            a.location && `di ${a.location}`,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ') || '-'}
                                    </p>
                                </div>
                                <span className="text-sm tabular-nums">
                                    {a.quantity} unit
                                    {a.purchase_price > 0 &&
                                        ` · ${rupiah(a.purchase_price)}`}
                                </span>
                                <span
                                    className={cn(
                                        'rounded px-2 py-0.5 text-xs font-medium',
                                        BADGE[a.condition],
                                    )}
                                >
                                    {label(conditions, a.condition)}
                                </span>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="size-10"
                                    aria-label="Riwayat dan detail"
                                    aria-expanded={open === a.id}
                                    onClick={() =>
                                        setOpen(open === a.id ? null : a.id)
                                    }
                                >
                                    <ChevronDown
                                        className={cn(
                                            'transition-transform',
                                            open === a.id && 'rotate-180',
                                        )}
                                    />
                                </Button>
                            </div>
                            {open === a.id && (
                                <div className="grid gap-3 border-t p-3 text-sm">
                                    <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                        <div>
                                            <dt className="inline text-muted-foreground">
                                                No. seri:{' '}
                                            </dt>
                                            <dd className="inline">
                                                {a.serial ?? '-'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="inline text-muted-foreground">
                                                Tanggal beli:{' '}
                                            </dt>
                                            <dd className="inline">
                                                {a.purchased_on ?? '-'}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt className="inline text-muted-foreground">
                                                Nilai:{' '}
                                            </dt>
                                            <dd className="inline">
                                                {rupiah(
                                                    a.quantity *
                                                        a.purchase_price,
                                                )}
                                            </dd>
                                        </div>
                                        {a.notes && (
                                            <div className="sm:col-span-2">
                                                <dt className="inline text-muted-foreground">
                                                    Catatan:{' '}
                                                </dt>
                                                <dd className="inline">
                                                    {a.notes}
                                                </dd>
                                            </div>
                                        )}
                                    </dl>
                                    {can.manage && (
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                className="h-10"
                                                onClick={() =>
                                                    setLog({
                                                        asset: a,
                                                        type: 'service',
                                                        happened_on: new Date()
                                                            .toISOString()
                                                            .slice(0, 10),
                                                        cost: '',
                                                        note: '',
                                                        condition: '',
                                                        location: '',
                                                    })
                                                }
                                            >
                                                Catat servis / riwayat
                                            </Button>
                                            <Button
                                                variant="outline"
                                                className="h-10"
                                                onClick={() =>
                                                    setForm({
                                                        id: a.id,
                                                        code: a.code,
                                                        name: a.name,
                                                        category:
                                                            a.category ?? '',
                                                        brand: a.brand ?? '',
                                                        model: a.model ?? '',
                                                        serial: a.serial ?? '',
                                                        quantity: a.quantity,
                                                        purchased_on:
                                                            a.purchased_on ??
                                                            '',
                                                        purchase_price:
                                                            a.purchase_price,
                                                        location:
                                                            a.location ?? '',
                                                        condition: a.condition,
                                                        notes: a.notes ?? '',
                                                    })
                                                }
                                            >
                                                Ubah
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                className="h-10 text-destructive"
                                                onClick={() =>
                                                    confirm(
                                                        `Hapus ${a.name}?`,
                                                    ) &&
                                                    router.delete(
                                                        `/assets/${a.id}`,
                                                        opts,
                                                    )
                                                }
                                            >
                                                Hapus
                                            </Button>
                                        </div>
                                    )}
                                    <div>
                                        <p className="mb-1 font-medium">
                                            Riwayat
                                        </p>
                                        {a.logs.length === 0 ? (
                                            <p className="text-muted-foreground">
                                                Belum ada riwayat.
                                            </p>
                                        ) : (
                                            <ul className="grid gap-1">
                                                {a.logs.map((l) => (
                                                    <li
                                                        key={l.id}
                                                        className="flex flex-wrap justify-between gap-2 rounded-lg bg-muted/50 px-3 py-2"
                                                    >
                                                        <span>
                                                            {l.happened_on} ·{' '}
                                                            <b>
                                                                {label(
                                                                    logTypes,
                                                                    l.type,
                                                                )}
                                                            </b>
                                                            {l.note &&
                                                                ` · ${l.note}`}
                                                            {l.user && (
                                                                <span className="text-muted-foreground">
                                                                    {' '}
                                                                    ({l.user})
                                                                </span>
                                                            )}
                                                        </span>
                                                        {l.cost > 0 && (
                                                            <span className="tabular-nums">
                                                                {rupiah(l.cost)}
                                                            </span>
                                                        )}
                                                    </li>
                                                ))}
                                            </ul>
                                        )}
                                    </div>
                                </div>
                            )}
                        </li>
                    ))}
                    {shown.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            {assets.length === 0
                                ? 'Belum ada aset.'
                                : 'Tidak ada aset yang cocok.'}
                        </li>
                    )}
                </ul>
            </div>

            {importing && (
                <ImportDialog
                    type="assets"
                    onClose={() => setImporting(false)}
                    onDone={() => router.reload()}
                />
            )}

            {form && (
                <Modal
                    open
                    onClose={() => setForm(null)}
                    title={form.id ? 'Ubah aset' : 'Tambah aset'}
                    className="sm:max-w-xl"
                >
                    <Field label="Nama" error={errors?.name}>
                        <Input
                            className="h-11"
                            value={form.name}
                            placeholder="mis. Mesin espresso"
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </Field>
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Kategori">
                            <Input
                                className="h-11"
                                list="asset-categories"
                                value={form.category}
                                placeholder="mis. Mesin kopi"
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        category: e.target.value,
                                    })
                                }
                            />
                            <datalist id="asset-categories">
                                {[
                                    ...new Set([
                                        'Mesin kopi',
                                        'Grinder',
                                        'Peralatan dapur',
                                        'Kulkas & freezer',
                                        'Furnitur',
                                        'Elektronik',
                                        'Peralatan makan',
                                        ...categories,
                                    ]),
                                ].map((c) => (
                                    <option key={c} value={c} />
                                ))}
                            </datalist>
                        </Field>
                        <Field
                            label="Kode"
                            hint="Kosong = dibuat otomatis"
                            error={errors?.code}
                        >
                            <Input
                                className="h-11"
                                value={form.code}
                                onChange={(e) =>
                                    setForm({ ...form, code: e.target.value })
                                }
                            />
                        </Field>
                        <Field label="Merek">
                            <Input
                                className="h-11"
                                value={form.brand}
                                onChange={(e) =>
                                    setForm({ ...form, brand: e.target.value })
                                }
                            />
                        </Field>
                        <Field label="Model">
                            <Input
                                className="h-11"
                                value={form.model}
                                onChange={(e) =>
                                    setForm({ ...form, model: e.target.value })
                                }
                            />
                        </Field>
                        <Field label="No. seri">
                            <Input
                                className="h-11"
                                value={form.serial}
                                onChange={(e) =>
                                    setForm({ ...form, serial: e.target.value })
                                }
                            />
                        </Field>
                        <Field label="Jumlah unit">
                            <Input
                                className="h-11"
                                type="number"
                                min={1}
                                value={form.quantity}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        quantity:
                                            e.target.value === ''
                                                ? ''
                                                : Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                        <Field label="Tanggal beli">
                            <Input
                                className="h-11"
                                type="date"
                                value={form.purchased_on}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        purchased_on: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Harga per unit">
                            <MoneyInput
                                value={form.purchase_price}
                                onChange={(v) =>
                                    setForm({ ...form, purchase_price: v })
                                }
                            />
                        </Field>
                        <Field label="Lokasi">
                            <Input
                                className="h-11"
                                value={form.location}
                                placeholder="mis. Bar, Dapur, Gudang"
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        location: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Kondisi">
                            <Select
                                value={form.condition}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        condition: e.target.value,
                                    })
                                }
                            >
                                {conditions.map((c) => (
                                    <option key={c.key} value={c.key}>
                                        {c.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    <Field label="Catatan">
                        <Input
                            className="h-11"
                            value={form.notes}
                            onChange={(e) =>
                                setForm({ ...form, notes: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={
                            form.name.trim() === '' || form.quantity === ''
                        }
                        onClick={() => {
                            const body = {
                                ...form,
                                code: form.code || null,
                                purchase_price: form.purchase_price || 0,
                                purchased_on: form.purchased_on || null,
                            };
                            const o = {
                                ...opts,
                                onSuccess: () => setForm(null),
                            };

                            if (form.id) {
                                router.put(`/assets/${form.id}`, body, o);
                            } else {
                                router.post('/assets', body, o);
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}

            {log && (
                <Modal
                    open
                    onClose={() => setLog(null)}
                    title={`Riwayat ${log.asset.name}`}
                    description="Servis, perbaikan, pindah lokasi, atau catatan."
                >
                    <div className="grid grid-cols-2 gap-3">
                        <Field label="Jenis">
                            <Select
                                value={log.type}
                                onChange={(e) =>
                                    setLog({ ...log, type: e.target.value })
                                }
                            >
                                {logTypes.map((t) => (
                                    <option key={t.key} value={t.key}>
                                        {t.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                        <Field label="Tanggal">
                            <Input
                                className="h-11"
                                type="date"
                                value={log.happened_on}
                                onChange={(e) =>
                                    setLog({
                                        ...log,
                                        happened_on: e.target.value,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Biaya (Rp)">
                            <MoneyInput
                                value={log.cost}
                                onChange={(v) => setLog({ ...log, cost: v })}
                            />
                        </Field>
                        <Field
                            label="Kondisi sesudahnya"
                            hint="Kosong = tidak berubah"
                        >
                            <Select
                                value={log.condition}
                                onChange={(e) =>
                                    setLog({
                                        ...log,
                                        condition: e.target.value,
                                    })
                                }
                            >
                                <option value="">Tidak berubah</option>
                                {conditions.map((c) => (
                                    <option key={c.key} value={c.key}>
                                        {c.label}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    </div>
                    {log.type === 'move' && (
                        <Field label="Lokasi baru">
                            <Input
                                className="h-11"
                                value={log.location}
                                onChange={(e) =>
                                    setLog({ ...log, location: e.target.value })
                                }
                            />
                        </Field>
                    )}
                    <Field label="Catatan">
                        <Input
                            className="h-11"
                            value={log.note}
                            onChange={(e) =>
                                setLog({ ...log, note: e.target.value })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        onClick={() =>
                            router.post(
                                `/assets/${log.asset.id}/logs`,
                                {
                                    type: log.type,
                                    happened_on: log.happened_on,
                                    cost: log.cost || 0,
                                    note: log.note || null,
                                    condition: log.condition || null,
                                    location:
                                        log.type === 'move'
                                            ? log.location || null
                                            : null,
                                },
                                { ...opts, onSuccess: () => setLog(null) },
                            )
                        }
                    >
                        Simpan riwayat
                    </Button>
                </Modal>
            )}
        </>
    );
}
