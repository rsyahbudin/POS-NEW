import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader, ResponsiveTable } from '@/components/pos/ui';
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
                <ResponsiveTable
                    toolbar={false}
                    headers={['Bahan', 'Stok', 'Batas minimum', 'Status']}
                    numeric={[1, 2]}
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
                        i.low ? (
                            <span
                                key="l"
                                className="rounded bg-red-100 px-2 py-0.5 text-xs font-medium text-red-900"
                            >
                                Menipis
                            </span>
                        ) : (
                            <span key="o" className="text-emerald-700">
                                Cukup
                            </span>
                        ),
                    ])}
                />
            </div>
        </>
    );
}
