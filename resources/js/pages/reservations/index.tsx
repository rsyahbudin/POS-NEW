import { Head, router } from '@inertiajs/react';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Copy,
    MessageCircle,
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
    status: 'booked' | 'seated' | 'cancelled' | 'no_show';
    note: string | null;
    starts_at: string;
    tables: { id: number; name: string; capacity: number }[];
    summary: { name: string; qty: number; total: number; options: string }[];
    total: number;
    order: string | null;
};
type TableRow = { id: number; name: string; capacity: number; area: string };

const STATUS: Record<Row['status'], { label: string; cls: string }> = {
    booked: {
        label: 'Menunggu',
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
const wa = (phone: string) =>
    `https://wa.me/${phone.replace(/\D/g, '').replace(/^0/, '62')}`;

export default function Reservations({
    date,
    link,
    enabled,
    reservations,
    tables,
}: {
    date: string;
    link: string;
    enabled: boolean;
    reservations: Row[];
    tables: TableRow[];
}) {
    const [moving, setMoving] = useState<{ r: Row; ids: number[] } | null>(
        null,
    );
    const go = (d: string) =>
        router.get('/reservations', { date: d }, { preserveScroll: true });
    const post = (url: string, data: Record<string, unknown> = {}) =>
        router.post(url, data as never, { preserveScroll: true });
    const waiting = reservations.filter((r) => r.status === 'booked');
    const guests = waiting.reduce((n, r) => n + r.pax, 0);

    return (
        <>
            <Head title="Reservasi" />
            <div className="mx-auto grid w-full max-w-4xl gap-4 p-4 md:p-6">
                <PageHeader
                    title="Reservasi"
                    subtitle="Tamu memesan sendiri lewat link reservasi. Tekan Duduk saat mereka datang: pesanan dibuka di meja pertama, meja lain bergabung ke satu bill, dan menu pesanan langsung dikirim ke dapur."
                    actions={
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
                        {dateId(date)} · {waiting.length} menunggu · {guests}{' '}
                        orang
                    </span>
                </div>

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
                                r.status !== 'booked' && 'opacity-75',
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
                                {r.status === 'booked' && (
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

                            <footer className="flex flex-wrap items-center gap-2">
                                {r.status === 'booked' && (
                                    <>
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
                                        href={wa(r.phone)}
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
