import { Head, Link } from '@inertiajs/react';
import { dateId, rupiah, timeId } from '@/lib/format';

type Props = {
    paymentInfo: string;
    changeUntil: string;
    fullMenuFrom: string;
    outlet: {
        name: string | null;
        phone: string | null;
        whatsapp: string | null;
        maps_url: string | null;
        address: string | null;
    };
    reservation: {
        code: string;
        name: string;
        pax: number;
        status: string;
        starts_at: string;
        tables: string[];
        area: string | null;
        tight: boolean;
        summary: {
            name: string;
            qty: number;
            total: number;
            options: string;
        }[];
        total: number;
        note: string | null;
        payment_type: 'dp' | 'full';
        amount_due: number;
        paid: number;
        package_short: boolean;
        can_change: boolean;
        full_menu: boolean;
    };
};

const STATUS: Record<string, string> = {
    pending: 'Menunggu pembayaran',
    booked: 'Terkonfirmasi',
    seated: 'Sudah duduk',
    cancelled: 'Dibatalkan',
    no_show: 'Tidak datang',
};

/** What the guest keeps after booking: the code to show at the door, the time, the tables and the pre-order. */
export default function Status({
    outlet,
    reservation: r,
    paymentInfo,
    changeUntil,
    fullMenuFrom,
}: Props) {
    const left = Math.max(0, r.amount_due - r.paid);
    return (
        <>
            <Head title={`Reservasi ${r.code}`} />
            <div className="mx-auto grid min-h-dvh w-full max-w-xl content-start gap-5 bg-background px-4 py-8 text-foreground">
                <header>
                    <p className="text-sm text-muted-foreground">
                        {outlet.name}
                    </p>
                    <h1 className="text-2xl font-bold tracking-tight">
                        Reservasi {STATUS[r.status]?.toLowerCase() ?? ''}
                    </h1>
                </header>

                <section className="grid gap-1 rounded-2xl border bg-card p-5 text-center">
                    <p className="text-sm text-muted-foreground">
                        Tunjukkan kode ini ke kasir
                    </p>
                    <p className="text-4xl font-bold tracking-[0.2em]">
                        {r.code}
                    </p>
                </section>

                {r.status === 'pending' && (
                    <section
                        role="status"
                        className="grid gap-1 rounded-2xl border border-amber-500/60 bg-amber-50 p-4 text-sm text-amber-950 dark:bg-amber-950/40 dark:text-amber-100"
                    >
                        <p className="font-semibold">
                            Bayar {r.payment_type === 'full' ? 'lunas' : 'DP'}{' '}
                            {rupiah(r.amount_due)} untuk mengesahkan reservasi
                        </p>
                        {paymentInfo && <p>{paymentInfo}</p>}
                        <p>
                            Pengelola mengonfirmasi setelah pembayaran diterima.
                            Sebelum itu tempatmu belum pasti.
                        </p>
                        {r.package_short && (
                            <p className="font-medium">
                                Paket kurang dari jumlah orang: perlu
                                persetujuan pengelola.
                            </p>
                        )}
                    </section>
                )}
                {r.status === 'booked' && (
                    <section className="rounded-2xl border border-emerald-600/50 bg-emerald-50 p-4 text-sm text-emerald-950 dark:bg-emerald-950/40 dark:text-emerald-100">
                        Pembayaran {rupiah(r.paid)} sudah diterima.
                        {left > 0 && ` Sisa ${rupiah(left)} dibayar di kasir.`}
                    </section>
                )}

                <section className="grid gap-2 rounded-2xl border bg-card p-4 text-sm">
                    <Row label="Atas nama" value={r.name} />
                    <Row
                        label="Waktu"
                        value={`${dateId(r.starts_at)} · ${timeId(r.starts_at)}`}
                    />
                    <Row label="Jumlah" value={`${r.pax} orang`} />
                    <Row
                        label="Pembayaran"
                        value={
                            r.payment_type === 'full'
                                ? 'Lunas'
                                : `DP ${rupiah(r.amount_due)}`
                        }
                    />
                    {r.area && <Row label="Area" value={r.area} />}
                    <Row
                        label="Tempat"
                        value={
                            r.tables.length > 0
                                ? `${r.tables.join(' + ')}${r.tight ? ' (agak padat)' : ''}`
                                : 'Diatur kasir'
                        }
                    />
                    <Row label="Status" value={STATUS[r.status] ?? r.status} />
                    {r.note && <Row label="Catatan" value={r.note} />}
                </section>

                <section className="grid gap-2 rounded-2xl border bg-card p-4">
                    <h2 className="font-semibold">Pesanan</h2>
                    <ul className="grid gap-1.5 text-sm">
                        {r.summary.map((l, i) => (
                            <li key={i} className="flex justify-between gap-3">
                                <span>
                                    {l.qty}× {l.name}
                                    {l.options && (
                                        <span className="block text-xs text-muted-foreground">
                                            {l.options}
                                        </span>
                                    )}
                                </span>
                                <span className="tabular-nums">
                                    {rupiah(l.total)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p className="flex justify-between border-t pt-2 font-semibold">
                        <span>Perkiraan total</span>
                        <span className="tabular-nums">{rupiah(r.total)}</span>
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Pesanan diteruskan ke dapur saat kamu tiba dan
                        didudukkan. Menu biasa bisa dipesan lewat QR di mejamu
                        mulai pukul {fullMenuFrom}
                        {r.full_menu ? ' (sudah dibuka untukmu)' : ''}: semuanya
                        masuk ke satu bill.
                    </p>
                </section>

                {r.can_change && (
                    <section className="grid gap-2 rounded-2xl border bg-card p-4 text-sm">
                        <p className="text-muted-foreground">
                            Jumlah orang, pesanan, atau pembatalan bisa kamu
                            ubah sendiri sampai pukul {changeUntil} di hari-H.
                        </p>
                        <Link
                            href={`/reservasi/${r.code}/ubah`}
                            className="inline-flex h-12 items-center justify-center rounded-xl bg-primary px-5 font-semibold text-primary-foreground"
                        >
                            Ubah atau batalkan
                        </Link>
                    </section>
                )}

                <section className="grid gap-1 text-sm text-muted-foreground">
                    {outlet.address && <p>{outlet.address}</p>}
                    {(outlet.whatsapp || outlet.phone) && (
                        <p>
                            {r.can_change
                                ? 'Perlu bantuan? Hubungi '
                                : 'Perubahan sekarang lewat pengelola. Hubungi '}
                            {outlet.whatsapp ?? outlet.phone}.
                        </p>
                    )}
                    {outlet.maps_url && (
                        <a
                            className="font-semibold text-primary underline"
                            href={outlet.maps_url}
                            target="_blank"
                            rel="noreferrer"
                        >
                            Petunjuk arah
                        </a>
                    )}
                </section>
            </div>
        </>
    );
}

function Row({ label, value }: { label: string; value: string }) {
    return (
        <p className="flex justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="text-right font-medium">{value}</span>
        </p>
    );
}
