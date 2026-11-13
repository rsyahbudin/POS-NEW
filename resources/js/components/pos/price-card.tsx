import { InfoTip } from '@/components/pos/info-tip';
import { Button } from '@/components/ui/button';
import { rupiah } from '@/lib/format';
import { foodCostPct, suggestedPrice, verdict } from '@/lib/pricing';
import { cn } from '@/lib/utils';

const money = (n: number) => rupiah(Math.round(n));

/** Compact advice for a list cell: suggested price and whether the current price is enough. */
export function Advice({
    price,
    cost,
    target,
}: {
    price: number;
    cost: number;
    target: number;
}) {
    const rec = suggestedPrice(cost, target);
    const v = verdict(price, cost, target);

    if (rec === null) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <span className="grid justify-items-end leading-tight">
            <b className="tabular-nums">{rupiah(rec)}</b>
            <span
                className={cn(
                    'text-xs font-medium',
                    v === 'good'
                        ? 'text-emerald-700 dark:text-emerald-400'
                        : 'text-amber-700 dark:text-amber-400',
                )}
            >
                {price <= 0
                    ? 'Belum ada harga'
                    : v === 'good'
                      ? 'Harga sudah cukup'
                      : 'Harga terlalu rendah'}
            </span>
        </span>
    );
}

/** Cost, margin, food cost, and the suggested selling price for one portion or variant. */
export function PriceCard({
    title,
    price,
    cost,
    target,
    onApply,
}: {
    title?: string | null;
    price: number;
    cost: number;
    target: number;
    /** Takes the suggested price as the selling price. */
    onApply?: (price: number) => void;
}) {
    const rec = suggestedPrice(cost, target);
    const food = foodCostPct(price, cost);
    const v = verdict(price, cost, target);
    const profit = price - cost;

    return (
        <section className="grid gap-2 rounded-xl border bg-muted/40 p-3 text-sm">
            <div className="flex items-center justify-between gap-2">
                <h3 className="font-semibold">
                    {title ? `Varian ${title}` : 'Harga dan margin'}
                </h3>
                <span className="flex items-center gap-1 text-xs text-muted-foreground">
                    Target food cost {target}%
                    <InfoTip label="Info rumus harga">
                        Harga disarankan = HPP ÷ target food cost, dibulatkan ke
                        atas per Rp500. Contoh: HPP Rp16.250 ÷ 35% = Rp46.429,
                        jadi Rp46.500. Target diubah di Pengaturan &gt; Stok
                        &amp; HPP.
                    </InfoTip>
                </span>
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 tabular-nums">
                <dt className="text-muted-foreground">HPP bahan</dt>
                <dd className="text-right font-medium">{money(cost)}</dd>
                <dt className="text-muted-foreground">Harga jual sekarang</dt>
                <dd className="text-right font-medium">{money(price)}</dd>
                <dt className="text-muted-foreground">Laba per porsi</dt>
                <dd className="text-right font-medium">
                    {cost > 0 && price > 0 ? money(profit) : '-'}
                </dd>
                <dt className="text-muted-foreground">Margin</dt>
                <dd className="text-right font-medium">
                    {cost > 0 && price > 0
                        ? `${Math.round((profit / price) * 100)}%`
                        : '-'}
                </dd>
                <dt className="text-muted-foreground">Food cost</dt>
                <dd
                    className={cn(
                        'text-right font-medium',
                        v === 'low' && 'text-amber-700 dark:text-amber-400',
                    )}
                >
                    {food === null || cost <= 0 ? '-' : `${Math.round(food)}%`}
                </dd>
            </dl>
            {rec === null ? (
                <p className="text-muted-foreground">
                    Isi bahan dan harganya dulu untuk melihat harga yang
                    disarankan.
                </p>
            ) : (
                <p
                    className={cn(
                        'rounded-lg p-2.5 font-medium',
                        v === 'good'
                            ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-200'
                            : 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200',
                    )}
                >
                    Harga disarankan {rupiah(rec)}
                    {price <= 0
                        ? ' · menu ini belum punya harga jual'
                        : v === 'good'
                          ? ` · harga sekarang sudah cukup (food cost ${Math.round(food ?? 0)}%)`
                          : ` · harga sekarang ${rupiah(price)} terlalu rendah, naikkan ${rupiah(rec - price)}`}
                </p>
            )}
            {rec !== null && onApply && rec !== price && (
                <Button
                    type="button"
                    variant={price <= 0 || v === 'low' ? 'default' : 'outline'}
                    className="h-11"
                    onClick={() => onApply(rec)}
                >
                    Pakai {rupiah(rec)} sebagai harga jual
                </Button>
            )}
        </section>
    );
}
