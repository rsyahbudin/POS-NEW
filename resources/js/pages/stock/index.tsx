import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Ingredient = {
    id: number;
    name: string;
    unit: string;
    stock: number;
    min_stock: number;
    low: boolean;
};

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

export default function StockList({
    ingredients,
    canCount,
}: {
    ingredients: Ingredient[];
    canCount: boolean;
}) {
    const [q, setQ] = useState('');
    const [onlyLow, setOnlyLow] = useState(false);
    const shown = ingredients.filter(
        (i) =>
            i.name.toLowerCase().includes(q.toLowerCase()) &&
            (!onlyLow || i.low),
    );
    const lowCount = ingredients.filter((i) => i.low).length;

    return (
        <>
            <Head title="Daftar Stok" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Daftar stok"
                    subtitle="Jumlah bahan yang tercatat saat ini."
                    actions={
                        canCount && (
                            <Button asChild className="h-11">
                                <Link href="/stock/count">
                                    Mulai stok opname
                                </Link>
                            </Button>
                        )
                    }
                />
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
                <ul className="grid gap-2 sm:grid-cols-2">
                    {shown.map((i) => (
                        <li
                            key={i.id}
                            className="flex items-center justify-between gap-3 rounded-xl border bg-card p-3"
                        >
                            <div>
                                <p className="font-semibold">{i.name}</p>
                                <p className="text-xs text-muted-foreground">
                                    Batas minimum {num(i.min_stock)} {i.unit}
                                </p>
                            </div>
                            <p
                                className={cn(
                                    'text-lg font-bold tabular-nums',
                                    i.low && 'text-destructive',
                                )}
                            >
                                {num(i.stock)}{' '}
                                <span className="text-sm font-medium">
                                    {i.unit}
                                </span>
                                {i.low && (
                                    <span className="block text-right text-xs font-medium">
                                        menipis
                                    </span>
                                )}
                            </p>
                        </li>
                    ))}
                    {shown.length === 0 && (
                        <li className="text-sm text-muted-foreground">
                            Tidak ada bahan.
                        </li>
                    )}
                </ul>
            </div>
        </>
    );
}
