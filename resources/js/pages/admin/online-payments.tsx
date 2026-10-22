import { Head, Link, router } from '@inertiajs/react';
import { PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, rupiah, timeId } from '@/lib/format';

type Row = {
    id: number;
    at: string | null;
    order: string;
    provider: string;
    amount: number;
    status: string;
    applied: boolean;
    error: string | null;
    ref: string | null;
    simulatable: boolean;
};

const STATUS: Record<string, string> = {
    pending: 'Menunggu bayar',
    paid: 'Dibayar',
    expired: 'Kedaluwarsa',
    failed: 'Gagal',
};

export default function OnlinePaymentsPage({
    enabled,
    webhook,
    rows,
}: {
    enabled: boolean;
    webhook: string;
    rows: Row[];
}) {
    const opts = { preserveScroll: true };

    return (
        <>
            <Head title="Pembayaran Online" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Pembayaran online (QRIS)"
                    subtitle="Pelanggan membayar dari HP lewat QR meja. Pembayaran yang masuk otomatis tercatat lunas atas nama Pembayaran Online."
                    actions={
                        <Button asChild variant="outline" className="h-11">
                            <Link href="/admin/settings">Pengaturan QRIS</Link>
                        </Button>
                    }
                />
                {!enabled && (
                    <section className="grid gap-2 rounded-xl border bg-card p-4 text-sm">
                        <h2 className="font-semibold">Cara menyalakan</h2>
                        <ol className="list-decimal space-y-1 pl-5">
                            <li>
                                Daftar akun merchant di payment gateway
                                (disarankan Midtrans, midtrans.com). Dokumen
                                usaha diverifikasi dulu; mode Sandbox bisa
                                dipakai langsung untuk uji coba.
                            </li>
                            <li>
                                Di dashboard Midtrans: Settings › Access Keys,
                                salin Server Key.
                            </li>
                            <li>
                                Di dashboard Midtrans: Settings › Configuration
                                › Payment Notification URL, isi alamat di bawah.
                            </li>
                            <li>
                                Di Pengaturan › QRIS Online: tempel Server Key,
                                aktifkan, lalu simpan. Untuk mencoba tanpa
                                gateway, pilih Simulasi.
                            </li>
                        </ol>
                    </section>
                )}
                <p className="text-sm">
                    Alamat notifikasi untuk gateway:{' '}
                    <code className="rounded bg-muted px-1.5 py-0.5">
                        {webhook}
                    </code>
                </p>
                <ResponsiveTable
                    headers={[
                        'Waktu',
                        'Order',
                        'Nominal',
                        'Status',
                        'Tercatat',
                        'Catatan',
                        'Aksi',
                    ]}
                    numeric={[2]}
                    empty="Belum ada pembayaran online."
                    rows={rows.map((r) => [
                        r.at ? `${dateId(r.at)} ${timeId(r.at)}` : '-',
                        r.order,
                        rupiah(r.amount),
                        STATUS[r.status] ?? r.status,
                        r.status === 'paid' ? (
                            r.applied ? (
                                'Ya'
                            ) : (
                                <span
                                    key="n"
                                    className="font-medium text-red-700"
                                >
                                    Belum
                                </span>
                            )
                        ) : (
                            '-'
                        ),
                        r.error ?? '-',
                        <span key="a" className="flex justify-end gap-1.5">
                            {r.status === 'paid' && !r.applied && (
                                <Button
                                    size="sm"
                                    className="h-9"
                                    onClick={() =>
                                        router.post(
                                            `/admin/online-payments/${r.id}/retry`,
                                            {},
                                            opts,
                                        )
                                    }
                                >
                                    Catat ulang
                                </Button>
                            )}
                            {r.simulatable && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-9"
                                    onClick={() =>
                                        router.post(
                                            `/admin/online-payments/${r.id}/simulate`,
                                            {},
                                            opts,
                                        )
                                    }
                                >
                                    Simulasikan lunas
                                </Button>
                            )}
                        </span>,
                    ])}
                />
            </div>
        </>
    );
}
