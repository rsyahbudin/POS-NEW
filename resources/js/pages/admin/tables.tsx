import { Head, router } from '@inertiajs/react';
import { Plus, Printer, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { Field, PageHeader, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Table = {
    id: number;
    name: string;
    capacity: number;
    qr_enabled: boolean;
    status: string;
    qr_url: string;
};
type Area = { id: number; name: string; tables: Table[] };

export default function TablesPage({ areas }: { areas: Area[] }) {
    const [area, setArea] = useState('');
    const [form, setForm] = useState<{
        id?: number;
        name: string;
        capacity: number;
        area_id: number;
    } | null>(null);

    return (
        <>
            <Head title="Meja & QR" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Area, meja & QR"
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href="/admin/tables-qr.pdf"
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer /> Cetak semua QR (PDF)
                                </a>
                            </Button>
                            <Button
                                className="h-11"
                                disabled={!areas.length}
                                onClick={() =>
                                    setForm({
                                        name: '',
                                        capacity: 4,
                                        area_id: areas[0].id,
                                    })
                                }
                            >
                                <Plus /> Meja
                            </Button>
                        </>
                    }
                />

                <div className="flex max-w-md gap-2">
                    <Input
                        className="h-11"
                        placeholder="Area baru (mis. Lantai 2)"
                        value={area}
                        onChange={(e) => setArea(e.target.value)}
                    />
                    <Button
                        className="h-11"
                        disabled={!area.trim()}
                        onClick={() =>
                            router.post(
                                '/admin/areas',
                                { name: area },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setArea(''),
                                },
                            )
                        }
                    >
                        Tambah area
                    </Button>
                </div>

                {areas.map((a) => (
                    <section key={a.id} aria-label={a.name}>
                        <div className="mb-2 flex items-center justify-between">
                            <h2 className="font-semibold">{a.name}</h2>
                            <Button
                                asChild
                                variant="ghost"
                                size="sm"
                                className="h-10"
                            >
                                <a
                                    href={`/admin/tables-qr.pdf?area_id=${a.id}`}
                                    target="_blank"
                                    rel="noreferrer"
                                >
                                    <Printer /> QR area
                                </a>
                            </Button>
                        </div>
                        <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                            {a.tables.map((t) => (
                                <div
                                    key={t.id}
                                    className="rounded-xl border bg-card p-3"
                                >
                                    <p className="font-medium">
                                        {t.name}{' '}
                                        <span className="text-xs text-muted-foreground">
                                            · {t.capacity} kursi · {t.status}
                                        </span>
                                    </p>
                                    <p className="mt-1 truncate text-xs text-muted-foreground">
                                        {t.qr_url}
                                    </p>
                                    <div className="mt-2 flex flex-wrap gap-2">
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            className="h-10"
                                            onClick={() =>
                                                setForm({
                                                    id: t.id,
                                                    name: t.name,
                                                    capacity: t.capacity,
                                                    area_id: a.id,
                                                })
                                            }
                                        >
                                            Ubah
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant={
                                                t.qr_enabled
                                                    ? 'outline'
                                                    : 'destructive'
                                            }
                                            className="h-10"
                                            aria-pressed={!t.qr_enabled}
                                            onClick={() =>
                                                fetch(
                                                    `/pos/tables/${t.id}/qr`,
                                                    {
                                                        method: 'POST',
                                                        headers: {
                                                            'Content-Type':
                                                                'application/json',
                                                            Accept: 'application/json',
                                                            'X-XSRF-TOKEN':
                                                                decodeURIComponent(
                                                                    document.cookie.match(
                                                                        /XSRF-TOKEN=([^;]+)/,
                                                                    )?.[1] ??
                                                                        '',
                                                                ),
                                                        },
                                                        body: JSON.stringify({
                                                            qr_enabled:
                                                                !t.qr_enabled,
                                                        }),
                                                    },
                                                ).then(() => router.reload())
                                            }
                                        >
                                            {t.qr_enabled
                                                ? 'QR aktif'
                                                : 'QR nonaktif'}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-10"
                                            onClick={() =>
                                                confirm(
                                                    `Buat ulang QR ${t.name}? QR lama langsung tidak berlaku.`,
                                                ) &&
                                                router.post(
                                                    `/admin/tables/${t.id}/regenerate-token`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <RefreshCw /> Token baru
                                        </Button>
                                        <Button
                                            asChild
                                            size="sm"
                                            variant="ghost"
                                            className="h-10"
                                        >
                                            <a
                                                href={`/admin/tables-qr.pdf?table_id=${t.id}`}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <Printer /> PDF
                                            </a>
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="h-10"
                                            onClick={() =>
                                                confirm(`Hapus ${t.name}?`) &&
                                                router.delete(
                                                    `/admin/tables/${t.id}`,
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
                    </section>
                ))}
            </div>

            {form && (
                <Modal
                    open
                    onClose={() => setForm(null)}
                    title={form.id ? 'Ubah meja' : 'Meja baru'}
                >
                    <Field label="Nama meja">
                        <Input
                            className="h-11"
                            value={form.name}
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Kapasitas kursi">
                        <Input
                            className="h-11"
                            type="number"
                            min={1}
                            value={form.capacity}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    capacity: Number(e.target.value),
                                })
                            }
                        />
                    </Field>
                    <Field label="Area">
                        <Select
                            value={form.area_id}
                            onChange={(e) =>
                                setForm({
                                    ...form,
                                    area_id: Number(e.target.value),
                                })
                            }
                        >
                            {areas.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button
                        className="h-12"
                        disabled={!form.name.trim()}
                        onClick={() => {
                            const o = {
                                preserveScroll: true,
                                onSuccess: () => setForm(null),
                            };
                            if (form.id) {
                                router.put(`/admin/tables/${form.id}`, form, o);
                            } else {
                                router.post('/admin/tables', form, o);
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}
        </>
    );
}
