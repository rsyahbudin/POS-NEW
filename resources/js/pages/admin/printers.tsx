import { Head, Link, router } from '@inertiajs/react';
import { Plus, Printer } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type PrinterRow = {
    id: number;
    name: string;
    kind: string;
    station_id: number | null;
    paper_width: number;
    copies: number;
    is_active: boolean;
};
type Form = Omit<PrinterRow, 'id'> & { id?: number };

const empty: Form = {
    name: '',
    kind: 'receipt',
    station_id: null,
    paper_width: 58,
    copies: 1,
    is_active: true,
};

export default function PrintersPage({
    printers,
    stations,
    kinds,
}: {
    printers: PrinterRow[];
    stations: { id: number; name: string }[];
    kinds: { key: string; label: string }[];
}) {
    const [form, setForm] = useState<Form | null>(null);
    const label = (k: string) => kinds.find((x) => x.key === k)?.label ?? k;
    const stationName = (id: number | null) =>
        id ? (stations.find((s) => s.id === id)?.name ?? '') : '';

    const save = () => {
        if (!form) {
            return;
        }

        const opts = { preserveScroll: true, onSuccess: () => setForm(null) };

        if (form.id) {
            router.put(`/admin/printers/${form.id}`, { ...form }, opts);
        } else {
            router.post('/admin/printers', { ...form }, opts);
        }
    };

    return (
        <>
            <Head title="Printer" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Printer"
                    subtitle="Daftar printer dan apa yang dicetak masing-masing. Printer disambungkan ke perangkat di halaman Stasiun Cetak."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <Link href="/print-station">Stasiun Cetak</Link>
                            </Button>
                            <Button
                                className="h-11"
                                onClick={() => setForm({ ...empty })}
                            >
                                <Plus /> Tambah printer
                            </Button>
                        </>
                    }
                />

                <p className="rounded-lg bg-muted p-3 text-sm">
                    Belum ada printer di sini = struk dicetak langsung dari
                    perangkat kasir (pengaturan printer di layar kasir). Setelah
                    ada printer, pesanan, bill, dan struk masuk antrean cetak
                    dan dicetak oleh perangkat yang membuka Stasiun Cetak.
                </p>

                <ResponsiveTable
                    headers={[
                        'Printer',
                        'Mencetak',
                        'Station',
                        'Kertas',
                        'Salinan',
                        'Status',
                        'Aksi',
                    ]}
                    numeric={[4]}
                    empty="Belum ada printer."
                    rows={printers.map((p) => [
                        <span key={p.id} className="font-medium">
                            {p.name}
                        </span>,
                        label(p.kind),
                        p.kind === 'ticket'
                            ? stationName(p.station_id) || 'Semua station'
                            : '-',
                        `${p.paper_width} mm`,
                        `${p.copies}×`,
                        p.is_active ? (
                            <span key="a" className="text-emerald-700">
                                Aktif
                            </span>
                        ) : (
                            <span key="n" className="text-muted-foreground">
                                Nonaktif
                            </span>
                        ),
                        <span key="e" className="flex justify-end">
                            <Button
                                size="sm"
                                variant="outline"
                                className="h-9"
                                onClick={() => setForm({ ...p })}
                            >
                                Ubah
                            </Button>
                        </span>,
                    ])}
                />
            </div>

            {form && (
                <Modal
                    open
                    onClose={() => setForm(null)}
                    title={form.id ? 'Ubah printer' : 'Tambah printer'}
                >
                    <Field label="Nama printer">
                        <Input
                            className="h-11"
                            value={form.name}
                            placeholder="mis. Printer Bar"
                            onChange={(e) =>
                                setForm({ ...form, name: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Mencetak">
                        <Select
                            value={form.kind}
                            onChange={(e) =>
                                setForm({ ...form, kind: e.target.value })
                            }
                        >
                            {kinds.map((k) => (
                                <option key={k.key} value={k.key}>
                                    {k.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    {form.kind === 'ticket' && (
                        <Field
                            label="Station"
                            hint="Printer ini hanya mencetak pesanan dari station yang dipilih (mis. Bar atau Dapur)."
                        >
                            <Select
                                value={form.station_id ?? ''}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        station_id: e.target.value
                                            ? Number(e.target.value)
                                            : null,
                                    })
                                }
                            >
                                <option value="">Semua station</option>
                                {stations.map((s) => (
                                    <option key={s.id} value={s.id}>
                                        {s.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Lebar kertas">
                            <Select
                                value={form.paper_width}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        paper_width: Number(e.target.value),
                                    })
                                }
                            >
                                <option value={58}>58 mm</option>
                                <option value={80}>80 mm</option>
                            </Select>
                        </Field>
                        <Field label="Jumlah salinan">
                            <Input
                                className="h-11"
                                type="number"
                                min={1}
                                max={5}
                                value={form.copies}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        copies: Math.max(
                                            1,
                                            Math.min(
                                                5,
                                                Number(e.target.value) || 1,
                                            ),
                                        ),
                                    })
                                }
                            />
                        </Field>
                    </div>
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
                        Aktif
                    </label>
                    <Button
                        className="h-12"
                        disabled={form.name.trim() === ''}
                        onClick={save}
                    >
                        Simpan
                    </Button>
                    {form.id && (
                        <Button
                            variant="ghost"
                            className="h-11 text-destructive"
                            onClick={() =>
                                confirm(`Hapus printer ${form.name}?`) &&
                                router.delete(`/admin/printers/${form.id}`, {
                                    preserveScroll: true,
                                    onSuccess: () => setForm(null),
                                })
                            }
                        >
                            Hapus printer
                        </Button>
                    )}
                </Modal>
            )}
        </>
    );
}
