import { Head } from '@inertiajs/react';
import { dateId, rupiah, timeId } from '@/lib/format';

type Props = {
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
        summary: {
            name: string;
            qty: number;
            total: number;
            options: string;
        }[];
        total: number;
        note: string | null;
    };
};

const STATUS: Record<string, string> = {
    booked: 'Dipesan',
    seated: 'Sudah duduk',
    cancelled: 'Dibatalkan',
    no_show: 'Tidak datang',
};

/** What the guest keeps after booking: the code to show at the door, the time, the tables and the pre-order. */
export default function Status({ outlet, reservation: r }: Props) {
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

                <section className="grid gap-2 rounded-2xl border bg-card p-4 text-sm">
                    <Row label="Atas nama" value={r.name} />
                    <Row
                        label="Waktu"
                        value={`${dateId(r.starts_at)} · ${timeId(r.starts_at)}`}
                    />
                    <Row label="Jumlah" value={`${r.pax} orang`} />
                    <Row
                        label="Meja"
                        value={
                            r.tables.length > 0
                                ? r.tables.join(' + ')
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
                        didudukkan. Pembayaran dilakukan di kasir. Kalau mau
                        menambah menu, pindai QR di mejamu: semuanya masuk ke
                        satu bill.
                    </p>
                </section>

                <section className="grid gap-1 text-sm text-muted-foreground">
                    {outlet.address && <p>{outlet.address}</p>}
                    {(outlet.whatsapp || outlet.phone) && (
                        <p>
                            Perlu mengubah atau membatalkan? Hubungi{' '}
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
