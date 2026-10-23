import { Head, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, rupiah, timeId } from '@/lib/format';

type Row = {
    id: number;
    year: number;
    month: number;
    status: string;
    receipts: number;
    total: number;
    size: number;
    error: string | null;
    by: string | null;
    at: string | null;
};

const MONTHS = [
    'Januari',
    'Februari',
    'Maret',
    'April',
    'Mei',
    'Juni',
    'Juli',
    'Agustus',
    'September',
    'Oktober',
    'November',
    'Desember',
];

const STATUS: Record<string, string> = {
    pending: 'Antre',
    running: 'Sedang dibuat',
    done: 'Siap',
    failed: 'Gagal',
};

const mb = (b: number) =>
    b >= 1048576
        ? `${(b / 1048576).toFixed(1)} MB`
        : `${Math.ceil(b / 1024)} KB`;

const selectCls = 'h-11 rounded-lg border bg-background px-3 text-sm';

export default function ReceiptExportsPage({
    years,
    current,
    rows,
}: {
    years: number[];
    current: { year: number; month: number };
    rows: Row[];
}) {
    const [year, setYear] = useState(current.year);
    const [month, setMonth] = useState(current.month);
    const [info, setInfo] = useState<{ count: number; total: number } | null>(
        null,
    );
    const working = rows.some(
        (r) => r.status === 'pending' || r.status === 'running',
    );

    useEffect(() => {
        const ctl = new AbortController();
        fetch(`/admin/receipt-exports/preview?year=${year}&month=${month}`, {
            signal: ctl.signal,
            headers: { Accept: 'application/json' },
        })
            .then((r) => r.json())
            .then(setInfo)
            .catch(() => undefined);

        return () => ctl.abort();
    }, [year, month, rows.length]);

    useEffect(() => {
        if (!working) {
            return;
        }
        const t = setInterval(() => router.reload({ only: ['rows'] }), 3000);

        return () => clearInterval(t);
    }, [working]);

    return (
        <>
            <Head title="Struk Bulanan" />
            <div className="mx-auto grid w-full max-w-[1100px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Struk bulanan (PDF)"
                    subtitle="Satu PDF berisi struk semua transaksi lunas di bulan yang dipilih. Bulan dan tahun yang sudah lewat bisa. Berkas disimpan 30 hari."
                />
                <section className="grid gap-3 rounded-xl border bg-card p-4">
                    <div className="flex flex-wrap items-end gap-3">
                        <label className="grid gap-1 text-sm">
                            Bulan
                            <select
                                className={selectCls}
                                value={month}
                                onChange={(e) => setMonth(+e.target.value)}
                            >
                                {MONTHS.map((m, i) => (
                                    <option key={m} value={i + 1}>
                                        {m}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="grid gap-1 text-sm">
                            Tahun
                            <select
                                className={selectCls}
                                value={year}
                                onChange={(e) => setYear(+e.target.value)}
                            >
                                {years.map((y) => (
                                    <option key={y} value={y}>
                                        {y}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <Button
                            className="h-11"
                            disabled={!info || info.count === 0}
                            onClick={() =>
                                router.post(
                                    '/admin/receipt-exports',
                                    { year, month },
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Buat PDF
                        </Button>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {info === null
                            ? 'Menghitung...'
                            : info.count === 0
                              ? 'Tidak ada transaksi lunas di bulan ini.'
                              : `${info.count} struk, total ${rupiah(info.total)}. Bulan besar bisa makan beberapa menit; halaman ini boleh ditinggal.`}
                    </p>
                </section>
                <ResponsiveTable
                    headers={[
                        'Periode',
                        'Struk',
                        'Total',
                        'Status',
                        'Diminta',
                        'Aksi',
                    ]}
                    numeric={[1, 2]}
                    empty="Belum ada PDF yang dibuat."
                    rows={rows.map((r) => [
                        `${MONTHS[r.month - 1]} ${r.year}`,
                        r.status === 'done' ? r.receipts : '-',
                        r.status === 'done' ? rupiah(r.total) : '-',
                        r.status === 'failed'
                            ? `Gagal: ${r.error ?? ''}`
                            : r.status === 'done'
                              ? `${STATUS.done} (${mb(r.size)})`
                              : STATUS[r.status],
                        `${r.by ?? '-'}, ${r.at ? `${dateId(r.at)} ${timeId(r.at)}` : ''}`,
                        <span key="a" className="flex justify-end gap-1.5">
                            {r.status === 'done' && (
                                <Button asChild size="sm" className="h-9">
                                    <a
                                        href={`/admin/receipt-exports/${r.id}/download`}
                                    >
                                        Unduh
                                    </a>
                                </Button>
                            )}
                            {(r.status === 'done' || r.status === 'failed') && (
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="h-9"
                                    onClick={() =>
                                        router.delete(
                                            `/admin/receipt-exports/${r.id}`,
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Hapus
                                </Button>
                            )}
                        </span>,
                    ])}
                />
            </div>
        </>
    );
}
