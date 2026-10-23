import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { ProductArt } from '@/components/pos/product-art';
import { t } from '@/lang';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Category, Product } from '@/types/pos';

/**
 * Menu for the cashier and the waiter: photo (or tinted monogram) tiles, a badge with how many of the item are already
 * in the order, a category rail on wide screens and category chips on small ones.
 */
export function ProductGrid({
    menu,
    soldOut,
    markMode,
    inCart,
    onPick,
}: {
    menu: Category[];
    soldOut: number[];
    markMode: boolean;
    inCart: Record<number, number>;
    onPick: (p: Product) => void;
}) {
    const [cat, setCat] = useState<number | 'all'>('all');
    const [q, setQ] = useState('');
    const tintOf = useMemo(
        () => new Map(menu.map((c, i) => [c.id, i] as const)),
        [menu],
    );

    const products = useMemo(() => {
        const words = q.toLowerCase().split(/\s+/).filter(Boolean);

        return menu
            .filter((c) => cat === 'all' || c.id === cat)
            .flatMap((c) =>
                c.products.map((p) => ({ p, tint: tintOf.get(c.id) ?? 0 })),
            )
            .filter(({ p }) =>
                words.every((w) =>
                    `${p.name} ${p.sku ?? ''}`.toLowerCase().includes(w),
                ),
            );
    }, [menu, cat, q, tintOf]);

    const cats = [{ id: 'all' as const, name: t('Semua') }, ...menu];

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3 xl:flex-row xl:gap-4">
            <div
                role="tablist"
                aria-label={t('Kategori')}
                className="flex shrink-0 [scrollbar-width:none] gap-2 overflow-x-auto pb-1 xl:w-40 xl:flex-col xl:gap-1 xl:overflow-x-visible xl:overflow-y-auto xl:pb-0"
            >
                {cats.map((c) => (
                    <button
                        key={c.id}
                        role="tab"
                        aria-selected={cat === c.id}
                        onClick={() => setCat(c.id)}
                        className={cn(
                            'h-10 shrink-0 rounded-full border px-4 text-sm font-medium whitespace-nowrap transition-colors xl:h-11 xl:rounded-lg xl:border-transparent xl:text-left',
                            cat === c.id
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'bg-card hover:bg-muted xl:bg-transparent',
                        )}
                    >
                        {c.name}
                    </button>
                ))}
            </div>

            <div className="flex min-h-0 flex-1 flex-col gap-3">
                <div className="relative">
                    <Search
                        className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden
                    />
                    <input
                        type="search"
                        className="h-11 w-full rounded-lg border border-input bg-card pr-3 pl-9 text-base outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 sm:text-sm"
                        placeholder={t('Cari nama / kode produk')}
                        aria-label={t('Cari produk')}
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                </div>

                <div className="grid min-h-0 flex-1 auto-rows-min grid-cols-[repeat(auto-fill,minmax(9.5rem,1fr))] gap-3 overflow-y-auto pb-24 md:pb-2">
                    {products.map(({ p, tint }) => {
                        const out = !p.is_available || soldOut.includes(p.id);
                        const n = inCart[p.id] ?? 0;

                        return (
                            <button
                                key={p.id}
                                disabled={out && !markMode}
                                onClick={() => onPick(p)}
                                className={cn(
                                    'group relative flex flex-col overflow-hidden rounded-xl border bg-card text-left transition outline-none hover:border-primary/50 focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]',
                                    n > 0 &&
                                        'border-primary/60 ring-1 ring-primary/30',
                                    markMode && 'ring-2 ring-amber-500',
                                )}
                            >
                                <div
                                    className={cn(
                                        'aspect-[4/3] w-full overflow-hidden',
                                        out && 'opacity-40 grayscale',
                                    )}
                                >
                                    <ProductArt
                                        name={p.name}
                                        image={p.image}
                                        tint={tint}
                                    />
                                </div>
                                <div className="grid gap-1 p-2.5">
                                    <span className="line-clamp-2 min-h-9 text-sm leading-[1.15rem] font-medium">
                                        {p.name}
                                    </span>
                                    <span className="flex items-end justify-between gap-1">
                                        <span className="text-[15px] font-semibold tabular-nums">
                                            {p.variants.length > 1
                                                ? `${t('dari')} ${rupiah(Math.min(...p.variants.map((v) => v.price)))}`
                                                : rupiah(p.price)}
                                        </span>
                                        {p.stock_qty !== null && !out && (
                                            <span className="text-xs text-muted-foreground">
                                                {t('sisa')} {p.stock_qty}
                                            </span>
                                        )}
                                    </span>
                                </div>
                                {n > 0 && (
                                    <span className="absolute top-2 right-2 grid h-7 min-w-7 place-items-center rounded-full bg-primary px-1.5 text-sm font-semibold text-primary-foreground shadow">
                                        {n}
                                    </span>
                                )}
                                {out && (
                                    <span className="absolute top-2 left-2 rounded-md bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                        {t('HABIS')}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                    {products.length === 0 && (
                        <p className="col-span-full py-10 text-center text-sm text-muted-foreground">
                            {t('Produk tidak ditemukan.')}
                        </p>
                    )}
                </div>
            </div>
        </div>
    );
}
