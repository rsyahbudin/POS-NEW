import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { dateId, rupiah } from '@/lib/format';
import { StockShare } from '@/components/pos/stock-share';

type Ingredient = {
    id: number;
    name: string;
    unit: string;
    stock: number;
    min_stock: number;
    low: boolean;
    value: number | null;
    counted_at: string | null;
};

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

export default function StockList({
    ingredients,
    canCount,
    canBuy,
    showValue,
}: {
    ingredients: Ingredient[];
    canCount: boolean;
    canBuy: boolean;
    showValue: boolean;
}) {
    const [q, setQ] = useState('');
    const [onlyLow, setOnlyLow] = useState(false);
    const shown = ingredients.filter(
        (i) =>
            i.name.toLowerCase().includes(q.toLowerCase()) &&
            (!onlyLow || i.low),
    );
    const lowCount = ingredients.filter((i) => i.low).length;
    const totalValue = ingredients.reduce((a, i) => a + (i.value ?? 0), 0);

    return (
        <>
            <Head title="Daftar Stok" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Daftar stok"
                    subtitle="Jumlah bahan yang tercatat saat ini."
                    actions={
                        <>
                            {canBuy && lowCount > 0 && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-11"
                                >
                                    <Link href="/stock/purchase?menipis=1">
                                        Belanjakan yang menipis ({lowCount})
                                    </Link>
                                </Button>
                            )}
                            {canCount && (
                                <Button asChild className="h-11">
                                    <Link href="/stock/count">
                                        Mulai stok opname
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />
                <StockShare />
                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        className="h-11 max-w-xs"
                        placeholder="Cari bahan…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    <Button
                        type="button"
                        variant={onlyLow ? 'default' : 'outline'}
                        className="h-11"
                        aria-pressed={onlyLow}
                        onClick={() => setOnlyLow((v) => !v)}
                    >
                        Menipis ({lowCount})
                    </Button>
                </div>
                <ResponsiveTable
                    toolbar={false}
                    headers={[
                        'Bahan',
                        'Stok',
                        'Batas minimum',
                        ...(showValue ? ['Nilai'] : []),
                        'Terakhir dihitung',
                        'Status',
                    ]}
                    numeric={showValue ? [1, 2, 3] : [1, 2]}
                    footer={
                        showValue
                            ? [
                                  'Total nilai stok',
                                  '',
                                  '',
                                  rupiah(totalValue),
                                  '',
                                  '',
                              ]
                            : undefined
                    }
                    empty="Tidak ada bahan."
                    rows={shown.map((i) => [
                        <span key={i.id} className="font-medium">
                            {i.name}
                        </span>,
                        <span
                            key="s"
                            className={cn(
                                'font-semibold',
                                i.low && 'text-destructive',
                            )}
                        >
                            {num(i.stock)} {i.unit}
                        </span>,
                        `${num(i.min_stock)} ${i.unit}`,
                        ...(showValue ? [rupiah(i.value ?? 0)] : []),
                        i.counted_at ? dateId(i.counted_at) : 'Belum pernah',
                        i.low ? (
                            <span
                                key="l"
                                className="rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900 dark:bg-red-950/50 dark:text-red-200"
                            >
                                Menipis
                            </span>
                        ) : (
                            <span
                                key="o"
                                className="text-emerald-700 dark:text-emerald-400"
                            >
                                Cukup
                            </span>
                        ),
                    ])}
                />
            </div>
        </>
    );
}
