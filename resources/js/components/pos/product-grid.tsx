import { Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Category, Product } from '@/types/pos';

export function ProductGrid({
    menu,
    soldOut,
    markMode,
    onPick,
}: {
    menu: Category[];
    soldOut: number[];
    markMode: boolean;
    onPick: (p: Product) => void;
}) {
    const [cat, setCat] = useState<number | 'all'>('all');
    const [q, setQ] = useState('');

    const products = useMemo(() => {
        const needle = q.trim().toLowerCase();

        return menu
            .filter((c) => cat === 'all' || c.id === cat)
            .flatMap((c) => c.products)
            .filter(
                (p) =>
                    !needle ||
                    p.name.toLowerCase().includes(needle) ||
                    (p.sku ?? '').toLowerCase().includes(needle),
            );
    }, [menu, cat, q]);

    return (
        <div className="flex min-h-0 flex-1 flex-col gap-3">
            <div className="relative">
                <Search
                    className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden
                />
                <Input
                    className="h-11 pl-9"
                    placeholder={t('Cari nama / kode produk')}
                    aria-label={t('Cari produk')}
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
            </div>

            <div
                role="tablist"
                aria-label={t('Kategori')}
                className="flex gap-2 overflow-x-auto pb-1"
            >
                {[{ id: 'all' as const, name: t('Semua') }, ...menu].map(
                    (c) => (
                        <button
                            key={c.id}
                            role="tab"
                            aria-selected={cat === c.id}
                            onClick={() => setCat(c.id)}
                            className={cn(
                                'h-11 shrink-0 rounded-full border px-4 text-sm font-semibold transition-colors',
                                cat === c.id
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-card hover:bg-muted',
                            )}
                        >
                            {c.name}
                        </button>
                    ),
                )}
            </div>

            <div className="grid min-h-0 flex-1 auto-rows-min grid-cols-2 gap-2 overflow-y-auto pb-24 sm:grid-cols-3 md:pb-2 lg:grid-cols-4">
                {products.map((p) => {
                    const out = !p.is_available || soldOut.includes(p.id);

                    return (
                        <button
                            key={p.id}
                            disabled={out && !markMode}
                            onClick={() => onPick(p)}
                            className={cn(
                                'relative flex min-h-24 flex-col justify-between rounded-2xl border bg-card p-3 text-left transition outline-none hover:border-primary/60 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-ring active:scale-[0.98]',
                                out && 'opacity-60',
                                markMode && 'ring-2 ring-amber-500',
                            )}
                        >
                            {p.image && (
                                <img
                                    src={p.image}
                                    alt=""
                                    width={96}
                                    height={64}
                                    loading="lazy"
                                    className="mb-2 h-16 w-full rounded-xl object-cover"
                                />
                            )}
                            <span className="line-clamp-2 text-sm leading-tight font-medium">
                                {p.name}
                            </span>
                            <span className="mt-2 flex items-end justify-between gap-1">
                                <span className="text-sm font-bold text-primary tabular-nums">
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
                            {out && (
                                <span className="absolute top-2 right-2 rounded-md bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white">
                                    {t('HABIS')}
                                </span>
                            )}
                        </button>
                    );
                })}
                {products.length === 0 && (
                    <p className="col-span-full py-8 text-center text-sm text-muted-foreground">
                        {t('Produk tidak ditemukan.')}
                    </p>
                )}
            </div>
        </div>
    );
}
