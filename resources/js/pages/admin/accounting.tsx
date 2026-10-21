import { Head, router, usePage } from '@inertiajs/react';
import { BarList, ChartCard, PairedBars } from '@/components/pos/charts';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    Field,
    MoneyInput,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
    Tabs,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah } from '@/lib/format';

type Bal = {
    id: number;
    code: string;
    name: string;
    type: string;
    debit: number;
    credit: number;
    balance: number;
};
type CF = Bal & {
    opening: number;
    inflow: number;
    outflow: number;
    closing: number;
};
type Journal = {
    id: number;
    date: string;
    memo: string | null;
    is_manual: boolean;
    reversed_by: number | null;
    reverses: number | null;
    lines: { code: string; name: string; debit: number; credit: number }[];
};
type Props = {
    filters: { from: string; to: string; account_id: number | null };
    accounts: {
        id: number;
        code: string;
        name: string;
        type: string;
        is_system: boolean;
        is_active: boolean;
    }[];
    balances: Bal[];
    profitLoss: {
        revenue: Bal[];
        expense: Bal[];
        total_revenue: number;
        total_expense: number;
        net_profit: number;
    };
    balanceSheet: {
        assets: Bal[];
        liabilities: Bal[];
        equity: Bal[];
        current_earnings: number;
        total_assets: number;
        total_liabilities_equity: number;
    };
    cashFlow: CF[];
    journals: Journal[];
    ledger: {
        opening: number;
        lines: {
            entry_id: number;
            date: string;
            memo: string | null;
            debit: number;
            credit: number;
            balance: number;
        }[];
        closing: number;
    } | null;
    periods: {
        id: number;
        year: number;
        month: number;
        closed_at: string | null;
    }[];
    canManage: boolean;
};

const TYPE_LABEL: Record<string, string> = {
    asset: 'Aset (kas, bank)',
    liability: 'Kewajiban',
    equity: 'Ekuitas / modal',
    revenue: 'Pendapatan',
    expense: 'Beban',
};

type Tab =
    | 'pl'
    | 'bs'
    | 'cf'
    | 'ledger'
    | 'journal'
    | 'coa'
    | 'manual'
    | 'close';

export default function Accounting(p: Props) {
    const [tab, setTab] = useState<Tab>('pl');
    const errors = usePage().props.errors as Record<string, string>;
    const exp = (report: string, format: string) =>
        `/admin/accounting/export?${new URLSearchParams({ from: p.filters.from, to: p.filters.to, report, format, ...(p.filters.account_id ? { account_id: String(p.filters.account_id) } : {}) })}`;
    const reportKey =
        {
            pl: 'profit_loss',
            bs: 'balance_sheet',
            cf: 'cash_flow',
            ledger: 'ledger',
            journal: 'journal',
            coa: 'accounts',
        }[tab as 'pl'] ?? null;

    return (
        <>
            <Head title="Akuntansi" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Akuntansi"
                    subtitle={p.canManage ? undefined : 'Mode baca'}
                    actions={
                        reportKey ? (
                            <>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-11"
                                >
                                    <a href={exp(reportKey, 'xlsx')}>
                                        Unduh Excel
                                    </a>
                                </Button>
                                <Button
                                    asChild
                                    variant="outline"
                                    className="h-11"
                                >
                                    <a href={exp(reportKey, 'pdf')}>
                                        Unduh PDF
                                    </a>
                                </Button>
                            </>
                        ) : undefined
                    }
                />

                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(
                            '/admin/accounting',
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
                            defaultValue={p.filters.from}
                        />
                    </Field>
                    <Field label="Sampai / per tanggal">
                        <Input
                            className="h-11"
                            type="date"
                            name="to"
                            defaultValue={p.filters.to}
                        />
                    </Field>
                    {tab === 'ledger' && (
                        <Field label="Akun">
                            <Select
                                name="account_id"
                                defaultValue={p.filters.account_id ?? ''}
                            >
                                <option value="">—</option>
                                {p.accounts.map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                </form>
                <Tabs
                    value={tab}
                    onChange={setTab}
                    tabs={[
                        { id: 'pl', label: 'Laba Rugi' },
                        { id: 'bs', label: 'Neraca' },
                        { id: 'cf', label: 'Arus Kas' },
                        { id: 'ledger', label: 'Buku Besar' },
                        { id: 'journal', label: 'Jurnal Umum' },
                        { id: 'coa', label: 'Akun' },
                        ...(p.canManage
                            ? [
                                  {
                                      id: 'manual' as const,
                                      label: 'Jurnal manual',
                                  },
                                  { id: 'close' as const, label: 'Tutup buku' },
                              ]
                            : []),
                    ]}
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                {tab === 'pl' && (
                    <>
                        <div className="grid grid-cols-3 gap-3">
                            <Stat
                                label="Pendapatan"
                                value={rupiah(p.profitLoss.total_revenue)}
                            />
                            <Stat
                                label="Beban"
                                value={rupiah(p.profitLoss.total_expense)}
                            />
                            <Stat
                                label="Laba bersih"
                                value={rupiah(p.profitLoss.net_profit)}
                            />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-2">
                            <ChartCard title="Pendapatan vs beban">
                                <PairedBars
                                    title="Pendapatan dan beban periode ini"
                                    labels={['Periode ini']}
                                    a={{
                                        name: 'Pendapatan',
                                        values: [p.profitLoss.total_revenue],
                                        color: '#059669',
                                    }}
                                    b={{
                                        name: 'Beban',
                                        values: [p.profitLoss.total_expense],
                                        color: '#e11d48',
                                    }}
                                />
                            </ChartCard>
                            <ChartCard title="Beban terbesar">
                                <BarList
                                    title="Beban per akun"
                                    color="#e11d48"
                                    data={p.profitLoss.expense
                                        .map((r) => ({
                                            label: r.name,
                                            value: r.balance,
                                        }))
                                        .sort((a, b) => b.value - a.value)}
                                />
                            </ChartCard>
                        </div>
                        <ResponsiveTable
                            sortable={false}
                            toolbar={false}
                            headers={['Akun', 'Jumlah']}
                            numeric={[1]}
                            rows={[
                                ...p.profitLoss.revenue.map((r) => [
                                    r.name,
                                    rupiah(r.balance),
                                ]),
                                ...p.profitLoss.expense.map((r) => [
                                    r.name,
                                    rupiah(-r.balance),
                                ]),
                            ]}
                        />
                    </>
                )}
                {tab === 'bs' && (
                    <>
                        <div className="grid grid-cols-2 gap-3">
                            <Stat
                                label="Total aset"
                                value={rupiah(p.balanceSheet.total_assets)}
                            />
                            <Stat
                                label="Kewajiban + ekuitas"
                                value={rupiah(
                                    p.balanceSheet.total_liabilities_equity,
                                )}
                                hint={
                                    p.balanceSheet.total_assets ===
                                    p.balanceSheet.total_liabilities_equity
                                        ? 'Seimbang ✔'
                                        : 'TIDAK SEIMBANG'
                                }
                            />
                        </div>
                        <ResponsiveTable
                            sortable={false}
                            toolbar={false}
                            headers={['Akun', 'Saldo']}
                            numeric={[1]}
                            rows={[
                                ...p.balanceSheet.assets,
                                ...p.balanceSheet.liabilities,
                                ...p.balanceSheet.equity,
                            ]
                                .map((r) => [r.name, rupiah(r.balance)])
                                .concat([
                                    [
                                        'Laba berjalan',
                                        rupiah(p.balanceSheet.current_earnings),
                                    ],
                                ])}
                        />
                    </>
                )}
                {tab === 'cf' && (
                    <>
                        <ChartCard title="Uang masuk vs keluar per akun kas / bank">
                            <PairedBars
                                title="Arus kas per akun"
                                labels={p.cashFlow.map((a) => a.name)}
                                a={{
                                    name: 'Masuk',
                                    values: p.cashFlow.map((a) => a.inflow),
                                    color: '#059669',
                                }}
                                b={{
                                    name: 'Keluar',
                                    values: p.cashFlow.map((a) => a.outflow),
                                    color: '#e11d48',
                                }}
                            />
                        </ChartCard>
                        <ResponsiveTable
                            headers={[
                                'Akun kas/bank',
                                'Saldo awal',
                                'Masuk',
                                'Keluar',
                                'Saldo akhir',
                            ]}
                            numeric={[1, 2, 3, 4]}
                            rows={p.cashFlow.map((a) => [
                                a.name,
                                rupiah(a.opening),
                                rupiah(a.inflow),
                                rupiah(a.outflow),
                                rupiah(a.closing),
                            ])}
                        />
                    </>
                )}
                {tab === 'ledger' &&
                    (p.ledger ? (
                        <ResponsiveTable
                            headers={[
                                'Tanggal',
                                'Memo',
                                'Debit',
                                'Kredit',
                                'Saldo',
                            ]}
                            numeric={[2, 3, 4]}
                            rows={[
                                [
                                    'Saldo awal',
                                    '',
                                    '',
                                    '',
                                    rupiah(p.ledger.opening),
                                ],
                                ...p.ledger.lines.map((l) => [
                                    dateId(l.date),
                                    l.memo ?? '',
                                    l.debit ? rupiah(l.debit) : '',
                                    l.credit ? rupiah(l.credit) : '',
                                    rupiah(l.balance),
                                ]),
                            ]}
                        />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Pilih akun lalu tampilkan.
                        </p>
                    ))}
                {tab === 'journal' && (
                    <Journals journals={p.journals} canManage={p.canManage} />
                )}
                {tab === 'coa' && (
                    <Coa
                        accounts={p.accounts}
                        canManage={p.canManage}
                        balances={p.balances}
                    />
                )}
                {tab === 'manual' && (
                    <Manual accounts={p.accounts} today={p.filters.to} />
                )}
                {tab === 'close' && <Close periods={p.periods} />}
            </div>
        </>
    );
}

function Journals({
    journals,
    canManage,
}: {
    journals: Journal[];
    canManage: boolean;
}) {
    return (
        <div className="grid gap-2">
            {journals.map((j) => (
                <div
                    key={j.id}
                    className="rounded-xl border bg-card p-3 text-sm"
                >
                    <div className="flex items-start justify-between gap-2">
                        <p className="font-medium">
                            #{j.id} · {dateId(j.date)} · {j.memo}
                            {j.is_manual && (
                                <span className="ml-1 rounded bg-amber-200 px-1 text-xs">
                                    manual
                                </span>
                            )}
                            {j.reversed_by && (
                                <span className="ml-1 rounded bg-red-200 px-1 text-xs">
                                    dibalik
                                </span>
                            )}
                            {j.reverses && (
                                <span className="ml-1 rounded bg-slate-200 px-1 text-xs">
                                    pembalik
                                </span>
                            )}
                        </p>
                        {canManage && !j.reversed_by && !j.reverses && (
                            <Button
                                size="sm"
                                variant="ghost"
                                className="h-9"
                                onClick={() => {
                                    const memo = prompt(
                                        'Alasan jurnal pembalik?',
                                    );
                                    if (memo)
                                        router.post(
                                            `/admin/accounting/journals/${j.id}/reverse`,
                                            { memo },
                                            { preserveScroll: true },
                                        );
                                }}
                            >
                                Balik
                            </Button>
                        )}
                    </div>
                    <table className="mt-1 w-full">
                        <tbody>
                            {j.lines.map((l, i) => (
                                <tr key={i}>
                                    <td className={l.credit ? 'pl-6' : ''}>
                                        {l.name}
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {l.debit ? rupiah(l.debit) : ''}
                                    </td>
                                    <td className="text-right tabular-nums">
                                        {l.credit ? rupiah(l.credit) : ''}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ))}
            {journals.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Belum ada jurnal pada periode ini.
                </p>
            )}
        </div>
    );
}

function Coa({
    accounts,
    canManage,
    balances,
}: {
    accounts: Props['accounts'];
    canManage: boolean;
    balances: Bal[];
}) {
    const [n, setN] = useState({ name: '', type: 'asset' });

    return (
        <div className="grid gap-3">
            <ResponsiveTable
                headers={['Akun', 'Tipe', 'Saldo periode']}
                numeric={[2]}
                rows={accounts.map((a) => [
                    `${a.name}${a.is_system ? ' 🔒' : ''}${a.is_active ? '' : ' (nonaktif)'}`,
                    TYPE_LABEL[a.type] ?? a.type,
                    rupiah(balances.find((b) => b.id === a.id)?.balance ?? 0),
                ])}
            />
            {canManage && (
                <section className="grid max-w-xl gap-2 rounded-xl border bg-card p-3">
                    <h3 className="font-medium">
                        Akun baru (mis. rekening bank QRIS)
                    </h3>
                    <div className="grid gap-2">
                        <Select
                            value={n.type}
                            onChange={(e) =>
                                setN({ ...n, type: e.target.value })
                            }
                        >
                            {Object.entries(TYPE_LABEL).map(([v, l]) => (
                                <option key={v} value={v}>
                                    {l}
                                </option>
                            ))}
                        </Select>
                    </div>
                    <Input
                        className="h-11"
                        placeholder="Nama akun"
                        value={n.name}
                        onChange={(e) => setN({ ...n, name: e.target.value })}
                    />
                    <Button
                        className="h-11"
                        disabled={!n.name}
                        onClick={() =>
                            router.post('/admin/accounting/accounts', n, {
                                preserveScroll: true,
                                onSuccess: () =>
                                    setN({ name: '', type: 'asset' }),
                            })
                        }
                    >
                        Tambah akun
                    </Button>
                </section>
            )}
        </div>
    );
}

type Line = {
    account_id: number | '';
    debit: number | '';
    credit: number | '';
};

function Manual({
    accounts,
    today,
}: {
    accounts: Props['accounts'];
    today: string;
}) {
    const [date, setDate] = useState(today);
    const [memo, setMemo] = useState('');
    const [lines, setLines] = useState<Line[]>([
        { account_id: '', debit: '', credit: '' },
        { account_id: '', debit: '', credit: '' },
    ]);
    const d = lines.reduce((s, l) => s + (l.debit || 0), 0);
    const c = lines.reduce((s, l) => s + (l.credit || 0), 0);

    return (
        <section className="grid max-w-3xl gap-3 rounded-xl border bg-card p-4">
            <p className="text-sm text-muted-foreground">
                Untuk koreksi dan saldo awal go-live (jurnal pembuka terhadap
                Modal Pemilik). Jurnal yang diposting tidak bisa diedit, hanya
                dibalik.
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
                <Field label="Tanggal">
                    <Input
                        className="h-11"
                        type="date"
                        value={date}
                        onChange={(e) => setDate(e.target.value)}
                    />
                </Field>
                <Field label="Memo">
                    <Input
                        className="h-11"
                        value={memo}
                        onChange={(e) => setMemo(e.target.value)}
                        placeholder="Saldo awal go-live"
                    />
                </Field>
            </div>
            {lines.map((l, i) => (
                <div
                    key={i}
                    className="grid grid-cols-[1fr_110px_110px_44px] gap-2"
                >
                    <Select
                        value={l.account_id}
                        onChange={(e) =>
                            setLines(
                                lines.map((x, j) =>
                                    j === i
                                        ? {
                                              ...x,
                                              account_id: Number(
                                                  e.target.value,
                                              ),
                                          }
                                        : x,
                                ),
                            )
                        }
                        aria-label="Akun"
                    >
                        <option value="">Akun…</option>
                        {accounts.map((a) => (
                            <option key={a.id} value={a.id}>
                                {a.name}
                            </option>
                        ))}
                    </Select>
                    <MoneyInput
                        value={l.debit}
                        placeholder="Debit"
                        aria-label="Debit"
                        onChange={(v) =>
                            setLines(
                                lines.map((x, j) =>
                                    j === i
                                        ? {
                                              ...x,
                                              debit: v,
                                              credit: v ? '' : x.credit,
                                          }
                                        : x,
                                ),
                            )
                        }
                    />
                    <MoneyInput
                        value={l.credit}
                        placeholder="Kredit"
                        aria-label="Kredit"
                        onChange={(v) =>
                            setLines(
                                lines.map((x, j) =>
                                    j === i
                                        ? {
                                              ...x,
                                              credit: v,
                                              debit: v ? '' : x.debit,
                                          }
                                        : x,
                                ),
                            )
                        }
                    />
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11"
                        aria-label="Hapus baris"
                        onClick={() =>
                            setLines(lines.filter((_, j) => j !== i))
                        }
                    >
                        <Trash2 />
                    </Button>
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                className="h-10 w-fit"
                onClick={() =>
                    setLines([
                        ...lines,
                        { account_id: '', debit: '', credit: '' },
                    ])
                }
            >
                <Plus /> Baris
            </Button>
            <p
                className={
                    d === c && d > 0 ? 'text-emerald-700' : 'text-destructive'
                }
                aria-live="polite"
            >
                Debit {rupiah(d)} · Kredit {rupiah(c)}{' '}
                {d === c && d > 0 ? '✔ seimbang' : '— belum seimbang'}
            </p>
            <Button
                className="h-12"
                disabled={
                    d !== c ||
                    d === 0 ||
                    !memo.trim() ||
                    lines.some((l) => (l.debit || l.credit) && !l.account_id)
                }
                onClick={() =>
                    router.post(
                        '/admin/accounting/journals',
                        {
                            date,
                            memo,
                            lines: lines
                                .filter((l) => l.debit || l.credit)
                                .map((l) => ({
                                    account_id: l.account_id,
                                    debit: l.debit || 0,
                                    credit: l.credit || 0,
                                })),
                        },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                setMemo('');
                                setLines([
                                    { account_id: '', debit: '', credit: '' },
                                    { account_id: '', debit: '', credit: '' },
                                ]);
                            },
                        },
                    )
                }
            >
                Posting jurnal
            </Button>
        </section>
    );
}

function Close({ periods }: { periods: Props['periods'] }) {
    const now = new Date();
    const [y, setY] = useState(now.getFullYear());
    const [m, setM] = useState(now.getMonth() + 1);

    return (
        <section className="grid max-w-xl gap-3">
            <p className="text-sm text-muted-foreground">
                Tutup buku mengunci periode dari jurnal baru. Tidak bisa dibuka
                lagi dari aplikasi.
            </p>
            <div className="flex items-end gap-2">
                <Field label="Bulan">
                    <Input
                        className="h-11 w-24"
                        type="number"
                        min={1}
                        max={12}
                        value={m}
                        onChange={(e) => setM(Number(e.target.value))}
                    />
                </Field>
                <Field label="Tahun">
                    <Input
                        className="h-11 w-28"
                        type="number"
                        value={y}
                        onChange={(e) => setY(Number(e.target.value))}
                    />
                </Field>
                <Button
                    variant="destructive"
                    className="h-11"
                    onClick={() =>
                        confirm(`Tutup buku ${m}/${y}?`) &&
                        router.post(
                            '/admin/accounting/close-period',
                            { year: y, month: m },
                            { preserveScroll: true },
                        )
                    }
                >
                    Tutup buku
                </Button>
            </div>
            <ResponsiveTable
                headers={['Periode', 'Status']}
                rows={periods.map((pr) => [
                    `${pr.month}/${pr.year}`,
                    pr.closed_at ? 'Tertutup' : 'Terbuka',
                ])}
            />
        </section>
    );
}
