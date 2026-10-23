import { Head, Link, router } from '@inertiajs/react';
import {
    BarList,
    ChartCard,
    ColumnChart,
    LineChart,
} from '@/components/pos/charts';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId } from '@/lib/format';

const LABELS: Record<string, string> = {
    day: 'Per hari',
    hour: 'Per jam',
    product: 'Per produk',
    station: 'Per stasiun',
};
const HEAD: Record<string, string> = {
    day: 'Hari',
    hour: 'Jam',
    product: 'Produk',
    station: 'Stasiun',
};

type Row = {
    label: string;
    items: number;
    avg_minutes: number;
    p90_minutes: number;
    max_minutes: number;
};
type Props = {
    enabled: boolean;
    filters: { from: string; to: string; dimension: string };
    summary: {
        items: number;
        avg_minutes: number;
        p90_minutes: number;
        avg_wait: number;
        avg_make: number;
        slow: number;
    };
    rows: Row[];
    slowAfter: number;
    dimensions: string[];
};

const min = (n: number) => `${n.toLocaleString('id-ID')} mnt`;

export default function KitchenReport({
    enabled,
    filters,
    summary,
    rows,
    slowAfter,
    dimensions,
}: Props) {
    const qs = (format: string) =>
        new URLSearchParams({ ...filters, format }).toString();
    const data = rows.map((r) => ({ label: r.label, value: r.avg_minutes }));

    return (
        <>
            <Head title="Kinerja Dapur" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Kinerja dapur"
                    subtitle="Berapa menit dari pesanan dikonfirmasi sampai ditandai Siap."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/kitchen/export?${qs('xlsx')}`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/kitchen/export?${qs('pdf')}`}
                                >
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />

                {!enabled && (
                    <p
                        role="status"
                        className="rounded-2xl border bg-muted p-4 text-sm"
                    >
                        Layar dapur (KDS) belum diaktifkan, jadi waktu tidak
                        tercatat. Aktifkan di{' '}
                        <Link
                            href="/admin/settings"
                            className="font-semibold underline"
                        >
                            Pengaturan › Dapur (KDS)
                        </Link>
                        . Data di bawah hanya dari periode saat KDS pernah
                        dipakai.
                    </p>
                )}

                <form
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/reports/kitchen',
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
                </form>

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label="Rata-rata selesai"
                        value={min(summary.avg_minutes)}
                        hint={`${summary.items} item`}
                    />
                    <Stat
                        label="90% item selesai dalam"
                        value={min(summary.p90_minutes)}
                    />
                    <Stat
                        label="Menunggu dimulai / dibuat"
                        value={`${min(summary.avg_wait)} / ${min(summary.avg_make)}`}
                    />
                    <Stat
                        label={`Lewat ${slowAfter} menit`}
                        value={summary.slow}
                        hint="Batas merah di Pengaturan"
                    />
                </div>

                <ChartCard
                    title={`Rata-rata menit selesai ${LABELS[filters.dimension].toLowerCase()}`}
                >
                    {filters.dimension === 'day' ? (
                        <LineChart
                            title="Rata-rata menit selesai per hari"
                            data={data}
                            fmt={min}
                        />
                    ) : filters.dimension === 'hour' ? (
                        <ColumnChart
                            title="Rata-rata menit selesai per jam"
                            data={data.map((d) => ({
                                ...d,
                                label: d.label.slice(0, 2),
                            }))}
                            fmt={min}
                        />
                    ) : (
                        <BarList
                            title="Rata-rata menit selesai"
                            data={data}
                            fmt={min}
                        />
                    )}
                </ChartCard>

                <ResponsiveTable
                    headers={[
                        HEAD[filters.dimension],
                        'Item',
                        'Rata-rata',
                        '90% selesai dalam',
                        'Terlama',
                    ]}
                    numeric={[1, 2, 3, 4]}
                    rows={rows.map((r) => [
                        filters.dimension === 'day' ? dateId(r.label) : r.label,
                        r.items,
                        min(r.avg_minutes),
                        min(r.p90_minutes),
                        min(r.max_minutes),
                    ])}
                />
            </div>
        </>
    );
}
