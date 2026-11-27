import { Head, router } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    MessageCircle,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah, timeId } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    code: string;
    name: string;
    phone: string;
    pax: number;
    status: 'pending' | 'booked' | 'seated' | 'cancelled' | 'no_show';
    note: string | null;
    starts_at: string;
    tables: { id: number; name: string; capacity: number }[];
    summary: { name: string; qty: number; total: number; options: string }[];
    total: number;
    order: string | null;
    payment_type: 'dp' | 'full';
    amount_due: number;
    paid: number;
    package_short: boolean;
    package_waived: boolean;
    full_menu: boolean;
    deposit_applied: boolean;
    changed_at: string | null;
};
type Day = {
    open: boolean;
    reason: string | null;
    capacity: number;
    remaining: number;
    booked: number;
    time: string;
};
type TableRow = { id: number; name: string; capacity: number; area: string };

const STATUS: Record<Row['status'], { label: string; cls: string }> = {
    pending: {
        label: 'Menunggu pembayaran',
        cls: 'bg-red-100 text-red-900 dark:bg-red-950/50 dark:text-red-200',
    },
    booked: {
        label: 'Terkonfirmasi',
        cls: 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
    },
    seated: {
        label: 'Sudah duduk',
        cls: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200',
    },
    cancelled: { label: 'Batal', cls: 'bg-muted text-muted-foreground' },
    no_show: { label: 'Tidak datang', cls: 'bg-muted text-muted-foreground' },
};

const shift = (date: string, days: number) => {
    const d = new Date(`${date}T00:00:00`);
    d.setDate(d.getDate() + days);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** WhatsApp wants the number with the country code and no zero in front. */
const wa = (phone: string, text = '') =>
    `https://wa.me/${phone.replace(/\D/g, '').replace(/^0/, '62')}${text ? `?text=${encodeURIComponent(text)}` : ''}`;

/** The message customer service sends: what is booked, what to pay or that it was received, and the page to come back to. */
const message = (r: Row, outlet: string | null) =>
    `Halo ${r.name}, reservasi buka puasa${outlet ? ` di ${outlet}` : ''} ${dateId(r.starts_at)} pukul ${timeId(r.starts_at)} untuk ${r.pax} orang (kode ${r.code}). ` +
    (r.status === 'pending'
        ? `Mohon ${r.payment_type === 'full' ? 'pelunasan' : 'DP'} ${rupiah(r.amount_due)}. Setelah transfer, kirim buktinya ke chat ini ya. `
        : `Pembayaran ${rupiah(r.paid)} sudah kami terima, reservasi terkonfirmasi. `) +
    `Detail dan perubahan: ${window.location.origin}/reservasi/${r.code}`;

export default function Reservations({
    date,
    link,
    enabled,
    canSeat,
    outletName,
    day,
    reservations,
    tables,
}: {
    date: string;
    link: string;
    enabled: boolean;
    canSeat: boolean;
    outletName: string | null;
    day: Day;
    reservations: Row[];
    tables: TableRow[];
}) {
    const [moving, setMoving] = useState<{ r: Row; ids: number[] } | null>(
        null,
    );
    const [paying, setPaying] = useState<{
        r: Row;
        amount: string;
        waive: boolean;
    } | null>(null);
    const go = (d: string) =>
        router.get('/reservations', { date: d }, { preserveScroll: true });
    const post = (url: string, data: Record<string, unknown> = {}) =>
        router.post(url, data as never, { preserveScroll: true });
    const live = (r: Row) => r.status === 'pending' || r.status === 'booked';
    const unpaid = reservations.filter((r) => r.status === 'pending').length;
    const noTable = reservations.filter(
        (r) => live(r) && r.tables.length === 0,
    ).length;
    const over = day.booked > day.capacity;

    return (
        <>
            <Head title="Reservasi" />
            <div className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
                <PageHeader
                    title="Reservasi"
                    subtitle="Tamu memesan sendiri lewat link reservasi, lalu membayar DP atau lunas. Konfirmasi pembayarannya, atur meja, dan tekan Duduk saat mereka datang: pesanan dibuka di meja pertama, meja lain bergabung ke satu bill, dan menu langsung ke dapur."
                    actions={
                        <>
                            <Button asChild className="h-11">
                                <a href="/reservations/baru">
                                    <Plus /> Reservasi baru
                                </a>
                            </Button>
                            <Button
                                variant="outline"
                                className="h-11"
                                onClick={() => {
                                    void navigator.clipboard
                                        .writeText(link)
                                        .then(() =>
                                            toast.success(
                                                'Link reservasi disalin.',
                                            ),
                                        );
                                }}
                            >
                                <Copy /> Salin link
                            </Button>
                        </>
                    }
                />

                {!enabled && (
                    <p className="rounded-lg bg-amber-100 p-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                        Reservasi belum dinyalakan. Aktifkan di Pengaturan &gt;
                        Reservasi dan isi tanggal Ramadan.
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Hari sebelumnya"
                        onClick={() => go(shift(date, -1))}
                    >
                        <ChevronLeft />
                    </Button>
                    <Input
                        type="date"
                        className="h-11 w-44"
                        value={date}
                        onChange={(e) => e.target.value && go(e.target.value)}
                        aria-label="Tanggal"
                    />
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Hari berikutnya"
                        onClick={() => go(shift(date, 1))}
                    >
                        <ChevronRight />
                    </Button>
                    <span className="ml-auto text-sm text-muted-foreground">
                        {dateId(date)} · jam {day.time}
                    </span>
                </div>

                <section
                    className={cn(
                        'grid gap-2 rounded-xl border p-3',
                        over && 'border-destructive',
                    )}
                    aria-label="Kuota hari ini"
                >
                    <div className="flex flex-wrap items-baseline justify-between gap-2">
                        <p className="text-lg font-semibold tabular-nums">
                            {day.booked} dari {day.capacity} orang
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {over
                                ? `Melebihi kuota ${day.booked - day.capacity} orang`
                                : day.open
                                  ? `Sisa ${day.remaining} · pemesanan baru masih dibuka`
                                  : (day.reason ?? 'Ditutup')}
                        </p>
                    </div>
                    <div
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuenow={day.booked}
                        aria-valuemax={day.capacity}
                    >
                        <div
                            className={cn(
                                'h-full',
                                over ? 'bg-destructive' : 'bg-primary',
                            )}
                            style={{
                                width: `${Math.min(100, (day.booked / day.capacity) * 100)}%`,
                            }}
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>{unpaid} menunggu pembayaran</span>
                        <span>·</span>
                        <span>{noTable} belum punya meja</span>
                        {noTable > 0 && (
                            <Button
                                size="sm"
                                variant="outline"
                                className="ml-auto h-9"
                                onClick={() =>
                                    post('/reservations/assign', { date })
                                }
                            >
                                Atur meja otomatis
                            </Button>
                        )}
                    </div>
                </section>

                {reservations.length === 0 && (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Belum ada reservasi untuk hari ini.
                    </p>
                )}

                <div className="grid gap-3">
                    {reservations.map((r) => (
                        <article
                            key={r.id}
                            className={cn(
                                'grid gap-3 rounded-xl border bg-card p-4',
                                !live(r) &&
                                    r.status !== 'seated' &&
                                    'opacity-75',
                            )}
                        >
                            <header className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="text-xl font-semibold tabular-nums">
                                        {timeId(r.starts_at)}{' '}
                                        <span className="text-base font-medium">
                                            {r.name}
                                        </span>
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {r.pax} orang · {r.code}
                                    </p>
                                </div>
                                <span
                                    className={cn(
                                        'rounded-full px-2.5 py-0.5 text-xs font-semibold',
                                        STATUS[r.status].cls,
                                    )}
                                >
                                    {STATUS[r.status].label}
                                    {r.order && ` · ${r.order}`}
                                </span>
                            </header>

                            <div className="flex flex-wrap items-center gap-2">
                                {r.tables.map((t) => (
                                    <span
                                        key={t.id}
                                        className="rounded-full border px-2.5 py-0.5 text-sm font-medium"
                                    >
                                        {t.name}
                                    </span>
                                ))}
                                {r.tables.length > 1 && (
                                    <span className="text-xs text-muted-foreground">
                                        digabung, satu bill
                                    </span>
                                )}
                                {r.tables.length === 0 && live(r) && (
                                    <span className="text-sm text-muted-foreground">
                                        Belum ada meja
                                    </span>
                                )}
                                {live(r) && (
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        className="h-9"
                                        onClick={() =>
                                            setMoving({
                                                r,
                                                ids: r.tables.map((t) => t.id),
                                            })
                                        }
                                    >
                                        Ubah meja
                                    </Button>
                                )}
                            </div>

                            <ul className="grid gap-0.5 text-sm">
                                {r.summary.map((l, i) => (
                                    <li
                                        key={i}
                                        className="flex justify-between gap-3"
                                    >
                                        <span>
                                            {l.qty}× {l.name}
                                            {l.options && (
                                                <span className="text-muted-foreground">
                                                    {' '}
                                                    · {l.options}
                                                </span>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground tabular-nums">
                                            {rupiah(l.total)}
                                        </span>
                                    </li>
                                ))}
                                <li className="mt-1 flex justify-between border-t pt-1 font-semibold">
                                    <span>Perkiraan total</span>
                                    <span className="tabular-nums">
                                        {rupiah(r.total)}
                                    </span>
                                </li>
                            </ul>
                            {r.note && (
                                <p className="text-sm text-muted-foreground italic">
                                    “{r.note}”
                                </p>
                            )}

                            <div className="grid gap-1 rounded-lg bg-muted/60 p-2 text-sm">
                                <p className="flex flex-wrap justify-between gap-2">
                                    <span>
                                        {r.payment_type === 'full'
                                            ? 'Bayar lunas'
                                            : 'Bayar DP'}
                                        : diminta {rupiah(r.amount_due)}
                                    </span>
                                    <span
                                        className={cn(
                                            'font-semibold tabular-nums',
                                            r.paid > 0
                                                ? 'text-emerald-700 dark:text-emerald-400'
                                                : 'text-destructive',
                                        )}
                                    >
                                        {r.paid > 0
                                            ? `Diterima ${rupiah(r.paid)}`
                                            : 'Belum diterima'}
                                    </span>
                                </p>
                                {r.package_short && (
                                    <p
                                        className={cn(
                                            r.package_waived
                                                ? 'text-muted-foreground'
                                                : 'font-medium text-amber-800 dark:text-amber-300',
                                        )}
                                    >
                                        {r.package_waived
                                            ? 'Paket kurang dari jumlah orang: disetujui.'
                                            : 'Paket kurang dari jumlah orang: perlu persetujuanmu.'}
                                    </p>
                                )}
                                {r.changed_at && (
                                    <p className="text-muted-foreground">
                                        Diubah tamu {timeId(r.changed_at)}. Cek
                                        selisih pembayaran.
                                    </p>
                                )}
                            </div>

                            {r.status === 'seated' && r.paid > 0 && (
                                <p className="flex flex-wrap items-center gap-2 text-sm">
                                    {r.deposit_applied ? (
                                        <span className="text-emerald-700 dark:text-emerald-400">
                                            DP {rupiah(r.paid)} sudah dipotong
                                            dari bill.
                                        </span>
                                    ) : (
                                        <>
                                            <span className="text-destructive">
                                                DP belum dipotong dari bill.
                                            </span>
                                            {canSeat && (
                                                <Button
                                                    size="sm"
                                                    className="h-9"
                                                    onClick={() =>
                                                        post(
                                                            `/reservations/${r.id}/deposit`,
                                                        )
                                                    }
                                                >
                                                    Potong DP sekarang
                                                </Button>
                                            )}
                                        </>
                                    )}
                                </p>
                            )}

                            <footer className="flex flex-wrap items-center gap-2">
                                {live(r) && (
                                    <>
                                        <Button
                                            className="h-11"
                                            variant={
                                                r.status === 'pending'
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            onClick={() =>
                                                setPaying({
                                                    r,
                                                    amount: String(
                                                        r.paid || r.amount_due,
                                                    ),
                                                    waive: r.package_waived,
                                                })
                                            }
                                        >
                                            {r.status === 'pending'
                                                ? 'Konfirmasi bayar'
                                                : 'Ubah nominal'}
                                        </Button>
                                        {r.status === 'booked' && canSeat && (
                                            <Button
                                                className="h-11"
                                                onClick={() =>
                                                    post(
                                                        `/reservations/${r.id}/seat`,
                                                    )
                                                }
                                            >
                                                <Check /> Duduk
                                            </Button>
                                        )}
                                        <Button
                                            asChild
                                            variant="ghost"
                                            className="h-11"
                                        >
                                            <a
                                                href={`/reservations/${r.id}/ubah`}
                                            >
                                                Ubah
                                            </a>
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            className="h-11"
                                            onClick={() =>
                                                post(
                                                    `/reservations/${r.id}/full-menu`,
                                                    { on: !r.full_menu },
                                                )
                                            }
                                        >
                                            {r.full_menu
                                                ? 'Menu biasa: dibuka'
                                                : 'Buka menu biasa'}
                                        </Button>
                                        <Button
                                            variant="outline"
                                            className="h-11"
                                            onClick={() =>
                                                confirm(
                                                    `Tandai ${r.name} tidak datang?`,
                                                ) &&
                                                post(
                                                    `/reservations/${r.id}/close`,
                                                    {
                                                        status: 'no_show',
                                                    },
                                                )
                                            }
                                        >
                                            Tidak datang
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            className="h-11 text-destructive"
                                            onClick={() =>
                                                confirm(
                                                    `Batalkan reservasi ${r.name}?`,
                                                ) &&
                                                post(
                                                    `/reservations/${r.id}/close`,
                                                    {
                                                        status: 'cancelled',
                                                    },
                                                )
                                            }
                                        >
                                            Batalkan
                                        </Button>
                                    </>
                                )}
                                <Button
                                    asChild
                                    variant="ghost"
                                    className="ml-auto h-11"
                                >
                                    <a
                                        href={wa(
                                            r.phone,
                                            message(r, outletName),
                                        )}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <MessageCircle /> {r.phone}
                                    </a>
                                </Button>
                            </footer>
                        </article>
                    ))}
                </div>
            </div>

            {paying && (
                <Modal
                    open
                    onClose={() => setPaying(null)}
                    title={`Pembayaran ${paying.r.name}`}
                    description={`Diminta ${rupiah(paying.r.amount_due)} (${paying.r.payment_type === 'full' ? 'lunas' : 'DP'}). Isi yang benar-benar masuk, lihat bukti transfer atau QRIS.`}
                >
                    <label className="grid gap-1.5 text-sm font-medium">
                        Nominal diterima
                        <Input
                            className="h-12"
                            inputMode="numeric"
                            value={paying.amount}
                            onChange={(e) =>
                                setPaying({
                                    ...paying,
                                    amount: e.target.value.replace(/\D/g, ''),
                                })
                            }
                        />
                    </label>
                    {paying.r.package_short && !paying.r.package_waived && (
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="mt-0.5 size-5"
                                checked={paying.waive}
                                onChange={(e) =>
                                    setPaying({
                                        ...paying,
                                        waive: e.target.checked,
                                    })
                                }
                            />
                            Setujui paket kurang dari jumlah orang
                        </label>
                    )}
                    <Button
                        className="h-12"
                        disabled={paying.amount === ''}
                        onClick={() => {
                            post(`/reservations/${paying.r.id}/confirm`, {
                                amount: Number(paying.amount),
                                waive_package: paying.waive,
                            });
                            setPaying(null);
                        }}
                    >
                        Konfirmasi pembayaran
                    </Button>
                </Modal>
            )}

            {moving && (
                <Modal
                    open
                    onClose={() => setMoving(null)}
                    title={`Meja untuk ${moving.r.name}`}
                    description={`${moving.r.pax} orang. Pilih satu atau beberapa meja; yang dipilih bergabung dalam satu bill.`}
                >
                    {(() => {
                        const seats = tables
                            .filter((t) => moving.ids.includes(t.id))
                            .reduce((n, t) => n + t.capacity, 0);

                        return (
                            <>
                                <div className="grid gap-2 sm:grid-cols-2">
                                    {tables.map((t) => {
                                        const on = moving.ids.includes(t.id);

                                        return (
                                            <button
                                                key={t.id}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() =>
                                                    setMoving({
                                                        ...moving,
                                                        ids: on
                                                            ? moving.ids.filter(
                                                                  (x) =>
                                                                      x !==
                                                                      t.id,
                                                              )
                                                            : [
                                                                  ...moving.ids,
                                                                  t.id,
                                                              ],
                                                    })
                                                }
                                                className={cn(
                                                    'flex h-12 items-center justify-between rounded-lg border px-3 text-left text-sm',
                                                    on
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'bg-card',
                                                )}
                                            >
                                                <span className="font-semibold">
                                                    {t.name}
                                                </span>
                                                <span className="text-xs opacity-80">
                                                    {t.area} · {t.capacity}{' '}
                                                    kursi
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                                <p
                                    className={cn(
                                        'text-sm',
                                        seats < moving.r.pax
                                            ? 'text-destructive'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {seats} kursi dipilih untuk {moving.r.pax}{' '}
                                    orang
                                </p>
                                <Button
                                    className="h-12"
                                    disabled={seats < moving.r.pax}
                                    onClick={() => {
                                        post(
                                            `/reservations/${moving.r.id}/tables`,
                                            {
                                                table_ids: moving.ids,
                                            },
                                        );
                                        setMoving(null);
                                    }}
                                >
                                    Simpan meja
                                </Button>
                            </>
                        );
                    })()}
                </Modal>
            )}
        </>
    );
}
