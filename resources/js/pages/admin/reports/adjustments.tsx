import { Head, router } from '@inertiajs/react';
import { BarList, ChartCard } from '@/components/pos/charts';
import { Field, PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah, timeId } from '@/lib/format';

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
    const when = (r: R) =>
        r.at
            ? `${dateId(String(r.at))} ${timeId(String(r.at))}`
            : r.date
              ? dateId(String(r.date))
              : '-';
    const who = (r: R) =>
        !r.approver || r.approver === r.actor
            ? String(r.actor ?? '-')
            : `${r.actor ?? '-'} → ${r.approver}`;

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
                    className="flex flex-wrap items-end gap-3 rounded-xl border bg-card p-3"
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
                        'Waktu',
                        'Order',
                        'Jenis',
                        'Nilai diskon',
                        'Potongan',
                        'Alasan',
                        'Pelaku → Penyetuju',
                    ]}
                    numeric={[4]}
                    rows={discounts.map((r) => [
                        when(r),
                        r.number,
                        r.kind,
                        r.value_label,
                        rupiah(Number(r.amount)),
                        r.reason,
                        who(r),
                    ])}
                    total={sum(discounts)}
                />
                <Section
                    title="Void item"
                    headers={[
                        'Waktu',
                        'Order',
                        'Item',
                        'Nilai',
                        'Alasan',
                        'Pelaku → Penyetuju',
                    ]}
                    numeric={[3]}
                    total={sum(voids)}
                    rows={voids.map((r) => [
                        when(r),
                        r.number,
                        `${r.item} ×${r.qty}`,
                        rupiah(Number(r.amount)),
                        r.reason,
                        who(r),
                    ])}
                />
                <Section
                    title="Refund"
                    headers={[
                        'Waktu',
                        'Order',
                        'Nominal',
                        'Alasan',
                        'Penyetuju',
                    ]}
                    numeric={[2]}
                    total={sum(refunds)}
                    rows={refunds.map((r) => [
                        when(r),
                        r.number,
                        rupiah(Number(r.amount)),
                        r.reason,
                        r.approver,
                    ])}
                />
                <Section
                    title="Komplimen & staff meal (harga jual)"
                    headers={[
                        'Waktu',
                        'Order',
                        'Item',
                        'Nilai',
                        'Jenis',
                        'Alasan / penyetuju',
                    ]}
                    numeric={[3]}
                    total={sum(comps)}
                    rows={comps.map((r) => [
                        when(r),
                        r.number,
                        `${r.item} ×${r.qty}`,
                        rupiah(Number(r.amount)),
                        r.type === 'staff_meal' ? 'Staff meal' : 'Komplimen',
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
    numeric,
    total,
}: {
    title: string;
    headers: string[];
    rows: (string | number | null)[][];
    numeric?: number[];
    total: number;
}) {
    const amountCol = numeric?.[0] ?? 0;

    return (
        <section>
            <h2 className="mb-2 font-semibold">
                {title}{' '}
                <span className="font-normal text-muted-foreground">
                    · {rows.length} kejadian
                </span>
            </h2>
            <ResponsiveTable
                headers={headers}
                rows={rows}
                numeric={numeric}
                empty="Tidak ada pada periode ini."
                footer={
                    rows.length > 0
                        ? headers.map((_, i) =>
                              i === 0
                                  ? 'Total'
                                  : i === amountCol
                                    ? rupiah(total)
                                    : '',
                          )
                        : undefined
                }
            />
        </section>
    );
}
