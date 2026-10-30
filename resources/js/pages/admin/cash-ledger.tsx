import { Head, router } from '@inertiajs/react';
import { BarList, ChartCard } from '@/components/pos/charts';
import { Field, PageHeader, ResponsiveTable, Stat } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah, timeId } from '@/lib/format';
import { SpendingTabs } from '@/components/pos/section-tabs';

type Move = {
    id: number;
    direction: 'in' | 'out';
    amount: number;
    reason: string;
    category: string | null;
    by: string | null;
    at: string;
    receipt: string | null;
};
type ShiftRow = {
    id: number;
    business_date: string;
    opened_by: string | null;
    open: boolean;
    opening: number;
    cash_sales: number;
    cash_in: number;
    cash_out: number;
    cash_refunds: number;
    expected: number;
    counted: number | null;
    difference: number | null;
    deposited: number | null;
    movements: Move[];
};
type Props = {
    filters: { from: string; to: string };
    shifts: ShiftRow[];
    totals: {
        opening: number;
        cash_sales: number;
        cash_in: number;
        cash_out: number;
        cash_refunds: number;
        expected: number;
        difference: number;
    };
    by_category: { name: string; count: number; total: number }[];
};

const signed = (n: number) =>
    `${n > 0 ? '+' : n < 0 ? '−' : ''}${rupiah(Math.abs(n))}`;

export default function CashLedger({
    filters,
    shifts,
    totals,
    by_category,
}: Props) {
    const qs = new URLSearchParams(filters).toString();

    return (
        <>
            <Head title="Laporan Kas Laci" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <SpendingTabs />
                <PageHeader
                    title="Laporan kas laci"
                    subtitle="Uang di laci per shift: modal, penjualan tunai, belanja, kas masuk, selisih, dan setoran."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/cash-ledger/export?${qs}&format=xlsx`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/cash-ledger/export?${qs}&format=pdf`}
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
                        const d = new FormData(e.currentTarget);
                        router.get('/admin/cash-ledger', {
                            from: d.get('from'),
                            to: d.get('to'),
                        });
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

                <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                    <Stat
                        label="Penjualan tunai"
                        value={rupiah(totals.cash_sales)}
                    />
                    <Stat
                        label="Belanja & uang keluar"
                        value={rupiah(totals.cash_out)}
                    />
                    <Stat label="Kas masuk" value={rupiah(totals.cash_in)} />
                    <Stat
                        label="Selisih hitung kas"
                        value={signed(totals.difference)}
                        hint="Jumlah semua shift yang sudah ditutup"
                    />
                </div>

                {by_category.length > 0 && (
                    <ChartCard title="Belanja tunai per kategori">
                        <BarList
                            title="Belanja tunai per kategori"
                            data={by_category.map((c) => ({
                                label: c.name,
                                value: c.total,
                            }))}
                            color="#e11d48"
                        />
                    </ChartCard>
                )}

                <ResponsiveTable
                    headers={[
                        'Tanggal',
                        'Dibuka oleh',
                        'Modal',
                        'Tunai masuk',
                        'Kas masuk',
                        'Uang keluar',
                        'Refund',
                        'Seharusnya',
                        'Dihitung',
                        'Selisih',
                        'Disetor',
                    ]}
                    numeric={[2, 3, 4, 5, 6, 7, 8, 9, 10]}
                    rows={shifts.map((s) => [
                        `${dateId(s.business_date)}${s.open ? ' (berjalan)' : ''}`,
                        s.opened_by ?? '-',
                        rupiah(s.opening),
                        rupiah(s.cash_sales),
                        rupiah(s.cash_in),
                        rupiah(s.cash_out),
                        rupiah(s.cash_refunds),
                        rupiah(s.expected),
                        s.counted === null ? '-' : rupiah(s.counted),
                        s.difference === null ? '-' : signed(s.difference),
                        s.deposited === null ? '-' : rupiah(s.deposited),
                    ])}
                    footer={[
                        'Total',
                        '',
                        rupiah(totals.opening),
                        rupiah(totals.cash_sales),
                        rupiah(totals.cash_in),
                        rupiah(totals.cash_out),
                        rupiah(totals.cash_refunds),
                        rupiah(totals.expected),
                        '',
                        signed(totals.difference),
                        '',
                    ]}
                />

                {shifts.some((s) => s.movements.length > 0) && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">
                            Rincian kas masuk dan keluar
                        </h2>
                        <ResponsiveTable
                            headers={[
                                'Tanggal',
                                'Jam',
                                'Arah',
                                'Kategori',
                                'Keterangan',
                                'Oleh',
                                'Nota',
                                'Nominal',
                            ]}
                            numeric={[7]}
                            rows={shifts.flatMap((s) =>
                                s.movements.map((m) => [
                                    dateId(s.business_date),
                                    timeId(m.at),
                                    m.direction === 'in' ? 'Masuk' : 'Keluar',
                                    m.category ?? '-',
                                    m.reason,
                                    m.by ?? '-',
                                    m.receipt ? (
                                        <a
                                            key={m.id}
                                            className="underline"
                                            href={m.receipt}
                                            target="_blank"
                                            rel="noreferrer"
                                        >
                                            Lihat
                                        </a>
                                    ) : (
                                        '-'
                                    ),
                                    signed(
                                        m.direction === 'in'
                                            ? m.amount
                                            : -m.amount,
                                    ),
                                ]),
                            )}
                        />
                    </section>
                )}
            </div>
        </>
    );
}
