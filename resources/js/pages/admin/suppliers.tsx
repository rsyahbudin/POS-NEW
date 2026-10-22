import { Head, router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, MessageCircle, Plus } from 'lucide-react';
import { useState } from 'react';
import { ImportDialog } from '@/components/pos/import-dialog';
import { Modal } from '@/components/pos/modal';
import { Field, PageHeader, ResponsiveTable, Tabs } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api } from '@/lib/api';
import { dateId, rupiah } from '@/lib/format';

type Supplier = {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    note: string | null;
    is_active: boolean;
    purchases: number;
    total: number;
    total_30: number;
    last_on: string | null;
    ingredients: number;
};
type Offer = { supplier: string; cost: number; on: string };
type Compare = { ingredient: string; unit: string; offers: Offer[] };
type Detail = {
    purchases: {
        id: number;
        on: string;
        total: number;
        status: string;
        items: { name: string; unit: string; qty: number; unit_cost: number }[];
    }[];
};
type Form = {
    id?: number;
    name: string;
    phone: string;
    address: string;
    note: string;
    is_active: boolean;
};

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });
const wa = (phone: string) => {
    const d = phone.replace(/\D/g, '');

    return `https://wa.me/${d.startsWith('0') ? `62${d.slice(1)}` : d}`;
};

export default function SuppliersPage({
    suppliers,
    compare,
}: {
    suppliers: Supplier[];
    compare: Compare[];
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [tab, setTab] = useState<'list' | 'compare'>('list');
    const [form, setForm] = useState<Form | null>(null);
    const [importing, setImporting] = useState(false);
    const [detail, setDetail] = useState<{
        s: Supplier;
        data: Detail | null;
    } | null>(null);
    const open = async (s: Supplier) => {
        setDetail({ s, data: null });
        setDetail({
            s,
            data: await api<Detail>('GET', `/admin/suppliers/${s.id}`),
        });
    };

    return (
        <>
            <Head title="Pemasok" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Pemasok"
                    subtitle="Tempat Anda membeli bahan: kontak, riwayat belanja, dan perbandingan harga."
                    actions={
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
                                    setForm({
                                        name: '',
                                        phone: '',
                                        address: '',
                                        note: '',
                                        is_active: true,
                                    })
                                }
                            >
                                <Plus /> Pemasok
                            </Button>
                        </>
                    }
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
                        { id: 'list', label: 'Daftar pemasok' },
                        { id: 'compare', label: 'Bandingkan harga' },
                    ]}
                />

                {tab === 'list' && (
                    <ResponsiveTable
                        headers={[
                            'Pemasok',
                            'Telepon / WA',
                            'Belanja',
                            'Total 30 hari',
                            'Total semua',
                            'Terakhir',
                            'Status',
                            'Aksi',
                        ]}
                        numeric={[2, 3, 4]}
                        empty="Belum ada pemasok."
                        rows={suppliers.map((s) => [
                            <span key={s.id} className="font-medium">
                                {s.name}
                            </span>,
                            s.phone ?? '-',
                            s.purchases,
                            rupiah(s.total_30),
                            rupiah(s.total),
                            s.last_on ? dateId(s.last_on) : '-',
                            s.is_active ? (
                                <span key="a" className="text-emerald-700">
                                    Aktif
                                </span>
                            ) : (
                                <span key="n" className="text-muted-foreground">
                                    Nonaktif
                                </span>
                            ),
                            <span
                                key="x"
                                className="flex flex-wrap justify-end gap-1.5"
                            >
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-9"
                                    onClick={() => void open(s)}
                                >
                                    Riwayat
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-9"
                                    onClick={() =>
                                        setForm({
                                            id: s.id,
                                            name: s.name,
                                            phone: s.phone ?? '',
                                            address: s.address ?? '',
                                            note: s.note ?? '',
                                            is_active: s.is_active,
                                        })
                                    }
                                >
                                    Ubah
                                </Button>
                            </span>,
                        ])}
                    />
                )}

                {tab === 'compare' && (
                    <>
                        <p className="text-sm text-muted-foreground">
                            Harga beli terakhir tiap bahan dari tiap pemasok
                            (per satuan dasar), dari yang termurah. Hanya bahan
                            yang pernah dibeli dengan pemasok tercatat.
                        </p>
                        <ResponsiveTable
                            headers={['Bahan', 'Termurah', 'Harga lain']}
                            empty="Belum ada belanja dengan pemasok tercatat."
                            rows={compare.map((c) => [
                                <span
                                    key={c.ingredient}
                                    className="font-medium"
                                >
                                    {c.ingredient}
                                </span>,
                                <span key="m">
                                    <b>{rupiah(c.offers[0].cost)}</b>/{c.unit} ·{' '}
                                    {c.offers[0].supplier}
                                </span>,
                                c.offers.length > 1 ? (
                                    <span
                                        key="o"
                                        className="text-muted-foreground"
                                    >
                                        {c.offers
                                            .slice(1)
                                            .map(
                                                (o) =>
                                                    `${o.supplier} ${rupiah(o.cost)} (+${Math.round((o.cost / c.offers[0].cost - 1) * 100)}%)`,
                                            )
                                            .join(' · ')}
                                    </span>
                                ) : (
                                    '-'
                                ),
                            ])}
                        />
                    </>
                )}
            </div>

            {form && (
                <Modal
                    open
                    onClose={() => setForm(null)}
                    title={form.id ? 'Ubah pemasok' : 'Pemasok baru'}
                >
                    <Field label="Nama" error={errors?.name}>
                        <Input
                            className="h-11"
                            value={form.name}
                            placeholder="mis. Toko Maju"
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Telepon / WhatsApp">
                        <Input
                            className="h-11"
                            value={form.phone}
                            placeholder="0812…"
                            onChange={(e) =>
                                setForm({ ...form, phone: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Alamat">
                        <Input
                            className="h-11"
                            value={form.address}
                            onChange={(e) =>
                                setForm({ ...form, address: e.target.value })
                            }
                        />
                    </Field>
                    <Field
                        label="Catatan"
                        hint="Mis. bayar tunai, kirim hari Senin, minimal order."
                    >
                        <Input
                            className="h-11"
                            value={form.note}
                            onChange={(e) =>
                                setForm({ ...form, note: e.target.value })
                            }
                        />
                    </Field>
                    {form.id && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="size-5"
                                checked={form.is_active}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        is_active: e.target.checked,
                                    })
                                }
                            />
                            Aktif (muncul di pilihan saat belanja)
                        </label>
                    )}
                    <Button
                        className="h-12"
                        disabled={form.name.trim() === ''}
                        onClick={() => {
                            const o = {
                                preserveScroll: true,
                                onSuccess: () => setForm(null),
                            };

                            if (form.id) {
                                router.put(
                                    `/admin/suppliers/${form.id}`,
                                    { ...form },
                                    o,
                                );
                            } else {
                                router.post('/admin/suppliers', { ...form }, o);
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}

            {detail && (
                <Modal
                    open
                    onClose={() => setDetail(null)}
                    title={detail.s.name}
                    description={
                        [detail.s.phone, detail.s.address]
                            .filter(Boolean)
                            .join(' · ') || 'Riwayat belanja'
                    }
                    className="sm:max-w-2xl"
                >
                    {detail.s.note && (
                        <p className="rounded-lg bg-muted p-3 text-sm">
                            {detail.s.note}
                        </p>
                    )}
                    {detail.s.phone && (
                        <div>
                            <Button asChild variant="outline" className="h-10">
                                <a
                                    href={wa(detail.s.phone)}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <MessageCircle /> Chat WhatsApp
                                </a>
                            </Button>
                        </div>
                    )}
                    {detail.data === null ? (
                        <p className="text-sm text-muted-foreground">Memuat…</p>
                    ) : (
                        <ResponsiveTable
                            headers={['Tanggal', 'Bahan', 'Total', 'Status']}
                            numeric={[2]}
                            empty="Belum ada belanja dari pemasok ini."
                            rows={detail.data.purchases.map((p) => [
                                dateId(p.on),
                                <span
                                    key={p.id}
                                    className="block text-xs leading-5"
                                >
                                    {p.items.map((i, n) => (
                                        <span key={n} className="block">
                                            {i.name} {num(i.qty)} {i.unit} ×{' '}
                                            {rupiah(i.unit_cost)}
                                        </span>
                                    ))}
                                </span>,
                                rupiah(p.total),
                                p.status === 'approved'
                                    ? 'Masuk stok'
                                    : p.status === 'pending'
                                      ? 'Menunggu'
                                      : 'Ditolak',
                            ])}
                        />
                    )}
                </Modal>
            )}

            {importing && (
                <ImportDialog
                    type="suppliers"
                    onClose={() => setImporting(false)}
                    onDone={() => router.reload()}
                />
            )}
        </>
    );
}
