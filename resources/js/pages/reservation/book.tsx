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

type Props = {
    outlet: {
        name: string | null;
        logo: string | null;
        phone: string | null;
        whatsapp: string | null;
    };
    period: { from: string; to: string } | null;
    slots: string[];
    maxPax: number;
    menu: Category[];
};

/** Public booking page: a day, a time and a party size, then a pre-order from the reservation menu only. */
export default function Book({ outlet, period, slots, maxPax, menu }: Props) {
    const [date, setDate] = useState(period?.from ?? '');
    const [pax, setPax] = useState(2);
    const [slot, setSlot] = useState('');
    const [free, setFree] = useState<Record<string, boolean>>({});
    const [cart, setCart] = useState<CartLine[]>([]);
    const [picking, setPicking] = useState<Product | null>(null);
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    // which times still have room for this day and party size
    useEffect(() => {
        if (!period || !date) {
            return;
        }

        let live = true;
        fetch(`/reservasi/slots?date=${encodeURIComponent(date)}&pax=${pax}`, {
            headers: { Accept: 'application/json' },
        })
            .then((r) => (r.ok ? r.json() : { slots: {} }))
            .then((r: { slots: Record<string, boolean> }) => {
                if (live) {
                    setFree(r.slots);
                    setSlot((s) => (r.slots[s] ? s : ''));
                }
            })
            .catch(() => undefined);

        return () => {
            live = false;
        };
    }, [date, pax, period]);

    const tintOf = useMemo(
        () => new Map(menu.map((c, i) => [c.id, i] as const)),
        [menu],
    );
    const total = cart.reduce((s, l) => s + l.unitPrice * l.qty, 0);
    const count = cart.reduce((s, l) => s + l.qty, 0);
    const ready =
        !!date &&
        !!slot &&
        cart.length > 0 &&
        name.trim() !== '' &&
        phone.replace(/\D/g, '').length >= 8;

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

    const submit = async () => {
        setBusy(true);
        setError(null);

        try {
            const res = await fetch('/reservasi', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': decodeURIComponent(
                        document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                    ),
                },
                body: JSON.stringify({
                    name,
                    phone,
                    pax,
                    date,
                    slot,
                    note: note || null,
                    items: cart.map((l) => ({
                        product_id: l.product.id,
                        variant_id: l.variantId,
                        modifier_ids: l.modifierIds,
                        parts: l.parts,
                        qty: l.qty,
                        note: l.note || null,
                    })),
                }),
            });
            const body = (await res.json()) as {
                code?: string;
                message?: string;
            };

            if (res.ok && body.code) {
                router.visit(`/reservasi/${body.code}`);

                return;
            }

            setError(body.message ?? 'Reservasi gagal. Coba lagi.');
        } catch {
            toast.error('Koneksi bermasalah. Coba lagi.');
        } finally {
            setBusy(false);
        }
    };

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
                            Reservasi buka puasa
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
                                                    Math.min(maxPax, n + 1),
                                                )
                                            }
                                        >
                                            <Plus />
                                        </Button>
                                    </div>
                                </div>
                            </div>
                            <div className="grid gap-1.5">
                                <Label>Jam</Label>
                                <div
                                    role="group"
                                    aria-label="Jam reservasi"
                                    className="flex flex-wrap gap-2"
                                >
                                    {slots.map((s) => {
                                        const ok = free[s] === true;

                                        return (
                                            <button
                                                key={s}
                                                type="button"
                                                disabled={!ok}
                                                aria-pressed={slot === s}
                                                onClick={() => setSlot(s)}
                                                className={cn(
                                                    'h-11 min-w-20 rounded-full border px-4 text-sm font-semibold tabular-nums transition-colors',
                                                    slot === s
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'bg-card',
                                                    !ok &&
                                                        'cursor-not-allowed text-muted-foreground line-through opacity-50',
                                                )}
                                            >
                                                {s}
                                            </button>
                                        );
                                    })}
                                </div>
                                {date &&
                                    Object.keys(free).length > 0 &&
                                    !Object.values(free).some(Boolean) && (
                                        <p className="text-sm text-muted-foreground">
                                            Semua jam pada {dateId(date)} sudah
                                            penuh untuk {pax} orang. Coba
                                            tanggal lain.
                                        </p>
                                    )}
                            </div>
                        </section>

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

                        <section className="grid gap-3 rounded-2xl border bg-card p-4">
                            <h2 className="font-semibold">Data pemesan</h2>
                            <div className="grid gap-1.5">
                                <Label htmlFor="rs-name">Nama</Label>
                                <Input
                                    id="rs-name"
                                    className="h-12"
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
                                {date && slot
                                    ? `${dateId(date)} · ${slot} · ${pax} orang`
                                    : 'Pilih tanggal dan jam'}
                            </p>
                        </div>
                        <Button
                            className="h-12 px-6 text-base"
                            disabled={!ready || busy}
                            onClick={() => void submit()}
                        >
                            {busy ? 'Memesan…' : 'Pesan sekarang'}
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
