import { Head, router } from '@inertiajs/react';
import { Minus, Plus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ModifierSheet } from '@/components/pos/modifier-sheet';
import { ProductArt } from '@/components/pos/product-art';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { dateId, rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import { bundleNeedsChoice } from '@/lib/bundle';
import { uuid } from '@/lib/format';
import type { CartLine, Category, Product } from '@/types/pos';

type Editing = {
    id?: number;
    code: string;
    name: string;
    phone: string;
    pax: number;
    note: string | null;
    date: string;
    payment_type: 'dp' | 'full';
    paid: number;
    status: string;
    lines: {
        index: number;
        qty: number;
        name: string;
        total: number;
        options: string;
    }[];
};
type Day = {
    open: boolean;
    reason: string | null;
    capacity: number;
    remaining: number;
    areas: { id: number; name: string; units: number }[];
};
type Props = {
    outlet: {
        name: string | null;
        logo: string | null;
        phone: string | null;
        whatsapp: string | null;
    };
    period: { from: string; to: string } | null;
    menu: Category[];
    editing: Editing | null;
    time: string;
    maxPax: number;
    dpPercent: number;
    cutoff: string;
    changeUntil: string;
    packageRequired: boolean;
    packageIds: number[];
    paymentInfo: string;
    staff?: boolean;
};

const csrf = () =>
    decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '');

/** The booking form. One time a day (buka puasa), a quota of people, one package per person; the same form changes an existing booking. */
export default function Book({
    outlet,
    period,
    menu,
    editing,
    time,
    maxPax,
    dpPercent,
    cutoff,
    changeUntil,
    packageRequired,
    packageIds,
    paymentInfo,
    staff = false,
}: Props) {
    const [date, setDate] = useState(editing?.date ?? period?.from ?? '');
    const [pax, setPax] = useState(editing?.pax ?? 2);
    const [day, setDay] = useState<Day | null>(null);
    const [cart, setCart] = useState<CartLine[]>([]);
    const [kept, setKept] = useState<Record<number, number>>(
        Object.fromEntries((editing?.lines ?? []).map((l) => [l.index, l.qty])),
    );
    const [payType, setPayType] = useState<'dp' | 'full'>(
        editing?.payment_type ?? 'dp',
    );
    const [picking, setPicking] = useState<Product | null>(null);
    const [name, setName] = useState(editing?.name ?? '');
    const [phone, setPhone] = useState(editing?.phone ?? '');
    const [note, setNote] = useState(editing?.note ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [area, setArea] = useState<number | 'any'>('any');
    const [paid, setPaid] = useState('');
    const [waive, setWaive] = useState(false);

    // how many people still fit on the chosen day (a new booking only)
    useEffect(() => {
        if (editing || !period || !date) {
            return;
        }

        let live = true;
        fetch(`/reservasi/slots?date=${encodeURIComponent(date)}&pax=${pax}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((r: Day | null) => {
                if (live && r) {
                    setDay(r);

                    if (!staff) {
                        setPax((n) => Math.max(1, Math.min(n, r.remaining || 1)));
                    }

                    // a chosen area that cannot take this party any more goes back to "any"
                    setArea((a) =>
                        a !== 'any' &&
                        (r.areas.find((x) => x.id === a)?.units ?? 0) === 0
                            ? 'any'
                            : a,
                    );
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, [date, pax, period, editing, staff]);

    const topPax = staff
        ? 200
        : editing
          ? maxPax
          : Math.min(maxPax, day?.remaining || maxPax);
    const tintOf = useMemo(
        () => new Map(menu.map((c, i) => [c.id, i] as const)),
        [menu],
    );
    const keptTotal = (editing?.lines ?? []).reduce(
        (n, l) => n + (l.total / l.qty) * (kept[l.index] ?? 0),
        0,
    );
    const total = Math.round(
        keptTotal + cart.reduce((s, l) => s + l.unitPrice * l.qty, 0),
    );
    const count =
        cart.reduce((s, l) => s + l.qty, 0) +
        Object.values(kept).reduce((n, q) => n + q, 0);
    const packages = cart
        .filter((l) => packageIds.includes(l.product.id))
        .reduce((n, l) => n + l.qty, 0);
    const due =
        payType === 'full'
            ? total
            : Math.ceil((total * dpPercent) / 100 / 1000) * 1000;
    const ready = editing
        ? count > 0 && phone.replace(/\D/g, '').length >= 8
        : !!date &&
          (staff || day?.open === true) &&
          cart.length > 0 &&
          name.trim() !== '' &&
          phone.replace(/\D/g, '').length >= 8;
    const short =
        packageRequired && packageIds.length > 0 && !editing && packages < pax;

    const addSimple = (p: Product) => {
        setCart((c) => {
            const same = c.find(
                (l) =>
                    l.product.id === p.id &&
                    !l.variantId &&
                    l.modifierIds.length === 0 &&
                    !l.parts &&
                    !l.note,
            );

            return same
                ? c.map((l) =>
                      l.key === same.key ? { ...l, qty: l.qty + 1 } : l,
                  )
                : [
                      ...c,
                      {
                          key: uuid(),
                          product: p,
                          variantId: null,
                          modifierIds: [],
                          qty: 1,
                          note: '',
                          unitPrice: p.price,
                          label: p.name,
                      },
                  ];
        });
    };

    const pick = (p: Product) => {
        if (
            p.variants.length > 0 ||
            p.modifier_groups.length > 0 ||
            bundleNeedsChoice(p)
        ) {
            setPicking(p);
        } else {
            addSimple(p);
        }
    };

    const send = async (url: string, payload: Record<string, unknown>) => {
        setBusy(true);
        setError(null);

        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf(),
                },
                body: JSON.stringify(payload),
            });
            const body = (await res.json()) as {
                code?: string;
                date?: string;
                message?: string;
            };

            if (res.ok && staff && body.date) {
                router.visit(`/reservations?date=${body.date}`);

                return;
            }

            if (res.ok && body.code) {
                router.visit(`/reservasi/${body.code}`);

                return;
            }

            setError(body.message ?? 'Gagal. Coba lagi.');
        } catch {
            toast.error('Koneksi bermasalah. Coba lagi.');
        } finally {
            setBusy(false);
        }
    };
    const lines = cart.map((l) => ({
        product_id: l.product.id,
        variant_id: l.variantId,
        modifier_ids: l.modifierIds,
        parts: l.parts,
        qty: l.qty,
        note: l.note || null,
    }));
    const submit = () =>
        editing
            ? send(
                  staff
                      ? `/reservations/${editing.id}/ubah`
                      : `/reservasi/${editing.code}/ubah`,
                  {
                      name,
                      pax,
                      phone,
                      note: note || null,
                      keep: Object.entries(kept).map(([index, qty]) => ({
                          index: Number(index),
                          qty,
                      })),
                      add: lines,
                  },
              )
            : send(staff ? '/reservations' : '/reservasi', {
                  name,
                  phone,
                  pax,
                  date,
                  area_id: area === 'any' ? null : area,
                  payment_type: payType,
                  note: note || null,
                  items: lines,
                  ...(staff
                      ? {
                            paid: paid === '' ? null : Number(paid),
                            waive_package: waive,
                        }
                      : {}),
              });
    const cancelBooking = () =>
        confirm('Batalkan reservasi ini?') &&
        send(`/reservasi/${editing?.code}/batal`, {});

    return (
        <>
            <Head title="Reservasi" />
            <div className="mx-auto grid min-h-dvh w-full max-w-xl content-start gap-5 bg-background px-4 pt-6 pb-40 text-foreground">
                <header className="flex items-center gap-3">
                    {outlet.logo && (
                        <img
                            src={outlet.logo}
                            alt=""
                            className="size-12 rounded-xl object-cover"
                        />
                    )}
                    <div>
                        <p className="text-sm text-muted-foreground">
                            {outlet.name}
                        </p>
                        <h1 className="text-2xl font-bold tracking-tight">
                            {editing
                                ? `Ubah reservasi ${editing.code}`
                                : staff
                                  ? 'Reservasi baru (CS)'
                                  : 'Reservasi buka puasa'}
                        </h1>
                    </div>
                </header>

                {!period ? (
                    <p className="rounded-xl border p-5 text-center text-muted-foreground">
                        Reservasi belum dibuka. Silakan hubungi kami
                        {outlet.whatsapp || outlet.phone
                            ? ` di ${outlet.whatsapp ?? outlet.phone}`
                            : ''}
                        .
                    </p>
                ) : (
                    <>
                        <section className="grid gap-4 rounded-2xl border bg-card p-4">
                            <p className="text-sm text-muted-foreground">
                                Buka puasa pukul{' '}
                                <span className="font-semibold text-foreground">
                                    {time}
                                </span>
                                . Satu paket untuk satu orang.
                            </p>
                            <div className="grid grid-cols-2 gap-3">
                                <div className="grid gap-1.5">
                                    <Label htmlFor="rs-date">Tanggal</Label>
                                    <Input
                                        id="rs-date"
                                        type="date"
                                        className="h-12"
                                        min={period.from}
                                        max={period.to}
                                        value={date}
                                        disabled={!!editing}
                                        onChange={(e) =>
                                            setDate(e.target.value)
                                        }
                                    />
                                </div>
                                <div className="grid gap-1.5">
                                    <Label>Jumlah orang</Label>
                                    <div className="flex h-12 items-center justify-between rounded-xl border px-1">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label="Kurangi"
                                            onClick={() =>
                                                setPax((n) =>
                                                    Math.max(1, n - 1),
                                                )
                                            }
                                        >
                                            <Minus />
                                        </Button>
                                        <span
                                            className="text-lg font-semibold tabular-nums"
                                            aria-live="polite"
                                        >
                                            {pax}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-10"
                                            aria-label="Tambah"
                                            onClick={() =>
                                                setPax((n) =>
                                                    Math.min(topPax, n + 1),
                                                )
                                            }
                                        >
                                            <Plus />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                            {staff && (
                                <p className="text-sm font-medium text-amber-800 dark:text-amber-300">
                                    Mode CS: tidak terhalang jam tutup, kuota,
                                    atau batas orang per reservasi.{' '}
                                    <a
                                        className="underline"
                                        href="/reservations"
                                    >
                                        Kembali ke daftar
                                    </a>
                                </p>
                            )}
                            {!staff && !editing && day && (
                                <p
                                    className={cn(
                                        'text-sm',
                                        day.open
                                            ? 'text-muted-foreground'
                                            : 'font-medium text-destructive',
                                    )}
                                >
                                    {day.open
                                        ? `Sisa ${day.remaining} tempat dari ${day.capacity}. Reservasi hari itu ditutup pukul ${cutoff} atau saat penuh.`
                                        : day.reason}
                                </p>
                            )}
                            {!editing && day && day.areas.length > 0 && (
                                <div className="grid gap-1.5">
                                    <Label>Area duduk</Label>
                                    <div
                                        role="group"
                                        aria-label="Area duduk"
                                        className="grid gap-2"
                                    >
                                        {[
                                            {
                                                id: 'any' as const,
                                                name: 'Bebas, mana saja yang kosong',
                                                units: day.areas.reduce(
                                                    (n, a) => n + a.units,
                                                    0,
                                                ),
                                            },
                                            ...day.areas,
                                        ].map((a) => {
                                            const on = area === a.id;
                                            const full = a.units === 0;

                                            return (
                                                <button
                                                    key={a.id}
                                                    type="button"
                                                    disabled={full && !staff}
                                                    aria-pressed={on}
                                                    onClick={() => setArea(a.id)}
                                                    className={cn(
                                                        'flex min-h-12 items-center justify-between gap-3 rounded-xl border px-3 text-left text-sm',
                                                        on
                                                            ? 'border-primary bg-primary text-primary-foreground'
                                                            : 'bg-card',
                                                        full &&
                                                            !staff &&
                                                            'cursor-not-allowed opacity-50',
                                                    )}
                                                >
                                                    <span className="font-semibold">
                                                        {a.name}
                                                    </span>
                                                    <span className="text-xs opacity-90">
                                                        {full
                                                            ? 'Penuh untuk ' +
                                                              pax +
                                                              ' orang'
                                                            : `${a.units} tempat`}
                                                    </span>
                                                </button>
                                            );
                                        })}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        Satu tempat dipakai satu grup. Kalau
                                        grupmu lebih banyak dari kapasitas
                                        normal, kami usahakan tempat yang bisa
                                        dibuat lebih padat.
                                    </p>
                                </div>
                            )}
                            {editing && !staff && (
                                <p className="text-sm text-muted-foreground">
                                    Bisa diubah atau dibatalkan sampai pukul{' '}
                                    {changeUntil} di hari-H.
                                </p>
                            )}
                        </section>

                        {editing && editing.lines.length > 0 && (
                            <section className="grid gap-2 rounded-2xl border bg-card p-4">
                                <h2 className="font-semibold">
                                    Pesanan sekarang
                                </h2>
                                <ul className="grid gap-2">
                                    {editing.lines.map((l) => (
                                        <li
                                            key={l.index}
                                            className="flex items-center justify-between gap-2 text-sm"
                                        >
                                            <span className="min-w-0 flex-1">
                                                {l.name}
                                                {l.options && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        {l.options}
                                                    </span>
                                                )}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="size-9"
                                                    aria-label="Kurangi"
                                                    onClick={() =>
                                                        setKept((k) => ({
                                                            ...k,
                                                            [l.index]: Math.max(
                                                                0,
                                                                (k[l.index] ??
                                                                    0) - 1,
                                                            ),
                                                        }))
                                                    }
                                                >
                                                    <Minus />
                                                </Button>
                                                <span className="w-6 text-center tabular-nums">
                                                    {kept[l.index] ?? 0}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="size-9"
                                                    aria-label="Tambah"
                                                    onClick={() =>
                                                        setKept((k) => ({
                                                            ...k,
                                                            [l.index]: Math.min(
                                                                50,
                                                                (k[l.index] ??
                                                                    0) + 1,
                                                            ),
                                                        }))
                                                    }
                                                >
                                                    <Plus />
                                                </Button>
                                            </span>
                                            <span className="w-24 text-right tabular-nums">
                                                {rupiah(
                                                    Math.round(
                                                        (l.total / l.qty) *
                                                            (kept[l.index] ??
                                                                0),
                                                    ),
                                                )}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        <section className="grid gap-3">
                            <h2 className="text-lg font-semibold">
                                Pilih menu
                            </h2>
                            {menu.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    Menu reservasi belum tersedia.
                                </p>
                            )}
                            {menu.map((c) => (
                                <div key={c.id} className="grid gap-2">
                                    {menu.length > 1 && (
                                        <h3 className="text-sm font-semibold text-muted-foreground">
                                            {c.name}
                                        </h3>
                                    )}
                                    <ul className="grid gap-2">
                                        {c.products.map((p) => {
                                            const qty = cart
                                                .filter(
                                                    (l) =>
                                                        l.product.id === p.id,
                                                )
                                                .reduce((a, l) => a + l.qty, 0);

                                            return (
                                                <li
                                                    key={p.id}
                                                    className="flex items-center gap-3 rounded-2xl border bg-card p-3"
                                                >
                                                    <div className="size-20 shrink-0 overflow-hidden rounded-xl">
                                                        <ProductArt
                                                            name={p.name}
                                                            image={p.image}
                                                            tint={
                                                                tintOf.get(
                                                                    c.id,
                                                                ) ?? 0
                                                            }
                                                        />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <p className="font-semibold">
                                                            {p.name}
                                                        </p>
                                                        {p.description && (
                                                            <p className="line-clamp-2 text-xs text-muted-foreground">
                                                                {p.description}
                                                            </p>
                                                        )}
                                                        <p className="mt-1 font-bold tabular-nums">
                                                            {p.variants.length >
                                                            1
                                                                ? `dari ${rupiah(Math.min(...p.variants.map((v) => v.price)))}`
                                                                : rupiah(
                                                                      p.price,
                                                                  )}
                                                        </p>
                                                    </div>
                                                    <Button
                                                        type="button"
                                                        className="h-11 shrink-0"
                                                        disabled={
                                                            !p.is_available
                                                        }
                                                        onClick={() => pick(p)}
                                                    >
                                                        {!p.is_available
                                                            ? 'Habis'
                                                            : qty > 0
                                                              ? `+ (${qty})`
                                                              : 'Tambah'}
                                                    </Button>
                                                </li>
                                            );
                                        })}
                                    </ul>
                                </div>
                            ))}
                        </section>

                        {cart.length > 0 && (
                            <section className="grid gap-2 rounded-2xl border bg-card p-4">
                                <h2 className="font-semibold">Pesananmu</h2>
                                <ul className="grid gap-2">
                                    {cart.map((l) => (
                                        <li
                                            key={l.key}
                                            className="flex items-center justify-between gap-2 text-sm"
                                        >
                                            <span className="min-w-0 flex-1">
                                                {l.label}
                                                {l.note && (
                                                    <span className="block text-xs text-muted-foreground">
                                                        “{l.note}”
                                                    </span>
                                                )}
                                            </span>
                                            <span className="flex items-center gap-1">
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="size-9"
                                                    aria-label="Kurangi"
                                                    onClick={() =>
                                                        setCart((c) =>
                                                            c.flatMap((x) =>
                                                                x.key !== l.key
                                                                    ? [x]
                                                                    : x.qty > 1
                                                                      ? [
                                                                            {
                                                                                ...x,
                                                                                qty:
                                                                                    x.qty -
                                                                                    1,
                                                                            },
                                                                        ]
                                                                      : [],
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Minus />
                                                </Button>
                                                <span className="w-6 text-center tabular-nums">
                                                    {l.qty}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="outline"
                                                    size="icon"
                                                    className="size-9"
                                                    aria-label="Tambah"
                                                    onClick={() =>
                                                        setCart((c) =>
                                                            c.map((x) =>
                                                                x.key === l.key
                                                                    ? {
                                                                          ...x,
                                                                          qty:
                                                                              x.qty +
                                                                              1,
                                                                      }
                                                                    : x,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <Plus />
                                                </Button>
                                            </span>
                                            <span className="w-24 text-right tabular-nums">
                                                {rupiah(l.unitPrice * l.qty)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}

                        {packageRequired &&
                            packageIds.length > 0 &&
                            !editing && (
                                <p
                                    role="status"
                                    className={cn(
                                        'rounded-xl p-3 text-sm',
                                        short
                                            ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    Paket {packages} dari {pax} orang.{' '}
                                    {short
                                        ? 'Satu orang satu paket. Kalau kurang, reservasi perlu persetujuan pengelola sebelum dikonfirmasi.'
                                        : 'Sudah sesuai.'}
                                </p>
                            )}

                        <section className="grid gap-3 rounded-2xl border bg-card p-4">
                            <h2 className="font-semibold">Data pemesan</h2>
                            <div className="grid gap-1.5">
                                <Label htmlFor="rs-name">Nama</Label>
                                <Input
                                    id="rs-name"
                                    className="h-12"
                                    disabled={!!editing && !staff}
                                    value={name}
                                    maxLength={80}
                                    onChange={(e) => setName(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="rs-phone">
                                    Nomor WhatsApp / telepon
                                </Label>
                                <Input
                                    id="rs-phone"
                                    className="h-12"
                                    inputMode="tel"
                                    value={phone}
                                    maxLength={30}
                                    onChange={(e) => setPhone(e.target.value)}
                                />
                            </div>
                            <div className="grid gap-1.5">
                                <Label htmlFor="rs-note">
                                    Catatan (opsional)
                                </Label>
                                <Input
                                    id="rs-note"
                                    className="h-12"
                                    value={note}
                                    maxLength={200}
                                    onChange={(e) => setNote(e.target.value)}
                                />
                            </div>
                            {error && (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {error}
                                </p>
                            )}
                        </section>

                        {!editing && (
                            <section className="grid gap-3 rounded-2xl border bg-card p-4">
                                <h2 className="font-semibold">Pembayaran</h2>
                                <div
                                    role="group"
                                    aria-label="Jenis pembayaran"
                                    className="grid gap-2 sm:grid-cols-2"
                                >
                                    {(
                                        [
                                            ['dp', `Bayar DP ${dpPercent}%`],
                                            ['full', 'Bayar lunas'],
                                        ] as const
                                    ).map(([k, label]) => (
                                        <button
                                            key={k}
                                            type="button"
                                            aria-pressed={payType === k}
                                            onClick={() => setPayType(k)}
                                            className={cn(
                                                'grid gap-0.5 rounded-xl border p-3 text-left',
                                                payType === k
                                                    ? 'border-primary bg-primary text-primary-foreground'
                                                    : 'bg-card',
                                            )}
                                        >
                                            <span className="font-semibold">
                                                {label}
                                            </span>
                                            <span className="text-sm tabular-nums opacity-90">
                                                {rupiah(
                                                    k === 'full'
                                                        ? total
                                                        : Math.ceil(
                                                              (total *
                                                                  dpPercent) /
                                                                  100 /
                                                                  1000,
                                                          ) * 1000,
                                                )}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                                {staff ? (
                                    <div className="grid gap-2">
                                        <Label htmlFor="rs-paid">
                                            Sudah dibayar (opsional, langsung
                                            terkonfirmasi)
                                        </Label>
                                        <Input
                                            id="rs-paid"
                                            className="h-12"
                                            inputMode="numeric"
                                            value={paid}
                                            onChange={(e) =>
                                                setPaid(
                                                    e.target.value.replace(
                                                        /\D/g,
                                                        '',
                                                    ),
                                                )
                                            }
                                        />
                                        {short && paid !== '' && (
                                            <label className="flex items-start gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    className="mt-0.5 size-5"
                                                    checked={waive}
                                                    onChange={(e) =>
                                                        setWaive(
                                                            e.target.checked,
                                                        )
                                                    }
                                                />
                                                Setujui paket kurang dari jumlah
                                                orang
                                            </label>
                                        )}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Reservasi sah setelah pembayaran
                                        dikonfirmasi pengelola.
                                        {paymentInfo && ` ${paymentInfo}`}
                                    </p>
                                )}
                            </section>
                        )}
                        {editing && !staff && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="h-11 text-destructive"
                                disabled={busy}
                                onClick={() => void cancelBooking()}
                            >
                                Batalkan reservasi
                            </Button>
                        )}
                    </>
                )}
            </div>

            {period && (
                <div className="fixed inset-x-0 bottom-0 z-20 border-t bg-background px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                    <div className="mx-auto flex max-w-xl items-center gap-3">
                        <div className="min-w-0 flex-1 text-sm">
                            <p className="font-semibold tabular-nums">
                                {count} item · {rupiah(total)}
                            </p>
                            <p className="truncate text-xs text-muted-foreground">
                                {date
                                    ? `${dateId(date)} · ${time} · ${pax} orang${editing ? '' : ` · bayar ${rupiah(due)}`}`
                                    : 'Pilih tanggal'}
                            </p>
                        </div>
                        <Button
                            className="h-12 px-6 text-base"
                            disabled={!ready || busy}
                            onClick={() => void submit()}
                        >
                            {busy
                                ? 'Memproses…'
                                : editing
                                  ? 'Simpan perubahan'
                                  : 'Pesan sekarang'}
                        </Button>
                    </div>
                </div>
            )}

            <ModifierSheet
                product={picking}
                onClose={() => setPicking(null)}
                onAdd={(l) => setCart((c) => [...c, l])}
            />
        </>
    );
}
