import { Head, Link, router } from '@inertiajs/react';
import { BarList, ChartCard, LineChart } from '@/components/pos/charts';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

const LABELS: Record<string, string> = {
    item: 'Per item',
    reason: 'Per alasan',
    person: 'Per pencatat',
    day: 'Per hari',
};
const HEAD: Record<string, string> = {
    item: 'Item',
    reason: 'Alasan',
    person: 'Pencatat',
    day: 'Hari',
};

type Row = { label: string; entries: number; cost: number; share_pct: number };
type Entry = {
    id: number;
    at: string;
    label: string;
    qty: number;
    unit: string;
    reason: string;
    note: string | null;
    cost: number;
    user: string | null;
};
type Props = {
    filters: { from: string; to: string; dimension: string };
    summary: {
        cost: number;
        entries: number;
        top_item: string | null;
        top_reason: string | null;
    };
    rows: Row[];
    entries: Entry[];
    dimensions: string[];
};

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

export default function WasteReport({
    filters,
    summary,
    rows,
    entries,
    dimensions,
}: Props) {
    const qs = (format: string) =>
        new URLSearchParams({ ...filters, format }).toString();
    const data = rows.map((r) => ({ label: r.label, value: r.cost }));

    return (
        <>
            <Head title="Laporan Waste" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Laporan waste"
                    subtitle="Bahan dan menu yang terbuang: berapa nilainya, apa penyebabnya, dan siapa yang mencatat."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/waste/export?${qs('xlsx')}`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/waste/export?${qs('pdf')}`}
                                >
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/reports/waste',
                            Object.fromEntries(
                                new FormData(e.currentTarget).entries(),
                            ),
                            { preserveState: true },
                        );
                    }}
                >
                    <Field label="Dari">
                        <Input
                            className="h-11"
                            type="date"
                            name="from"
                            defaultValue={filters.from}
                        />
                    </Field>
                    <Field label="Sampai">
                        <Input
                            className="h-11"
                            type="date"
                            name="to"
                            defaultValue={filters.to}
                        />
                    </Field>
                    <Field label="Kelompokkan">
                        <Select
                            name="dimension"
                            defaultValue={filters.dimension}
                        >
                            {dimensions.map((d) => (
                                <option key={d} value={d}>
                                    {LABELS[d]}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                    <Button asChild variant="ghost" className="h-11">
                        <Link href="/stock/waste">Catat waste</Link>
                    </Button>
                </form>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat label="Nilai terbuang" value={rupiah(summary.cost)} />
                    <Stat label="Kejadian" value={summary.entries} />
                    <Stat
                        label="Item terbanyak"
                        value={summary.top_item ?? '-'}
                        hint="Menurut nilai"
                    />
                    <Stat
                        label="Alasan terbanyak"
                        value={summary.top_reason ?? '-'}
                        hint="Menurut nilai"
                    />
                </div>

                <ChartCard
                    title={`Nilai terbuang ${LABELS[filters.dimension].toLowerCase()}`}
                >
                    {filters.dimension === 'day' ? (
                        <LineChart
                            title="Nilai terbuang per hari"
                            data={data}
                            fmt={rupiah}
                        />
                    ) : (
                        <BarList
                            title="Nilai terbuang"
                            data={data.slice(0, 12)}
                            fmt={rupiah}
                        />
                    )}
                </ChartCard>

                <ResponsiveTable
                    headers={[
                        HEAD[filters.dimension],
                        'Kejadian',
                        'Nilai',
                        '% dari total',
                    ]}
                    numeric={[1, 2, 3]}
                    empty="Tidak ada waste pada periode ini."
                    rows={rows.map((r) => [
                        r.label,
                        r.entries,
                        rupiah(r.cost),
                        `${r.share_pct.toLocaleString('id-ID')}%`,
                    ])}
                />

                <section className="grid gap-2">
                    <h2 className="font-semibold">Rincian kejadian</h2>
                    <ResponsiveTable
                        headers={[
                            'Waktu',
                            'Item',
                            'Jumlah',
                            'Alasan',
                            'Catatan',
                            'Nilai',
                            'Pencatat',
                        ]}
                        numeric={[2, 5]}
                        empty="Tidak ada kejadian."
                        rows={entries.map((e) => [
                            e.at,
                            e.label,
                            `${num(e.qty)} ${e.unit}`,
                            e.reason,
                            e.note ?? '-',
                            rupiah(e.cost),
                            e.user ?? '-',
                        ])}
                    />
                </section>
            </div>
        </>
    );
}
