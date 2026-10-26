import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { ProductArt } from '@/components/pos/product-art';
import type { Category, Modifier, Product } from '@/types/pos';

/*
 * Public guest page. Kept dependency-light on purpose (no shadcn/Radix, no
 * Echo): the QR bundle budget is 150 KB gzip (PRD 6.2). Live status uses
 * polling of a tiny JSON endpoint; the server stays the source of truth.
 */

type Line = {
    key: string;
    product: Product;
    variantId: number | null;
    modifierIds: number[];
    qty: number;
    note: string;
    unit: number;
    label: string;
};
type StatusRound = {
    id: number;
    status: string;
    reject_reason: string | null;
    created_at: string;
    items: {
        name: string;
        qty: number;
        price: number;
        modifiers: string[];
        voided: boolean;
    }[];
};
type Venue = {
    logo: string | null;
    banner: string | null;
    tagline: string | null;
    hours: string | null;
    address: string | null;
    phone: string | null;
    instagram: string | null;
    wifi_name: string | null;
    wifi_password: string | null;
};
type CallState = { status: string; reason: string; at: string } | null;
type Status = { rounds: StatusRound[]; total: number; call?: CallState };

type Props = {
    token: string;
    table: { name: string; public_id: string };
    outlet: string | null;
    venue: Venue;
    menu: Category[];
    outletId: number;
    autoConfirm: boolean;
    qris: boolean;
    status: Status;
    call: { enabled: boolean; reasons: Record<string, string> };
    kds: boolean;
};

/** Small line icons (24px grid, 2px stroke) so the guest page needs no icon library and no emoji. */
const ICONS = {
    bell: [
        'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9',
        'M10.3 21a1.94 1.94 0 0 0 3.4 0',
    ],
    clock: ['M12 6v6l4 2', 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18'],
    info: ['M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18', 'M12 16v-4', 'M12 8h.01'],
    search: ['m21 21-4.3-4.3', 'M11 19a8 8 0 1 0 0-16 8 8 0 0 0 0 16'],
    close: ['M18 6 6 18', 'm6 6 12 12'],
    check: ['M20 6 9 17l-5-5'],
    list: ['M4 6h16', 'M4 12h16', 'M4 18h10'],
} as const;

function Ico({
    name,
    className = 'size-4',
}: {
    name: keyof typeof ICONS;
    className?: string;
}) {
    return (
        <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
            className={`shrink-0 ${className}`}
            aria-hidden
        >
            {ICONS[name].map((d) => (
                <path key={d} d={d} />
            ))}
        </svg>
    );
}

const rp = (n: number) => `Rp${Math.trunc(n).toLocaleString('id-ID')}`;

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

export default function QrMenu({
    token,
    table,
    outlet,
    venue,
    menu: initialMenu,
    status: initialStatus,
    autoConfirm,
    qris,
    call,
    kds,
}: Props) {
    const [menu, setMenu] = useState(initialMenu);
    const [cart, setCart] = useState<Line[]>([]);
    const [status, setStatus] = useState<Status>(initialStatus);
    const [name, setName] = useState(() => {
        try {
            return localStorage.getItem('qr.name') ?? '';
        } catch {
            return '';
        }
    });
    const [picking, setPicking] = useState<Product | null>(null);
    const [open, setOpen] = useState<
        'cart' | 'status' | 'info' | 'cats' | null
    >(null);
    const hasInfo = !!(
        venue.address ||
        venue.phone ||
        venue.instagram ||
        venue.wifi_name
    );
    const [cat, setCat] = useState<number | 'all'>('all');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    const submitting = useRef(false);
    const chips = useRef<HTMLElement | null>(null);

    const lock = useRef(0);
    const goTo = (id: number) => {
        lock.current = Date.now() + 1500; // the scroll we start must not hand the highlight to another category
        setCat(id);
        document.getElementById(`cat-${id}`)?.scrollIntoView({
            behavior: 'smooth',
            block: 'start',
        });
    };

    const refresh = useCallback(async () => {
        try {
            const res = await fetch(`/m/${token}/status`, {
                headers: { Accept: 'application/json' },
            });

            if (res.ok) {
                setStatus(await res.json());
            }
        } catch {
            // offline: keep showing last known status
        }
    }, [token]);

    useEffect(() => {
        const id = window.setInterval(() => void refresh(), 6000);

        return () => window.clearInterval(id);
    }, [refresh]);

    const [q, setQ] = useState('');
    const allCount = useMemo(
        () => menu.reduce((n, c) => n + c.products.length, 0),
        [menu],
    );
    const tintOf = useMemo(
        () => new Map(menu.map((c, i) => [c.id, i] as const)),
        [menu],
    );
    const sections = useMemo(() => {
        const words = q.toLowerCase().split(/\s+/).filter(Boolean);

        return menu
            .map((c) => ({
                ...c,
                products: c.products.filter((p) =>
                    words.every((w) =>
                        `${p.name} ${p.description ?? ''}`
                            .toLowerCase()
                            .includes(w),
                    ),
                ),
            }))
            .filter((c) => c.products.length > 0);
    }, [menu, q]);

    // highlight the chip of the category being read
    useEffect(() => {
        const els = document.querySelectorAll<HTMLElement>('[data-cat]');
        const io = new IntersectionObserver(
            (entries) => {
                const top = entries
                    .filter((e) => e.isIntersecting)
                    .sort(
                        (a, b) =>
                            a.boundingClientRect.top - b.boundingClientRect.top,
                    )[0];

                if (top && Date.now() > lock.current) {
                    setCat(Number((top.target as HTMLElement).dataset.cat));
                }
            },
            { rootMargin: '-160px 0px -60% 0px' },
        );
        els.forEach((el) => io.observe(el));

        return () => io.disconnect();
    }, [sections]);
    // keep the chip of the category being read in view, however many categories there are
    useEffect(() => {
        const bar = chips.current;
        const chip = bar?.querySelector<HTMLElement>(`[data-chip="${cat}"]`);

        if (bar && chip) {
            bar.scrollTo({
                left:
                    chip.offsetLeft - (bar.clientWidth - chip.clientWidth) / 2,
                behavior: 'smooth',
            });
        }
    }, [cat]);
    const total = cart.reduce((s, l) => s + l.unit * l.qty, 0);
    const count = cart.reduce((s, l) => s + l.qty, 0);

    const add = (line: Line) =>
        setCart((c) => {
            const same = c.find(
                (x) =>
                    x.product.id === line.product.id &&
                    x.variantId === line.variantId &&
                    x.note === line.note &&
                    [...x.modifierIds].sort((a, b) => a - b).join() ===
                        [...line.modifierIds].sort((a, b) => a - b).join(),
            );

            return same
                ? c.map((x) =>
                      x === same ? { ...x, qty: x.qty + line.qty } : x,
                  )
                : [...c, line];
        });

    /** Quick +/- on a menu card for products without options. */
    const bump = (p: Product, delta: 1 | -1) =>
        setCart((c) => {
            const idx = c.findIndex((l) => l.product.id === p.id);

            if (delta === 1) {
                return idx >= 0
                    ? c.map((l, i) =>
                          i === idx
                              ? { ...l, qty: Math.min(20, l.qty + 1) }
                              : l,
                      )
                    : [
                          ...c,
                          {
                              key: crypto.randomUUID(),
                              product: p,
                              variantId: null,
                              modifierIds: [],
                              qty: 1,
                              note: '',
                              unit: p.price,
                              label: p.name,
                          },
                      ];
            }

            if (idx < 0) {
                return c;
            }

            return c[idx].qty <= 1
                ? c.filter((_, i) => i !== idx)
                : c.map((l, i) => (i === idx ? { ...l, qty: l.qty - 1 } : l));
        });

    const submit = async () => {
        if (submitting.current || cart.length === 0 || name.trim() === '') {
            return;
        }

        submitting.current = true;
        setBusy(true);
        setError('');

        try {
            const res = await fetch(`/m/${token}/order`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: JSON.stringify({
                    customer_name: name.trim(),
                    items: cart.map((l) => ({
                        product_id: l.product.id,
                        variant_id: l.variantId,
                        modifier_ids: l.modifierIds,
                        qty: l.qty,
                        note: l.note || null,
                    })),
                }),
            });
            const data = await res.json();

            if (!res.ok) {
                const first = data.errors
                    ? (Object.values(data.errors)[0] as string[])[0]
                    : null;
                throw new Error(
                    first ?? data.message ?? 'Gagal mengirim pesanan.',
                );
            }

            try {
                localStorage.setItem('qr.name', name.trim());
            } catch {
                // ignore
            }

            setCart([]);
            setStatus(data.status);
            setDone(true);
            setOpen('status');
        } catch (e) {
            setError(
                e instanceof Error ? e.message : 'Gagal mengirim pesanan.',
            );
        } finally {
            submitting.current = false;
            setBusy(false);
        }
    };

    // Sold-out items disappear from the menu without a reload.
    useEffect(() => {
        const id = window.setInterval(() => {
            router_reload_menu();
        }, 30000);

        function router_reload_menu() {
            fetch(window.location.href, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Partial-Component': 'qr/menu',
                    'X-Inertia-Partial-Data': 'menu',
                    Accept: 'text/html, application/xhtml+xml',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            })
                .then((r) => (r.ok ? r.json() : null))
                .then((p) => p?.props?.menu && setMenu(p.props.menu))
                .catch(() => undefined);
        }

        return () => window.clearInterval(id);
    }, []);

    const lastStatus = status.rounds[status.rounds.length - 1]?.status;

    return (
        <>
            <Head title={`Menu · ${table.name}`} />
            <div className="min-h-dvh bg-background pb-40 text-foreground">
                {venue.banner && (
                    <div className="px-4 pt-4">
                        <img
                            src={venue.banner}
                            alt=""
                            className="aspect-[7/3] w-full rounded-3xl object-cover shadow-sm ring-1 ring-black/5"
                        />
                    </div>
                )}
                <header
                    className={`px-5 pb-4 ${venue.banner ? 'pt-5' : 'pt-7'}`}
                >
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex min-w-0 items-center gap-3">
                            {venue.logo && (
                                <img
                                    src={venue.logo}
                                    alt=""
                                    className="size-12 shrink-0 rounded-2xl object-cover ring-1 ring-black/5"
                                />
                            )}
                            <div className="min-w-0">
                                <p className="truncate text-sm font-medium text-muted-foreground">
                                    {outlet}
                                </p>
                                <h1 className="mt-0.5 text-[28px] leading-9 font-bold tracking-tight">
                                    Meja {table.name}
                                </h1>
                            </div>
                        </div>
                        {call.enabled && (
                            <CallWaiter
                                token={token}
                                reasons={call.reasons}
                                state={status.call ?? null}
                                onState={(c) =>
                                    setStatus((s) => ({ ...s, call: c }))
                                }
                            />
                        )}
                    </div>
                    {venue.tagline && (
                        <p className="mt-2.5 text-[15px] leading-snug font-medium">
                            {venue.tagline}
                        </p>
                    )}
                    {(venue.hours || hasInfo) && (
                        <div className="mt-2.5 flex flex-wrap items-center gap-2">
                            {venue.hours && (
                                <span className="inline-flex h-8 items-center gap-1.5 rounded-full bg-muted px-3 text-xs font-medium">
                                    <Ico name="clock" className="size-3.5" />
                                    {venue.hours}
                                </span>
                            )}
                            {hasInfo && (
                                <button
                                    onClick={() => setOpen('info')}
                                    className="inline-flex h-8 items-center gap-1.5 rounded-full bg-muted px-3 text-xs font-semibold active:scale-95"
                                >
                                    <Ico name="info" className="size-3.5" />
                                    Info kafe
                                </button>
                            )}
                        </div>
                    )}
                    <p className="mt-2 text-sm text-muted-foreground">
                        {qris
                            ? 'Pilih menu, kirim pesanan, bayar langsung dari HP.'
                            : 'Pembayaran di kasir.'}
                    </p>
                </header>

                <div className="sticky top-0 z-10 border-b bg-background/90 backdrop-blur-md">
                    {allCount > 6 && (
                        <div className="px-5 pt-2.5">
                            <label className="relative block">
                                <Ico
                                    name="search"
                                    className="pointer-events-none absolute top-1/2 left-4 size-[18px] -translate-y-1/2 text-muted-foreground"
                                />
                                <input
                                    type="search"
                                    value={q}
                                    onChange={(e) => setQ(e.target.value)}
                                    placeholder="Cari menu"
                                    aria-label="Cari menu"
                                    className="h-11 w-full rounded-full border-0 bg-muted pr-4 pl-10 text-base outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-primary/40"
                                />
                            </label>
                        </div>
                    )}
                    <div className="flex items-center gap-2 px-5 py-2.5">
                        {sections.length > 4 && (
                            <button
                                onClick={() => setOpen('cats')}
                                aria-label="Semua kategori"
                                className="grid size-10 shrink-0 place-items-center rounded-full bg-muted active:scale-95"
                            >
                                <Ico name="list" className="size-[18px]" />
                            </button>
                        )}
                        <nav
                            ref={chips}
                            className="relative flex min-w-0 flex-1 [scrollbar-width:none] gap-2 overflow-x-auto"
                            aria-label="Kategori"
                        >
                            {sections.map((c) => (
                                <button
                                    key={c.id}
                                    data-chip={c.id}
                                    onClick={() => goTo(c.id)}
                                    aria-pressed={cat === c.id}
                                    className={`h-10 shrink-0 rounded-full px-4 text-sm font-semibold transition-colors ${cat === c.id ? 'bg-primary text-primary-foreground shadow-sm' : 'bg-muted text-foreground/80'}`}
                                >
                                    {c.name}
                                </button>
                            ))}
                        </nav>
                    </div>
                </div>

                <main>
                    {sections.map((c) => (
                        <section
                            key={c.id}
                            id={`cat-${c.id}`}
                            data-cat={c.id}
                            className="scroll-mt-28 px-5 pt-6"
                        >
                            <h2 className="flex items-baseline gap-2 text-xl font-bold tracking-tight">
                                {c.name}
                                <span className="text-sm font-medium text-muted-foreground">
                                    {c.products.length}
                                </span>
                            </h2>
                            <ul className="mt-1 divide-y divide-border/70">
                                {c.products.map((p) => {
                                    const hasOptions =
                                        p.variants.length > 0 ||
                                        p.modifier_groups.length > 0;
                                    const out = !p.is_available;
                                    const inCart = cart
                                        .filter((l) => l.product.id === p.id)
                                        .reduce((a, l) => a + l.qty, 0);

                                    return (
                                        <li
                                            key={p.id}
                                            className="flex items-start gap-4 py-4"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <h3
                                                    className={`text-base leading-snug font-semibold ${out ? 'text-muted-foreground' : ''}`}
                                                >
                                                    {p.name}
                                                </h3>
                                                {p.description && (
                                                    <p className="mt-1 line-clamp-2 text-[13px] leading-snug text-muted-foreground">
                                                        {p.description}
                                                    </p>
                                                )}
                                                <p
                                                    className={`mt-2.5 text-[15px] font-bold tabular-nums ${out ? 'text-muted-foreground' : ''}`}
                                                >
                                                    {p.variants.length > 1
                                                        ? `dari ${rp(Math.min(...p.variants.map((v) => v.price)))}`
                                                        : rp(p.price)}
                                                </p>
                                            </div>
                                            <div className="relative size-[104px] shrink-0">
                                                <div
                                                    className={`size-full overflow-hidden rounded-2xl shadow-sm ring-1 ring-black/5 ${out ? 'opacity-45 grayscale' : ''}`}
                                                >
                                                    <ProductArt
                                                        name={p.name}
                                                        image={p.image}
                                                        tint={
                                                            tintOf.get(c.id) ??
                                                            0
                                                        }
                                                    />
                                                </div>
                                                <div className="absolute right-1.5 -bottom-2.5">
                                                    {out ? (
                                                        <span className="inline-flex h-8 items-center rounded-full bg-foreground/85 px-3 text-xs font-semibold text-background">
                                                            Habis
                                                        </span>
                                                    ) : hasOptions ? (
                                                        <button
                                                            onClick={() =>
                                                                setPicking(p)
                                                            }
                                                            aria-label={`Pilih ${p.name}`}
                                                            className="flex h-10 items-center gap-1.5 rounded-full bg-primary px-4 text-sm font-semibold text-primary-foreground shadow-md active:scale-95"
                                                        >
                                                            {inCart > 0 && (
                                                                <span className="grid min-w-5 place-items-center rounded-full bg-white/25 px-1 text-xs tabular-nums">
                                                                    {inCart}
                                                                </span>
                                                            )}
                                                            Pilih
                                                        </button>
                                                    ) : inCart === 0 ? (
                                                        <button
                                                            onClick={() =>
                                                                bump(p, 1)
                                                            }
                                                            aria-label={`Tambah ${p.name}`}
                                                            className="grid size-10 place-items-center rounded-full bg-primary text-2xl leading-none text-primary-foreground shadow-md active:scale-90"
                                                        >
                                                            +
                                                        </button>
                                                    ) : (
                                                        <div className="flex h-10 items-center rounded-full bg-primary text-primary-foreground shadow-md">
                                                            <button
                                                                onClick={() =>
                                                                    bump(p, -1)
                                                                }
                                                                aria-label={`Kurangi ${p.name}`}
                                                                className="size-10 text-2xl leading-none active:scale-90"
                                                            >
                                                                −
                                                            </button>
                                                            <span
                                                                className="min-w-5 text-center text-sm font-bold tabular-nums"
                                                                aria-live="polite"
                                                            >
                                                                {inCart}
                                                            </span>
                                                            <button
                                                                onClick={() =>
                                                                    bump(p, 1)
                                                                }
                                                                aria-label={`Tambah ${p.name}`}
                                                                className="size-10 text-2xl leading-none active:scale-90"
                                                            >
                                                                +
                                                            </button>
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </section>
                    ))}
                    {sections.length === 0 && (
                        <p className="px-5 py-16 text-center text-sm text-muted-foreground">
                            {q
                                ? 'Menu tidak ditemukan.'
                                : 'Menu belum tersedia.'}
                        </p>
                    )}
                </main>

                <div
                    className={`fixed inset-x-0 bottom-0 z-20 grid gap-2 px-4 pt-2 pb-[max(0.75rem,env(safe-area-inset-bottom))] ${status.rounds.length === 0 && count === 0 ? 'hidden' : ''}`}
                >
                    {status.rounds.length > 0 && (
                        <button
                            onClick={() => setOpen('status')}
                            className="flex h-12 items-center justify-between rounded-2xl border bg-card px-4 text-sm font-semibold shadow-lg active:scale-[0.99]"
                        >
                            <span className="flex items-center gap-2">
                                <span
                                    aria-hidden
                                    className={`size-2.5 rounded-full ${lastStatus === 'Siap' || (!kds && lastStatus === 'Diterima') ? 'bg-emerald-500' : lastStatus === 'Ditolak' ? 'bg-red-500' : 'animate-pulse bg-amber-500'}`}
                                />
                                Lihat status pesanan
                            </span>
                            <span className="text-muted-foreground tabular-nums">
                                {rp(status.total)}
                            </span>
                        </button>
                    )}
                    {count > 0 && (
                        <button
                            onClick={() => setOpen('cart')}
                            className="flex h-16 items-center justify-between rounded-2xl bg-primary pr-5 pl-3 text-base font-semibold text-primary-foreground shadow-xl active:scale-[0.99]"
                        >
                            <span className="flex items-center gap-3">
                                <span
                                    aria-hidden
                                    className="grid size-10 place-items-center rounded-xl bg-white/20 text-base font-bold tabular-nums"
                                >
                                    {count}
                                </span>
                                <span className="grid text-left leading-tight">
                                    <span>Lihat pesanan</span>
                                    <span className="text-xs font-normal opacity-85">
                                        {count} item
                                    </span>
                                </span>
                            </span>
                            <span className="text-lg font-bold tabular-nums">
                                {rp(total)}
                            </span>
                        </button>
                    )}
                </div>

                {picking && (
                    <Picker
                        product={picking}
                        onClose={() => setPicking(null)}
                        onAdd={(l) => {
                            add(l);
                            setPicking(null);
                        }}
                    />
                )}

                {open === 'cart' && (
                    <Sheet
                        title="Pesanan kamu"
                        onClose={() => setOpen(null)}
                        footer={
                            <>
                                {error && (
                                    <p
                                        role="alert"
                                        className="mb-2 text-sm text-red-700 dark:text-red-400"
                                    >
                                        {error}
                                    </p>
                                )}
                                <button
                                    disabled={
                                        busy ||
                                        name.trim() === '' ||
                                        cart.length === 0
                                    }
                                    onClick={submit}
                                    className="h-14 w-full rounded-2xl bg-primary text-base font-bold text-primary-foreground shadow-md disabled:opacity-50"
                                >
                                    {busy
                                        ? 'Mengirim…'
                                        : `Kirim Pesanan · ${rp(total)}`}
                                </button>
                            </>
                        }
                    >
                        <ul className="divide-y divide-border/70">
                            {cart.map((l) => (
                                <li key={l.key} className="py-3.5">
                                    <div className="flex items-start justify-between gap-3">
                                        <span className="text-[15px] leading-snug font-semibold">
                                            {l.label}
                                        </span>
                                        <span className="shrink-0 text-[15px] font-bold tabular-nums">
                                            {rp(l.unit * l.qty)}
                                        </span>
                                    </div>
                                    <div className="mt-2.5 flex items-center gap-3">
                                        <div className="flex h-10 items-center rounded-full bg-muted">
                                            <button
                                                aria-label="Kurangi"
                                                className="size-10 text-xl leading-none active:scale-90"
                                                onClick={() =>
                                                    setCart((c) =>
                                                        l.qty <= 1
                                                            ? c.filter(
                                                                  (x) =>
                                                                      x.key !==
                                                                      l.key,
                                                              )
                                                            : c.map((x) =>
                                                                  x.key ===
                                                                  l.key
                                                                      ? {
                                                                            ...x,
                                                                            qty:
                                                                                x.qty -
                                                                                1,
                                                                        }
                                                                      : x,
                                                              ),
                                                    )
                                                }
                                            >
                                                −
                                            </button>
                                            <span className="min-w-6 text-center text-sm font-bold tabular-nums">
                                                {l.qty}
                                            </span>
                                            <button
                                                aria-label="Tambah"
                                                className="size-10 text-xl leading-none active:scale-90"
                                                onClick={() =>
                                                    setCart((c) =>
                                                        c.map((x) =>
                                                            x.key === l.key
                                                                ? {
                                                                      ...x,
                                                                      qty: Math.min(
                                                                          20,
                                                                          x.qty +
                                                                              1,
                                                                      ),
                                                                  }
                                                                : x,
                                                        ),
                                                    )
                                                }
                                            >
                                                +
                                            </button>
                                        </div>
                                        <input
                                            value={l.note}
                                            maxLength={120}
                                            placeholder="Catatan (mis. less ice)"
                                            aria-label={`Catatan ${l.label}`}
                                            onChange={(e) =>
                                                setCart((c) =>
                                                    c.map((x) =>
                                                        x.key === l.key
                                                            ? {
                                                                  ...x,
                                                                  note: e.target
                                                                      .value,
                                                              }
                                                            : x,
                                                    ),
                                                )
                                            }
                                            className="h-10 min-w-0 flex-1 rounded-full bg-muted px-4 text-base outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-primary/40"
                                        />
                                    </div>
                                </li>
                            ))}
                        </ul>
                        <label className="mt-4 grid gap-1.5 text-sm font-semibold">
                            <span>
                                Nama panggilan{' '}
                                <span className="text-red-600 dark:text-red-400">
                                    *
                                </span>
                            </span>
                            <input
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                maxLength={40}
                                placeholder="Contoh: Rani"
                                className="h-12 rounded-xl border bg-background px-4 text-base font-normal outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                required
                            />
                        </label>
                        <p className="mt-3 rounded-xl bg-muted p-3 text-[13px] leading-snug text-muted-foreground">
                            {autoConfirm
                                ? 'Pesanan langsung diteruskan ke dapur.'
                                : 'Pesanan dikonfirmasi kasir dulu sebelum dibuat.'}{' '}
                            Pembayaran di kasir.
                        </p>
                    </Sheet>
                )}

                {open === 'cats' && (
                    <Sheet title="Kategori" onClose={() => setOpen(null)}>
                        <ul className="divide-y divide-border/70">
                            {sections.map((c) => (
                                <li key={c.id}>
                                    <button
                                        onClick={() => {
                                            setOpen(null);
                                            goTo(c.id);
                                        }}
                                        className="flex min-h-14 w-full items-center justify-between gap-3 py-2 text-left"
                                    >
                                        <span
                                            className={`text-base ${cat === c.id ? 'font-bold text-primary' : 'font-medium'}`}
                                        >
                                            {c.name}
                                        </span>
                                        <span className="text-sm text-muted-foreground tabular-nums">
                                            {c.products.length} menu
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </Sheet>
                )}

                {open === 'info' && (
                    <Sheet title="Info kafe" onClose={() => setOpen(null)}>
                        <dl className="grid gap-4 text-sm">
                            {venue.hours && (
                                <InfoRow label="Jam buka">
                                    {venue.hours}
                                </InfoRow>
                            )}
                            {venue.address && (
                                <InfoRow label="Alamat">
                                    <span>{venue.address}</span>
                                    <a
                                        className="mt-1 block font-semibold text-primary underline"
                                        target="_blank"
                                        rel="noreferrer"
                                        href={`https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${outlet ?? ''} ${venue.address}`)}`}
                                    >
                                        Buka di Maps
                                    </a>
                                </InfoRow>
                            )}
                            {venue.phone && (
                                <InfoRow label="Telepon / WhatsApp">
                                    <a
                                        className="font-semibold text-primary underline"
                                        href={`tel:${venue.phone.replace(/[^\d+]/g, '')}`}
                                    >
                                        {venue.phone}
                                    </a>
                                </InfoRow>
                            )}
                            {venue.instagram && (
                                <InfoRow label="Instagram">
                                    <a
                                        className="font-semibold text-primary underline"
                                        target="_blank"
                                        rel="noreferrer"
                                        href={`https://instagram.com/${venue.instagram.replace(/^@/, '')}`}
                                    >
                                        {venue.instagram.startsWith('@')
                                            ? venue.instagram
                                            : `@${venue.instagram}`}
                                    </a>
                                </InfoRow>
                            )}
                            {venue.wifi_name && (
                                <InfoRow label="WiFi">
                                    <span className="block font-semibold">
                                        {venue.wifi_name}
                                    </span>
                                    {venue.wifi_password && (
                                        <CopyField
                                            value={venue.wifi_password}
                                        />
                                    )}
                                </InfoRow>
                            )}
                        </dl>
                    </Sheet>
                )}

                {open === 'status' && (
                    <Sheet
                        title="Status pesanan"
                        onClose={() => {
                            setOpen(null);
                            setDone(false);
                        }}
                    >
                        {done && (
                            <p
                                role="status"
                                className="mb-3 flex gap-2 rounded-xl bg-emerald-50 p-3 text-sm font-medium text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200"
                            >
                                <Ico name="check" className="mt-0.5 size-4" />
                                {autoConfirm
                                    ? 'Pesanan terkirim dan sedang diproses dapur.'
                                    : 'Pesanan terkirim. Menunggu konfirmasi kasir.'}
                            </p>
                        )}
                        <ul className="grid gap-3">
                            {status.rounds.map((r) => (
                                <li
                                    key={r.id}
                                    className="rounded-2xl border bg-card p-4"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <p
                                            className={`inline-block rounded-full px-3 py-1 text-xs font-bold ${r.status === 'Ditolak' ? 'bg-red-100 text-red-900 dark:bg-red-950/60 dark:text-red-200' : r.status === 'Siap' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/60 dark:text-emerald-200' : r.status === 'Menunggu Konfirmasi' ? 'bg-muted text-foreground' : 'bg-amber-100 text-amber-900 dark:bg-amber-950/60 dark:text-amber-200'}`}
                                        >
                                            {r.status}
                                        </p>
                                        <span className="text-xs text-muted-foreground">
                                            {new Date(
                                                r.created_at,
                                            ).toLocaleTimeString('id-ID', {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })}
                                        </span>
                                    </div>
                                    {r.status !== 'Ditolak' && (
                                        <Progress label={r.status} kds={kds} />
                                    )}
                                    {r.reject_reason && (
                                        <p className="mt-2 text-sm text-red-800 dark:text-red-200">
                                            Alasan: {r.reject_reason}
                                        </p>
                                    )}
                                    <ul className="mt-3 grid gap-1 text-sm">
                                        {r.items.map((i, k) => (
                                            <li
                                                key={k}
                                                className={`flex gap-2 ${i.voided ? 'line-through opacity-60' : ''}`}
                                            >
                                                <span className="font-semibold tabular-nums">
                                                    {i.qty}×
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    {i.name}
                                                    {i.modifiers.length
                                                        ? ` (${i.modifiers.join(', ')})`
                                                        : ''}
                                                </span>
                                                <span className="shrink-0 tabular-nums">
                                                    {rp(i.price)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-4 flex justify-between text-base font-bold">
                            <span>Total sementara</span>
                            <span className="tabular-nums">
                                {rp(status.total)}
                            </span>
                        </p>
                        {qris &&
                        status.rounds.length > 0 &&
                        status.rounds.every(
                            (r) => r.status !== 'Menunggu Konfirmasi',
                        ) &&
                        status.rounds.some((r) => r.status !== 'Ditolak') ? (
                            <PayBox token={token} />
                        ) : (
                            <p className="mt-2 text-center text-sm text-muted-foreground">
                                Pembayaran di kasir
                            </p>
                        )}
                    </Sheet>
                )}
            </div>
        </>
    );
}

function InfoRow({
    label,
    children,
}: {
    label: string;
    children: React.ReactNode;
}) {
    return (
        <div className="rounded-2xl bg-muted p-3.5">
            <dt className="mb-1 text-xs font-semibold text-muted-foreground">
                {label}
            </dt>
            <dd className="text-[15px] leading-snug break-words">{children}</dd>
        </div>
    );
}

/** A value the guest wants to copy (the WiFi password), with a tap-to-copy button. */
function CopyField({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <span className="mt-1.5 flex items-center gap-2">
            <code className="min-w-0 flex-1 rounded-lg bg-background px-2.5 py-1.5 text-sm break-all">
                {value}
            </code>
            <button
                onClick={async () => {
                    try {
                        await navigator.clipboard.writeText(value);
                        setCopied(true);
                        window.setTimeout(() => setCopied(false), 2000);
                    } catch {
                        // clipboard blocked: the password stays visible to type
                    }
                }}
                className="h-9 shrink-0 rounded-full bg-primary px-3.5 text-xs font-semibold text-primary-foreground active:scale-95"
            >
                {copied ? 'Tersalin' : 'Salin'}
            </button>
        </span>
    );
}

/** Where an order stands: confirmed by the cashier, being made, ready. */
function Progress({ label, kds }: { label: string; kds: boolean }) {
    const step =
        label === 'Siap'
            ? 3
            : label === 'Dibuat'
              ? 2
              : label === 'Diterima'
                ? 1
                : 0;
    // Without a kitchen screen nobody marks items as made or ready, so the order ends at "Diterima".
    const names = kds
        ? ['Dikirim', 'Diterima', 'Dibuat', 'Siap']
        : ['Dikirim', 'Diterima'];

    return (
        <ol
            className={`mt-3 grid gap-1.5 ${kds ? 'grid-cols-4' : 'grid-cols-2'}`}
            aria-hidden
        >
            {names.map((n, i) => (
                <li key={n}>
                    <span
                        className={`block h-1.5 rounded-full ${i <= step ? (step === 3 || (!kds && step >= 1) ? 'bg-emerald-500' : 'bg-primary') : 'bg-muted'}`}
                    />
                    <span
                        className={`mt-1 block text-[11px] ${i <= step ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}
                    >
                        {n}
                    </span>
                </li>
            ))}
        </ol>
    );
}

type Pay = {
    public_id: string;
    status: string;
    amount: number;
    expires_at: string | null;
    qr: string | null;
    simulated: boolean;
};

/** Pay the table's bill with a dynamic QRIS code; polls until the gateway reports it paid. */
function PayBox({ token }: { token: string }) {
    const [pay, setPay] = useState<Pay | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const start = async () => {
        setBusy(true);
        setError('');

        try {
            const res = await fetch(`/m/${token}/pay`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
            });
            const data = await res.json();

            if (!res.ok) {
                setError(
                    data.message ?? 'QRIS belum bisa dibuat. Bayar di kasir.',
                );
            } else {
                setPay(data);
            }
        } catch {
            setError('Koneksi terputus. Coba lagi.');
        } finally {
            setBusy(false);
        }
    };

    useEffect(() => {
        if (!pay || pay.status !== 'pending') {
            return;
        }

        const id = window.setInterval(async () => {
            try {
                const res = await fetch(`/m/${token}/pay/${pay.public_id}`, {
                    headers: { Accept: 'application/json' },
                });

                if (res.ok) {
                    setPay(await res.json());
                }
            } catch {
                // keep waiting
            }
        }, 3000);

        return () => window.clearInterval(id);
    }, [pay, token]);

    if (pay?.status === 'paid') {
        return (
            <p
                role="status"
                className="mt-4 rounded-2xl bg-emerald-50 p-4 text-center font-semibold text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200"
            >
                Pembayaran {rp(pay.amount)} berhasil. Terima kasih!
            </p>
        );
    }

    if (pay?.status === 'pending' && pay.qr) {
        return (
            <div className="mt-4 grid justify-items-center gap-2 rounded-2xl border bg-card p-4 shadow-sm">
                <p className="font-semibold">
                    Scan untuk membayar {rp(pay.amount)}
                </p>
                <img
                    src={pay.qr}
                    alt="Kode QRIS"
                    className="size-64 rounded-lg bg-white p-2"
                />
                <p className="text-center text-sm text-muted-foreground">
                    Buka aplikasi bank atau e-wallet (BCA, GoPay, OVO, Dana,
                    dll), pilih Scan QRIS. Halaman ini otomatis berubah setelah
                    lunas.
                </p>
                {pay.simulated && (
                    <p className="text-xs text-amber-800 dark:text-amber-200">
                        Mode simulasi: tidak ada uang yang bergerak.
                    </p>
                )}
            </div>
        );
    }

    return (
        <div className="mt-4 grid gap-2">
            {pay && pay.status !== 'pending' && (
                <p
                    role="alert"
                    className="text-center text-sm text-red-800 dark:text-red-200"
                >
                    Kode QR sudah tidak berlaku. Buat yang baru.
                </p>
            )}
            {error && (
                <p
                    role="alert"
                    className="text-center text-sm text-red-800 dark:text-red-200"
                >
                    {error}
                </p>
            )}
            <button
                type="button"
                disabled={busy}
                onClick={() => void start()}
                className="h-14 rounded-2xl bg-primary text-base font-bold text-primary-foreground shadow-md disabled:opacity-60"
            >
                {busy ? 'Membuat QR…' : 'Bayar dengan QRIS'}
            </button>
            <p className="text-center text-sm text-muted-foreground">
                Atau bayar di kasir
            </p>
        </div>
    );
}

/** "Panggil waiter": the guest picks why, staff hear a bell at their screen, and the guest sees when it is handled. */
function CallWaiter({
    token,
    reasons,
    state,
    onState,
}: {
    token: string;
    reasons: Record<string, string>;
    state: CallState;
    onState: (c: CallState) => void;
}) {
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const waiting = state?.status === 'pending';
    const [flash, setFlash] = useState(false);
    const was = useRef<string | undefined>(state?.status);

    // "Sudah ditangani" is a short thank-you, then the button is ready for the next call.
    useEffect(() => {
        if (was.current === 'pending' && state?.status === 'handled') {
            setFlash(true);
            const id = window.setTimeout(() => setFlash(false), 6000);

            return () => window.clearTimeout(id);
        }

        was.current = state?.status;
    }, [state?.status]);
    const handled = flash && state?.status === 'handled';

    const send = async (reason: string) => {
        setBusy(true);
        setError(null);

        try {
            const res = await fetch(`/m/${token}/call`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf(),
                },
                body: JSON.stringify({ reason }),
            });

            if (res.status === 429) {
                setError('Terlalu sering. Tunggu sebentar ya.');

                return;
            }

            if (!res.ok) {
                setError('Belum bisa memanggil. Coba lagi.');

                return;
            }

            onState((await res.json()).call);
            setOpen(false);
        } catch {
            setError('Koneksi bermasalah. Coba lagi.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <>
            <button
                onClick={() => setOpen(true)}
                className={`flex h-11 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-semibold shadow-sm active:scale-95 ${waiting ? 'bg-amber-100 text-amber-900 ring-1 ring-amber-300 dark:bg-amber-950/60 dark:text-amber-200 dark:ring-amber-800' : handled ? 'bg-emerald-50 text-emerald-800 ring-1 ring-emerald-200 dark:bg-emerald-950/60 dark:text-emerald-200 dark:ring-emerald-800' : 'bg-muted text-foreground'}`}
            >
                <Ico
                    name={handled ? 'check' : 'bell'}
                    className={`size-4 ${waiting ? 'animate-pulse' : ''}`}
                />
                {waiting
                    ? 'Menunggu waiter…'
                    : handled
                      ? 'Sudah ditangani'
                      : 'Panggil waiter'}
            </button>
            {open &&
                createPortal(
                    <Sheet
                        title="Panggil waiter"
                        onClose={() => setOpen(false)}
                    >
                        {waiting && (
                            <p className="mb-3 rounded-xl bg-amber-100 p-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
                                Waiter sudah dipanggil ({state?.reason}). Tekan
                                lagi kalau belum datang.
                            </p>
                        )}
                        <p className="mb-3 text-sm text-muted-foreground">
                            Pilih keperluan, staf akan datang ke meja kamu.
                        </p>
                        <div className="grid gap-2">
                            {Object.entries(reasons).map(([key, label]) => (
                                <button
                                    key={key}
                                    disabled={busy}
                                    onClick={() => void send(key)}
                                    className="h-14 rounded-2xl bg-muted px-4 text-left text-base font-semibold active:scale-[0.99] disabled:opacity-50"
                                >
                                    {label}
                                </button>
                            ))}
                        </div>
                        {error && (
                            <p
                                role="alert"
                                className="mt-3 text-sm text-red-700 dark:text-red-400"
                            >
                                {error}
                            </p>
                        )}
                    </Sheet>,
                    document.body,
                )}
        </>
    );
}

function Sheet({
    title,
    onClose,
    children,
    footer,
}: {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
    footer?: React.ReactNode;
}) {
    // keep the page behind from scrolling while a sheet is open
    useEffect(() => {
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = prev;
        };
    }, []);

    return (
        <div
            className="qr-fade fixed inset-0 z-30 flex items-end bg-black/45"
            role="dialog"
            aria-modal="true"
            aria-label={title}
            onClick={onClose}
        >
            <div
                className="qr-rise flex max-h-[92dvh] w-full flex-col rounded-t-[28px] bg-card shadow-2xl"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="px-5 pt-2.5">
                    <div
                        aria-hidden
                        className="mx-auto mb-3 h-1.5 w-11 rounded-full bg-border"
                    />
                    <div className="flex items-center justify-between pb-2">
                        <h2 className="text-xl font-bold tracking-tight">
                            {title}
                        </h2>
                        <button
                            onClick={onClose}
                            aria-label="Tutup"
                            className="grid size-10 place-items-center rounded-full bg-muted active:scale-90"
                        >
                            <Ico name="close" className="size-[18px]" />
                        </button>
                    </div>
                </div>
                <div className="min-h-0 flex-1 overflow-y-auto px-5 pb-4">
                    {children}
                </div>
                {footer && (
                    <div className="border-t bg-card px-5 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
                        {footer}
                    </div>
                )}
                {!footer && (
                    <div className="pb-[max(0.5rem,env(safe-area-inset-bottom))]" />
                )}
            </div>
        </div>
    );
}

function Picker({
    product,
    onClose,
    onAdd,
}: {
    product: Product;
    onClose: () => void;
    onAdd: (l: Line) => void;
}) {
    const [variantId, setVariantId] = useState<number | null>(
        (product.variants.find((v) => v.is_available) ?? product.variants[0])
            ?.id ?? null,
    );
    const [picked, setPicked] = useState<Record<number, number[]>>({});
    const [qty, setQty] = useState(1);
    const [note, setNote] = useState('');

    const unit =
        (product.variants.find((v) => v.id === variantId)?.price ??
            product.price) +
        product.modifier_groups
            .flatMap((g) =>
                g.modifiers.filter((m: Modifier) =>
                    picked[g.id]?.includes(m.id),
                ),
            )
            .reduce((s, m) => s + m.price_delta, 0);
    const missing = product.modifier_groups.find(
        (g) => (picked[g.id]?.length ?? 0) < g.min,
    );

    const toggle = (gid: number, id: number, max: number) =>
        setPicked((p) => {
            const cur = p[gid] ?? [];

            return cur.includes(id)
                ? { ...p, [gid]: cur.filter((x) => x !== id) }
                : {
                      ...p,
                      [gid]:
                          max === 1
                              ? [id]
                              : cur.length < max
                                ? [...cur, id]
                                : cur,
                  };
        });

    const chip = (on: boolean) =>
        `flex min-h-12 items-center justify-between gap-2 rounded-2xl border-2 px-3.5 py-2 text-left text-sm font-medium transition-colors disabled:opacity-45 ${on ? 'border-primary bg-primary/10 text-foreground' : 'border-transparent bg-muted'}`;

    return (
        <Sheet
            title={product.name}
            onClose={onClose}
            footer={
                <>
                    {missing && (
                        <p className="mb-2 text-xs font-medium text-red-700 dark:text-red-400">
                            Pilih minimal {missing.min} untuk {missing.name}
                        </p>
                    )}
                    <div className="flex items-center gap-3">
                        <div className="flex h-14 items-center rounded-2xl bg-muted">
                            <button
                                aria-label="Kurangi"
                                className="size-12 text-2xl leading-none active:scale-90"
                                onClick={() =>
                                    setQty((q) => Math.max(1, q - 1))
                                }
                            >
                                −
                            </button>
                            <span className="min-w-6 text-center text-base font-bold tabular-nums">
                                {qty}
                            </span>
                            <button
                                aria-label="Tambah"
                                className="size-12 text-2xl leading-none active:scale-90"
                                onClick={() =>
                                    setQty((q) => Math.min(20, q + 1))
                                }
                            >
                                +
                            </button>
                        </div>
                        <button
                            disabled={!!missing}
                            onClick={() => {
                                const ids = Object.values(picked).flat();
                                const names = product.modifier_groups.flatMap(
                                    (g) =>
                                        g.modifiers
                                            .filter((m) => ids.includes(m.id))
                                            .map((m) => m.name),
                                );
                                const v = product.variants.find(
                                    (x) => x.id === variantId,
                                );
                                onAdd({
                                    key: crypto.randomUUID(),
                                    product,
                                    variantId,
                                    modifierIds: ids,
                                    qty,
                                    note: note.trim(),
                                    unit,
                                    label: [
                                        product.name,
                                        v ? `(${v.name})` : '',
                                        names.length
                                            ? `· ${names.join(', ')}`
                                            : '',
                                    ]
                                        .filter(Boolean)
                                        .join(' '),
                                });
                            }}
                            className="h-14 flex-1 rounded-2xl bg-primary text-base font-bold text-primary-foreground shadow-md disabled:opacity-50"
                        >
                            Tambah · {rp(unit * qty)}
                        </button>
                    </div>
                </>
            }
        >
            {product.description && (
                <p className="-mt-1 mb-3 text-sm leading-snug text-muted-foreground">
                    {product.description}
                </p>
            )}
            {product.variants.length > 0 && (
                <fieldset className="mb-5">
                    <legend className="mb-2 text-sm font-bold">
                        Varian{' '}
                        <span className="font-medium text-muted-foreground">
                            · pilih 1
                        </span>
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {product.variants.map((v) => (
                            <button
                                key={v.id}
                                aria-pressed={variantId === v.id}
                                disabled={!v.is_available}
                                onClick={() => setVariantId(v.id)}
                                className={chip(variantId === v.id)}
                            >
                                <span>{v.name}</span>
                                <span className="text-xs text-muted-foreground tabular-nums">
                                    {v.is_available ? rp(v.price) : 'Habis'}
                                </span>
                            </button>
                        ))}
                    </div>
                </fieldset>
            )}
            {product.modifier_groups.map((g) => (
                <fieldset key={g.id} className="mb-5">
                    <legend className="mb-2 text-sm font-bold">
                        {g.name}{' '}
                        <span className="font-medium text-muted-foreground">
                            · {g.required ? 'wajib' : 'opsional'}
                            {g.max > 1 ? `, maks ${g.max}` : ''}
                        </span>
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {g.modifiers.map((m) => {
                            const on = picked[g.id]?.includes(m.id) ?? false;

                            return (
                                <button
                                    key={m.id}
                                    aria-pressed={on}
                                    disabled={!m.is_available}
                                    onClick={() => toggle(g.id, m.id, g.max)}
                                    className={chip(on)}
                                >
                                    <span>{m.name}</span>
                                    <span className="text-xs text-muted-foreground tabular-nums">
                                        {!m.is_available
                                            ? 'Habis'
                                            : m.price_delta
                                              ? `+${rp(m.price_delta)}`
                                              : ''}
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                </fieldset>
            ))}
            <label className="grid gap-1.5 text-sm font-bold">
                Catatan
                <input
                    value={note}
                    onChange={(e) => setNote(e.target.value)}
                    maxLength={120}
                    placeholder="Opsional, mis. tanpa es"
                    aria-label="Catatan"
                    className="h-12 rounded-2xl bg-muted px-4 text-base font-normal outline-none placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-primary/40"
                />
            </label>
        </Sheet>
    );
}
