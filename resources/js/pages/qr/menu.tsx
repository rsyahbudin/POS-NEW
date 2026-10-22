import { Head } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
        modifiers: string[];
        voided: boolean;
    }[];
};
type Status = { rounds: StatusRound[]; total: number };

type Props = {
    token: string;
    table: { name: string; public_id: string };
    outlet: string | null;
    menu: Category[];
    outletId: number;
    autoConfirm: boolean;
    qris: boolean;
    status: Status;
};

const rp = (n: number) => `Rp${Math.trunc(n).toLocaleString('id-ID')}`;

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

export default function QrMenu({
    token,
    table,
    outlet,
    menu: initialMenu,
    status: initialStatus,
    autoConfirm,
    qris,
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
    const [open, setOpen] = useState<'cart' | 'status' | null>(null);
    const [cat, setCat] = useState<number | 'all'>('all');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [done, setDone] = useState(false);
    const submitting = useRef(false);

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

    const products = useMemo(
        () =>
            menu
                .filter((c) => cat === 'all' || c.id === cat)
                .flatMap((c) => c.products),
        [menu, cat],
    );
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

    return (
        <>
            <Head title={`Menu · ${table.name}`} />
            <div className="min-h-dvh bg-background pb-28 text-foreground">
                <header className="sticky top-0 z-10 bg-primary px-4 py-4 text-primary-foreground">
                    <p className="text-xs font-medium text-primary-foreground/80">
                        {outlet}
                    </p>
                    <h1 className="text-xl font-bold">Meja {table.name}</h1>
                    <p className="text-xs text-primary-foreground/80">
                        Pembayaran di kasir.
                    </p>
                </header>

                <nav
                    className="flex gap-2 overflow-x-auto px-4 py-3"
                    aria-label="Kategori"
                >
                    {[{ id: 'all' as const, name: 'Semua' }, ...menu].map(
                        (c) => (
                            <button
                                key={c.id}
                                onClick={() => setCat(c.id)}
                                aria-pressed={cat === c.id}
                                className={`h-11 shrink-0 rounded-full border px-4 text-sm font-medium ${cat === c.id ? 'border-primary bg-primary text-primary-foreground' : 'bg-card'}`}
                            >
                                {c.name}
                            </button>
                        ),
                    )}
                </nav>

                <main className="grid gap-3 px-4">
                    {products.map((p) => {
                        const hasOptions =
                            p.variants.length > 0 ||
                            p.modifier_groups.length > 0;
                        const inCart = cart
                            .filter((l) => l.product.id === p.id)
                            .reduce((a, l) => a + l.qty, 0);

                        return (
                            <div
                                key={p.id}
                                className="flex min-h-[72px] items-center gap-3 rounded-2xl border bg-card p-3"
                            >
                                {p.image && (
                                    <img
                                        src={p.image}
                                        alt=""
                                        width={64}
                                        height={64}
                                        loading="lazy"
                                        className="size-16 shrink-0 rounded-xl object-cover"
                                    />
                                )}
                                <div className="min-w-0 flex-1">
                                    <span className="block font-medium">
                                        {p.name}
                                    </span>
                                    {p.description && (
                                        <span className="line-clamp-2 block text-xs text-muted-foreground">
                                            {p.description}
                                        </span>
                                    )}
                                    <span className="mt-0.5 block text-sm font-bold text-primary tabular-nums">
                                        {p.variants.length > 1
                                            ? `dari ${rp(Math.min(...p.variants.map((v) => v.price)))}`
                                            : rp(p.price)}
                                    </span>
                                </div>
                                {hasOptions ? (
                                    <div className="flex flex-col items-center gap-1">
                                        <button
                                            onClick={() => setPicking(p)}
                                            aria-label={`Pilih ${p.name}`}
                                            className="h-11 rounded-xl bg-primary px-4 text-sm font-bold text-primary-foreground active:scale-95"
                                        >
                                            {inCart > 0 ? 'Tambah' : 'Pilih'}
                                        </button>
                                        {inCart > 0 && (
                                            <span className="text-xs font-medium text-primary">
                                                {inCart} di keranjang
                                            </span>
                                        )}
                                    </div>
                                ) : inCart === 0 ? (
                                    <button
                                        onClick={() => bump(p, 1)}
                                        aria-label={`Tambah ${p.name}`}
                                        className="size-11 rounded-full bg-primary text-2xl leading-none font-bold text-primary-foreground active:scale-95"
                                    >
                                        +
                                    </button>
                                ) : (
                                    <div className="flex items-center gap-1">
                                        <button
                                            onClick={() => bump(p, -1)}
                                            aria-label={`Kurangi ${p.name}`}
                                            className="size-11 rounded-full border text-xl leading-none font-bold active:scale-95"
                                        >
                                            −
                                        </button>
                                        <span
                                            className="w-7 text-center font-bold tabular-nums"
                                            aria-live="polite"
                                        >
                                            {inCart}
                                        </span>
                                        <button
                                            onClick={() => bump(p, 1)}
                                            aria-label={`Tambah ${p.name}`}
                                            className="size-11 rounded-full bg-primary text-xl leading-none font-bold text-primary-foreground active:scale-95"
                                        >
                                            +
                                        </button>
                                    </div>
                                )}
                            </div>
                        );
                    })}
                    {products.length === 0 && (
                        <p className="py-10 text-center text-sm text-muted-foreground">
                            Menu belum tersedia.
                        </p>
                    )}
                </main>

                <div className="fixed inset-x-0 bottom-0 z-20 grid gap-2 border-t bg-card p-3">
                    {status.rounds.length > 0 && (
                        <button
                            onClick={() => setOpen('status')}
                            className="h-11 rounded-lg border text-sm font-medium"
                        >
                            Lihat status pesanan · total sementara{' '}
                            {rp(status.total)}
                        </button>
                    )}
                    {count > 0 && (
                        <button
                            onClick={() => setOpen('cart')}
                            className="flex h-14 items-center justify-between rounded-xl bg-primary px-4 text-base font-bold text-primary-foreground"
                        >
                            <span>{count} item</span>
                            <span className="tabular-nums">
                                {rp(total)} · Lihat pesanan
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
                    <Sheet title="Pesanan kamu" onClose={() => setOpen(null)}>
                        <ul className="grid gap-2">
                            {cart.map((l) => (
                                <li
                                    key={l.key}
                                    className="rounded-lg border p-2 text-sm"
                                >
                                    <div className="flex justify-between gap-2">
                                        <span className="font-medium">
                                            {l.label}
                                        </span>
                                        <span className="tabular-nums">
                                            {rp(l.unit * l.qty)}
                                        </span>
                                    </div>
                                    <input
                                        value={l.note}
                                        maxLength={120}
                                        placeholder="Catatan (mis. less ice, tanpa gula)"
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
                                        className="mt-1 h-10 w-full rounded-lg border px-2 text-sm"
                                    />
                                    <div className="mt-1 flex items-center gap-2">
                                        <button
                                            aria-label="Kurangi"
                                            className="size-11 rounded-lg border"
                                            onClick={() =>
                                                setCart((c) =>
                                                    l.qty <= 1
                                                        ? c.filter(
                                                              (x) =>
                                                                  x.key !==
                                                                  l.key,
                                                          )
                                                        : c.map((x) =>
                                                              x.key === l.key
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
                                        <span className="w-6 text-center">
                                            {l.qty}
                                        </span>
                                        <button
                                            aria-label="Tambah"
                                            className="size-11 rounded-lg border"
                                            onClick={() =>
                                                setCart((c) =>
                                                    c.map((x) =>
                                                        x.key === l.key
                                                            ? {
                                                                  ...x,
                                                                  qty: Math.min(
                                                                      20,
                                                                      x.qty + 1,
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
                                </li>
                            ))}
                        </ul>
                        <label className="mt-4 grid gap-1 text-sm font-medium">
                            Nama panggilan{' '}
                            <span className="text-red-700">*</span>
                            <input
                                value={name}
                                onChange={(e) => setName(e.target.value)}
                                maxLength={40}
                                placeholder="Contoh: Rani"
                                className="h-12 rounded-lg border px-3 text-base"
                                required
                            />
                        </label>
                        <p className="mt-2 text-xs text-muted-foreground">
                            {autoConfirm
                                ? 'Pesanan langsung diteruskan ke dapur.'
                                : 'Pesanan dikonfirmasi kasir dulu sebelum dibuat.'}{' '}
                            Pembayaran di kasir.
                        </p>
                        {error && (
                            <p
                                role="alert"
                                className="mt-2 text-sm text-red-700"
                            >
                                {error}
                            </p>
                        )}
                        <button
                            disabled={
                                busy || name.trim() === '' || cart.length === 0
                            }
                            onClick={submit}
                            className="mt-3 h-14 w-full rounded-xl bg-primary text-base font-bold text-primary-foreground disabled:opacity-50"
                        >
                            {busy
                                ? 'Mengirim…'
                                : `Kirim Pesanan · ${rp(total)}`}
                        </button>
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
                                className="mb-3 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900"
                            >
                                {autoConfirm
                                    ? 'Pesanan terkirim dan sedang diproses dapur.'
                                    : 'Pesanan terkirim. Menunggu konfirmasi kasir.'}
                            </p>
                        )}
                        <ul className="grid gap-3">
                            {status.rounds.map((r) => (
                                <li
                                    key={r.id}
                                    className="rounded-xl border p-3"
                                >
                                    <p
                                        className={`mb-1 inline-block rounded-md px-2 py-0.5 text-xs font-bold ${r.status === 'Ditolak' ? 'bg-red-100 text-red-900' : r.status === 'Siap' ? 'bg-emerald-100 text-emerald-900' : r.status === 'Menunggu Konfirmasi' ? 'bg-muted text-foreground' : 'bg-amber-100 text-amber-900'}`}
                                    >
                                        {r.status}
                                    </p>
                                    {r.reject_reason && (
                                        <p className="text-sm text-red-800">
                                            Alasan: {r.reject_reason}
                                        </p>
                                    )}
                                    <ul className="text-sm">
                                        {r.items.map((i, k) => (
                                            <li
                                                key={k}
                                                className={
                                                    i.voided
                                                        ? 'line-through opacity-60'
                                                        : ''
                                                }
                                            >
                                                {i.qty}× {i.name}
                                                {i.modifiers.length
                                                    ? ` (${i.modifiers.join(', ')})`
                                                    : ''}
                                            </li>
                                        ))}
                                    </ul>
                                </li>
                            ))}
                        </ul>
                        <p className="mt-3 text-right font-semibold">
                            Total sementara {rp(status.total)}
                        </p>
                        {qris &&
                        status.rounds.length > 0 &&
                        status.rounds.every(
                            (r) => r.status !== 'Menunggu Konfirmasi',
                        ) &&
                        status.rounds.some((r) => r.status !== 'Ditolak') ? (
                            <PayBox token={token} />
                        ) : (
                            <p className="mt-1 text-center text-sm text-muted-foreground">
                                Pembayaran di kasir
                            </p>
                        )}
                    </Sheet>
                )}
            </div>
        </>
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
                className="mt-3 rounded-lg bg-emerald-50 p-4 text-center font-semibold text-emerald-900"
            >
                Pembayaran {rp(pay.amount)} berhasil. Terima kasih!
            </p>
        );
    }

    if (pay?.status === 'pending' && pay.qr) {
        return (
            <div className="mt-3 grid justify-items-center gap-2 rounded-xl border p-4">
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
                    <p className="text-xs text-amber-800">
                        Mode simulasi: tidak ada uang yang bergerak.
                    </p>
                )}
            </div>
        );
    }

    return (
        <div className="mt-3 grid gap-2">
            {pay && pay.status !== 'pending' && (
                <p role="alert" className="text-center text-sm text-red-800">
                    Kode QR sudah tidak berlaku. Buat yang baru.
                </p>
            )}
            {error && (
                <p role="alert" className="text-center text-sm text-red-800">
                    {error}
                </p>
            )}
            <button
                type="button"
                disabled={busy}
                onClick={() => void start()}
                className="h-12 rounded-xl bg-primary font-semibold text-primary-foreground disabled:opacity-60"
            >
                {busy ? 'Membuat QR…' : 'Bayar dengan QRIS'}
            </button>
            <p className="text-center text-sm text-muted-foreground">
                Atau bayar di kasir
            </p>
        </div>
    );
}

function Sheet({
    title,
    onClose,
    children,
}: {
    title: string;
    onClose: () => void;
    children: React.ReactNode;
}) {
    return (
        <div
            className="fixed inset-0 z-30 flex items-end bg-black/50"
            role="dialog"
            aria-modal="true"
            aria-label={title}
            onClick={onClose}
        >
            <div
                className="max-h-[90dvh] w-full overflow-y-auto rounded-t-2xl bg-card p-4"
                onClick={(e) => e.stopPropagation()}
            >
                <div className="mb-3 flex items-center justify-between">
                    <h2 className="text-lg font-bold">{title}</h2>
                    <button
                        onClick={onClose}
                        aria-label="Tutup"
                        className="size-11 text-xl"
                    >
                        ✕
                    </button>
                </div>
                {children}
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
        product.variants[0]?.id ?? null,
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

    return (
        <Sheet title={product.name} onClose={onClose}>
            {product.variants.length > 0 && (
                <fieldset className="mb-3">
                    <legend className="mb-1 text-sm font-medium">Varian</legend>
                    <div className="grid grid-cols-2 gap-2">
                        {product.variants.map((v) => (
                            <button
                                key={v.id}
                                aria-pressed={variantId === v.id}
                                onClick={() => setVariantId(v.id)}
                                className={`h-12 rounded-xl border bg-card px-2 text-sm ${variantId === v.id ? 'border-primary bg-primary text-primary-foreground' : ''}`}
                            >
                                {v.name} · {rp(v.price)}
                            </button>
                        ))}
                    </div>
                </fieldset>
            )}
            {product.modifier_groups.map((g) => (
                <fieldset key={g.id} className="mb-3">
                    <legend className="mb-1 text-sm font-medium">
                        {g.name}{' '}
                        <span className="font-normal text-muted-foreground">
                            · {g.required ? 'wajib' : 'opsional'}
                        </span>
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {g.modifiers.map((m) => {
                            const on = picked[g.id]?.includes(m.id);
                            return (
                                <button
                                    key={m.id}
                                    aria-pressed={on}
                                    onClick={() => toggle(g.id, m.id, g.max)}
                                    className={`h-12 rounded-xl border bg-card px-2 text-sm ${on ? 'border-primary bg-primary text-primary-foreground' : ''}`}
                                >
                                    {m.name}
                                    {m.price_delta
                                        ? ` +${rp(m.price_delta)}`
                                        : ''}
                                </button>
                            );
                        })}
                    </div>
                </fieldset>
            ))}
            <input
                value={note}
                onChange={(e) => setNote(e.target.value)}
                maxLength={120}
                placeholder="Catatan (opsional)"
                aria-label="Catatan"
                className="mb-3 h-12 w-full rounded-lg border px-3"
            />
            <div className="flex items-center gap-3">
                <button
                    aria-label="Kurangi"
                    className="size-12 rounded-lg border"
                    onClick={() => setQty((q) => Math.max(1, q - 1))}
                >
                    −
                </button>
                <span className="w-6 text-center">{qty}</span>
                <button
                    aria-label="Tambah"
                    className="size-12 rounded-lg border"
                    onClick={() => setQty((q) => Math.min(20, q + 1))}
                >
                    +
                </button>
                <button
                    disabled={!!missing}
                    onClick={() => {
                        const ids = Object.values(picked).flat();
                        const names = product.modifier_groups.flatMap((g) =>
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
                                names.length ? `· ${names.join(', ')}` : '',
                            ]
                                .filter(Boolean)
                                .join(' '),
                        });
                    }}
                    className="h-12 flex-1 rounded-xl bg-primary font-bold text-primary-foreground disabled:opacity-50"
                >
                    Tambah · {rp(unit * qty)}
                </button>
            </div>
            {missing && (
                <p className="mt-2 text-xs text-red-700">
                    Pilih minimal {missing.min} untuk {missing.name}
                </p>
            )}
        </Sheet>
    );
}
