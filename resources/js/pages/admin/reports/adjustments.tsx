import { Head, router } from '@inertiajs/react';
import { BarList, ChartCard } from '@/components/pos/charts';
import { Field, PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

type R = Record<string, string | number | null>;
type Props = {
    filters: { from: string; to: string };
    discounts: R[];
    voids: R[];
    refunds: R[];
    comps: R[];
};

export default function Adjustments({
    filters,
    discounts,
    voids,
    refunds,
    comps,
}: Props) {
    const q = new URLSearchParams({
        ...filters,
        report: 'adjustments',
    }).toString();
    const sum = (rows: R[]) =>
        rows.reduce((a, r) => a + Number(r.amount ?? 0), 0);
    const who = (r: R) => `${r.actor ?? '-'} → ${r.approver ?? '-'}`;

    return (
        <>
            <Head title="Diskon, Void, Refund" />
            <div className="grid gap-5 p-4">
                <PageHeader
                    title="Diskon, void & refund"
                    subtitle="Siapa yang melakukan dan siapa yang menyetujui."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/export?${q}&format=xlsx`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/reports/export?${q}&format=pdf`}
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
                            '/admin/reports/adjustments',
                            Object.fromEntries(
                                new FormData(e.currentTarget).entries(),
                            ),
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
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                </form>
                <ChartCard
                    title="Perbandingan pengurangan nilai transaksi"
                    hint="Total nominal per jenis pada periode terpilih"
                >
                    <BarList
                        title="Total diskon, void, refund, komplimen"
                        data={[
                            { label: 'Diskon', value: sum(discounts) },
                            { label: 'Void', value: sum(voids) },
                            { label: 'Refund', value: sum(refunds) },
                            { label: 'Komplimen', value: sum(comps) },
                        ]}
                        color="#e11d48"
                    />
                </ChartCard>
                <Section
                    title="Diskon"
                    headers={[
                        'Order',
                        'Jenis',
                        'Nominal',
                        'Alasan',
                        'Pelaku → Penyetuju',
                    ]}
                    rows={discounts.map((r) => [
                        r.number,
                        `${r.type} ${r.value}`,
                        rupiah(Number(r.amount)),
                        r.reason,
                        who(r),
                    ])}
                />
                <Section
                    title="Void item"
                    headers={[
                        'Order',
                        'Item',
                        'Nilai',
                        'Alasan',
                        'Pelaku → Penyetuju',
                    ]}
                    rows={voids.map((r) => [
                        r.number,
                        `${r.item} ×${r.qty}`,
                        rupiah(Number(r.amount)),
                        r.reason,
                        who(r),
                    ])}
                />
                <Section
                    title="Refund"
                    headers={['Order', 'Nominal', 'Alasan', 'Penyetuju']}
                    rows={refunds.map((r) => [
                        r.number,
                        rupiah(Number(r.amount)),
                        r.reason,
                        r.approver,
                    ])}
                />
                <Section
                    title="Komplimen & staff meal (harga jual)"
                    headers={[
                        'Order',
                        'Item',
                        'Nilai',
                        'Jenis',
                        'Alasan / penyetuju',
                    ]}
                    rows={comps.map((r) => [
                        r.number,
                        `${r.item} ×${r.qty}`,
                        rupiah(Number(r.amount)),
                        r.type,
                        `${r.reason} · ${r.approver ?? '-'}`,
                    ])}
                />
            </div>
        </>
    );
}

function Section({
    title,
    headers,
    rows,
}: {
    title: string;
    headers: string[];
    rows: (string | number | null)[][];
}) {
    return (
        <section>
            <h2 className="mb-2 font-semibold">{title}</h2>
            <ResponsiveTable headers={headers} rows={rows} />
        </section>
    );
}
