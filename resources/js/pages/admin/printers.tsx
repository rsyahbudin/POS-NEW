import { Head, Link, router } from '@inertiajs/react';
import { Copy, Plus, Printer } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { dateId, timeId } from '@/lib/format';
import { Input } from '@/components/ui/input';
import { InfoNote } from '@/components/pos/info-tip';
import { useFeatures } from '@/hooks/use-features';

type PrinterRow = {
    id: number;
    name: string;
    roles: string[];
    station_id: number | null;
    paper_width: number;
    copies: number;
    is_active: boolean;
    host: string | null;
    port: number;
};
type Bridge = {
    has_token: boolean;
    online: boolean;
    last_seen: string | null;
    url: string;
};
type Failed = {
    id: number;
    printer: string | null;
    kind: string;
    reference: string | null;
    error: string | null;
    at: string | null;
};
type Form = Omit<PrinterRow, 'id'> & { id?: number };

const empty: Form = {
    name: '',
    roles: ['receipt'],
    station_id: null,
    paper_width: 58,
    copies: 1,
    is_active: true,
    host: null,
    port: 9100,
};

export default function PrintersPage({
    printers,
    stations,
    kinds,
    bridge,
    failed,
}: {
    printers: PrinterRow[];
    stations: { id: number; name: string }[];
    kinds: { key: string; label: string }[];
    bridge: Bridge;
    failed: Failed[];
}) {
    const features = useFeatures();
    const [token, setToken] = useState<string | null>(null);
    const [form, setForm] = useState<Form | null>(null);
    const label = (k: string) => kinds.find((x) => x.key === k)?.label ?? k;
    const stationName = (id: number | null) =>
        id ? (stations.find((s) => s.id === id)?.name ?? '') : '';

    const makeToken = async () => {
        if (
            bridge.has_token &&
            !confirm(
                'Token lama berhenti berlaku. Jembatan cetak yang sedang jalan harus diberi token baru. Lanjutkan?',
            )
        ) {
            return;
        }

        const res = await api<{ token: string }>(
            'POST',
            '/admin/printers/bridge-token',
        );
        setToken(res.token);
        router.reload({ only: ['bridge'] });
    };
    const command = `node print-bridge.mjs --url ${bridge.url} --token ${token ?? 'TOKEN'}`;
    // The same start script as bridge/start-print-bridge.bat with the address and token already filled in.
    const downloadBat = () => {
        const bat = [
            '@echo off',
            `set PRINT_BRIDGE_URL=${bridge.url}`,
            `set PRINT_BRIDGE_TOKEN=${token}`,
            '',
            'set NODE=node',
            'where node >nul 2>nul || set "NODE=%ProgramFiles%\\nodejs\\node.exe"',
            'cd /d "%~dp0"',
            ':loop',
            '"%NODE%" print-bridge.mjs >> print-bridge.log 2>&1',
            'rem Exit code 2 means the token was rejected: stop instead of retrying forever.',
            'if %errorlevel%==2 exit /b 2',
            'ping -n 6 127.0.0.1 >nul',
            'goto loop',
            '',
        ].join('\r\n');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([bat], { type: 'text/plain' }));
        a.download = 'start-print-bridge.bat';
        a.click();
        URL.revokeObjectURL(a.href);
    };

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
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
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

                <InfoNote>
                    Belum ada printer di sini = struk dicetak langsung dari
                    perangkat kasir (pengaturan printer di layar kasir). Setelah
                    ada printer, pesanan, bill, dan struk masuk antrean cetak
                    dan dicetak oleh perangkat yang membuka Stasiun Cetak, atau
                    oleh jembatan cetak bila printernya punya alamat IP. Printer
                    Bluetooth waiter jangan didaftarkan di sini: pairing di
                    tablet masing-masing agar pre-bill keluar dari printer
                    waiter yang menekannya.
                </InfoNote>

                <ResponsiveTable
                    headers={[
                        'Printer',
                        'Mencetak',
                        ...(features.stations ? ['Station'] : []),
                        'Koneksi',
                        'Kertas',
                        'Salinan',
                        'Status',
                        'Aksi',
                    ]}
                    numeric={[features.stations ? 5 : 4]}
                    empty="Belum ada printer."
                    rows={printers.map((p) => [
                        <span key={p.id} className="font-medium">
                            {p.name}
                        </span>,
                        p.roles.map(label).join(', '),
                        ...(features.stations
                            ? [
                                  p.roles.includes('ticket')
                                      ? stationName(p.station_id) ||
                                        'Semua station'
                                      : '-',
                              ]
                            : []),
                        p.host ? `LAN ${p.host}:${p.port}` : 'Perangkat',
                        `${p.paper_width} mm`,
                        `${p.copies}×`,
                        p.is_active ? (
                            <span
                                key="a"
                                className="text-emerald-700 dark:text-emerald-400"
                            >
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

                <section className="grid gap-3 rounded-xl border bg-card p-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="font-semibold">
                                Jembatan cetak (printer LAN)
                            </h2>
                            <InfoNote>
                                iPad dan tablet Android tidak bisa mencetak ke
                                printer LAN. Jalankan jembatan di satu perangkat
                                yang selalu menyala di cafe (mini PC, Raspberry
                                Pi, laptop); ia mengambil antrean dari server
                                dan mengirimnya ke printer lewat IP.
                            </InfoNote>
                        </div>
                        <span
                            className={`rounded-full border px-3 py-1 text-sm font-medium ${bridge.online ? 'border-emerald-600 text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'}`}
                        >
                            {bridge.online
                                ? 'Terhubung'
                                : bridge.last_seen
                                  ? `Terakhir aktif ${dateId(bridge.last_seen)} ${timeId(bridge.last_seen)}`
                                  : bridge.has_token
                                    ? 'Belum pernah terhubung'
                                    : 'Belum disiapkan'}
                        </span>
                    </div>
                    <ol className="grid list-decimal gap-1 pl-5 text-sm">
                        <li>
                            Pasang Node.js 18 atau lebih baru di perangkat itu,
                            lalu unduh{' '}
                            <a
                                className="underline"
                                href="/print-bridge/print-bridge.mjs"
                                download
                            >
                                print-bridge.mjs
                            </a>
                            .
                        </li>
                        <li>
                            Beri IP tetap ke tiap printer LAN, tambahkan di
                            tabel di atas dengan alamat IP dan port 9100.
                        </li>
                        <li>
                            Di Windows, taruh semua file di satu folder, mis.{' '}
                            <code>C:\pos-bridge</code> (jangan di System32).
                        </li>
                        <li>
                            Buat token, lalu unduh start-print-bridge.bat yang
                            sudah berisi token itu, taruh di folder yang sama,
                            dan jalankan.
                        </li>
                    </ol>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            className="h-11"
                            onClick={() => void makeToken()}
                        >
                            {bridge.has_token
                                ? 'Buat token baru'
                                : 'Buat token'}
                        </Button>
                    </div>
                    {token && (
                        <div className="grid gap-2 rounded-lg bg-muted p-3">
                            <InfoNote>
                                Token hanya tampil sekali. Salin sekarang.
                            </InfoNote>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    className="h-10 w-fit"
                                    onClick={downloadBat}
                                >
                                    Unduh start-print-bridge.bat
                                </Button>
                            </div>
                            <InfoNote>
                                Atau jalankan manual dari folder jembatan:
                            </InfoNote>
                            <code className="text-sm break-all">{command}</code>
                            <Button
                                variant="outline"
                                className="h-10 w-fit"
                                onClick={() =>
                                    void navigator.clipboard.writeText(command)
                                }
                            >
                                <Copy /> Salin perintah
                            </Button>
                        </div>
                    )}
                </section>

                {failed.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            Gagal dicetak lewat jembatan
                        </h2>
                        <ResponsiveTable
                            headers={['Printer', 'Cetakan', 'Sebab', 'Aksi']}
                            numeric={[]}
                            empty=""
                            rows={failed.map((j) => [
                                j.printer ?? '-',
                                `${j.kind} ${j.reference ?? ''}`,
                                j.error ?? '-',
                                <span key={j.id} className="flex justify-end">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="h-9"
                                        onClick={() =>
                                            router.post(
                                                `/admin/printers/jobs/${j.id}/retry`,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Cetak ulang
                                    </Button>
                                </span>,
                            ])}
                        />
                    </section>
                )}
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
                    <Field
                        label="Mencetak apa"
                        hint="Satu printer boleh mencetak beberapa hal. Centang semua yang dicetak printer ini."
                    >
                        <div className="grid gap-2">
                            {kinds.map((k) => (
                                <label
                                    key={k.key}
                                    className="flex min-h-11 items-center gap-2 rounded-lg border px-3 text-sm"
                                >
                                    <input
                                        type="checkbox"
                                        className="size-5"
                                        checked={form.roles.includes(k.key)}
                                        onChange={(e) =>
                                            setForm({
                                                ...form,
                                                roles: e.target.checked
                                                    ? [...form.roles, k.key]
                                                    : form.roles.filter(
                                                          (x) => x !== k.key,
                                                      ),
                                            })
                                        }
                                    />
                                    {k.label}
                                </label>
                            ))}
                        </div>
                    </Field>
                    {features.stations && form.roles.includes('ticket') && (
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
                    <div className="grid grid-cols-[1fr_6rem] gap-2">
                        <Field
                            label="Alamat IP printer LAN (opsional)"
                            hint="Isi untuk printer jaringan yang dilayani jembatan cetak. Kosongkan untuk printer yang tersambung ke perangkat (Bluetooth, USB, QZ Tray)."
                        >
                            <Input
                                className="h-11"
                                inputMode="decimal"
                                value={form.host ?? ''}
                                placeholder="192.168.1.50"
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        host: e.target.value.trim() || null,
                                    })
                                }
                            />
                        </Field>
                        <Field label="Port">
                            <Input
                                className="h-11"
                                type="number"
                                min={1}
                                max={65535}
                                value={form.port}
                                onChange={(e) =>
                                    setForm({
                                        ...form,
                                        port: Number(e.target.value) || 9100,
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
                        disabled={
                            form.name.trim() === '' || form.roles.length === 0
                        }
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
