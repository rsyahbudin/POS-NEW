import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    Field,
    MoneyInput,
    PageHeader,
    Select,
    Stat,
} from '@/components/pos/ui';
import { AccountMenu } from '@/components/pos/account-menu';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/lang';
import { dateId, rupiah, timeId, uuid } from '@/lib/format';

type Summary = {
    opening: number;
    cash_sales: number;
    cash_in: number;
    cash_out: number;
    cash_refunds: number;
    expected: number;
};
type ShiftData = {
    id: number;
    opened_at: string;
    closed_at: string | null;
    opening_cash: number;
    counted_cash: number | null;
    difference: number | null;
    cash_left: number | null;
    cash_deposited: number | null;
    difference_reason: string | null;
    summary: Summary;
    by_method: { name: string; type: string; total: number; count: number }[];
    orders: number;
    voids: number;
    discounts: number;
    comps: number;
    opened_by: string | null;
    closed_by: string | null;
    movements: {
        direction: 'in' | 'out';
        amount: number;
        reason: string;
        created_at: string;
        by: string | null;
    }[];
};
type Acc = { id: number; code: string; name: string };

type Props = {
    shift: ShiftData | null;
    suggestedOpening: number;
    accounts: { expense: Acc[]; other: Acc[]; bank: Acc[] };
    recent: ShiftData[];
    can: { operate: boolean };
};

export default function ShiftPage({
    shift,
    suggestedOpening,
    accounts,
    recent,
    can,
}: Props) {
    const errors = usePage().props.errors as Record<string, string> | undefined;

    return (
        <>
            <Head title="Shift" />
            <div className="mx-auto grid max-w-3xl gap-4 p-4">
                <PageHeader
                    title="Shift toko"
                    subtitle={
                        shift
                            ? `${t('Dibuka oleh')} ${shift.opened_by ?? '-'} · ${dateId(shift.opened_at)} ${timeId(shift.opened_at)}`
                            : t('Belum ada shift terbuka')
                    }
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <Link href="/pos">← {t('Kasir')}</Link>
                            </Button>
                            <AccountMenu />
                        </>
                    }
                />
                {errors && Object.values(errors).length > 0 && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                {shift ? (
                    <Open
                        shift={shift}
                        accounts={accounts}
                        operate={can.operate}
                    />
                ) : can.operate ? (
                    <OpenForm suggested={suggestedOpening} />
                ) : (
                    <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                        {t(
                            'Belum ada shift terbuka. Minta kasir atau manager membukanya.',
                        )}
                    </p>
                )}
                {shift && (
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Satu shift dipakai bersama oleh semua staf toko. Tiap pembayaran dan kas tercatat atas nama orang yang melakukannya.',
                        )}
                    </p>
                )}

                {recent.length > 0 && (
                    <section aria-label={t('Shift sebelumnya')}>
                        <h2 className="mb-2 font-semibold">
                            {t('Shift sebelumnya')}
                        </h2>
                        <div className="grid gap-2">
                            {recent.map((s) => (
                                <div
                                    key={s.id}
                                    className="rounded-lg border bg-card p-3 text-sm"
                                >
                                    <div className="flex justify-between">
                                        <span>
                                            {dateId(s.opened_at)}{' '}
                                            {timeId(s.opened_at)}–
                                            {timeId(s.closed_at)}
                                        </span>
                                        <span
                                            className={
                                                s.difference
                                                    ? 'font-semibold text-red-700'
                                                    : 'text-emerald-700'
                                            }
                                        >
                                            {t('Selisih')}{' '}
                                            {rupiah(s.difference ?? 0)}
                                        </span>
                                    </div>
                                    <p className="text-muted-foreground">
                                        {t('Dibuka oleh')} {s.opened_by ?? '-'}{' '}
                                        · {t('ditutup oleh')}{' '}
                                        {s.closed_by ?? '-'}
                                    </p>
                                    <p className="text-muted-foreground">
                                        {s.orders} order · {t('kas seharusnya')}{' '}
                                        {rupiah(s.summary.expected)} ·{' '}
                                        {t('fisik')} {rupiah(s.counted_cash)}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}

function OpenForm({ suggested }: { suggested: number }) {
    const [cash, setCash] = useState<number | ''>(suggested);
    const [busy, setBusy] = useState(false);

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <h2 className="font-semibold">{t('Buka shift')}</h2>
            <Field
                label={t('Modal kembalian di laci')}
                hint={
                    suggested > 0
                        ? `${t('Terisi dari shift sebelumnya')}: ${rupiah(suggested)}. ${t('Hitung uang, koreksi jika berbeda.')}`
                        : t('Hitung uang di laci lalu isi.')
                }
            >
                <MoneyInput value={cash} onChange={setCash} autoFocus />
            </Field>
            <Button
                className="h-12"
                disabled={busy || cash === ''}
                onClick={() => {
                    setBusy(true);
                    router.post(
                        '/shift/open',
                        {
                            opening_cash: cash,
                            device_label: navigator.userAgent.slice(0, 50),
                        },
                        { onFinish: () => setBusy(false) },
                    );
                }}
            >
                {t('Buka shift')}
            </Button>
        </section>
    );
}

function SalesBreakdown({ shift }: { shift: ShiftData }) {
    const total = shift.by_method.reduce((a, m) => a + m.total, 0);

    return (
        <section
            className="rounded-xl border bg-card p-3 text-sm"
            aria-label={t('Penerimaan per metode bayar')}
        >
            <h2 className="mb-2 font-semibold">
                {t('Penerimaan dari penjualan')}
            </h2>
            {shift.by_method.length === 0 ? (
                <p className="text-muted-foreground">
                    {t('Belum ada pembayaran.')}
                </p>
            ) : (
                <table className="w-full">
                    <thead>
                        <tr className="text-xs text-muted-foreground">
                            <th className="py-1 text-left font-normal">
                                {t('Metode')}
                            </th>
                            <th className="py-1 text-right font-normal">
                                {t('Transaksi')}
                            </th>
                            <th className="py-1 text-right font-normal">
                                {t('Jumlah')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {shift.by_method.map((m) => (
                            <tr key={m.name} className="border-t">
                                <td className="py-1.5">
                                    {m.name}
                                    {m.type !== 'cash' && (
                                        <span className="ml-1 text-xs text-muted-foreground">
                                            ({t('tidak masuk laci')})
                                        </span>
                                    )}
                                </td>
                                <td className="py-1.5 text-right tabular-nums">
                                    {m.count}
                                </td>
                                <td className="py-1.5 text-right tabular-nums">
                                    {rupiah(m.total)}
                                </td>
                            </tr>
                        ))}
                        <tr className="border-t font-semibold">
                            <td className="py-1.5" colSpan={2}>
                                {t('Total penjualan')}
                            </td>
                            <td className="py-1.5 text-right tabular-nums">
                                {rupiah(total)}
                            </td>
                        </tr>
                    </tbody>
                </table>
            )}
            <p className="mt-2 text-xs text-muted-foreground">
                {shift.orders} order · {shift.voids} void · {t('diskon')}{' '}
                {rupiah(shift.discounts)} · {shift.comps} {t('komplimen')}
            </p>
        </section>
    );
}

function Open({
    shift,
    accounts,
    operate,
}: {
    shift: ShiftData;
    accounts: Props['accounts'];
    operate: boolean;
}) {
    const s = shift.summary;

    return (
        <>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <Stat label={t('Modal awal')} value={rupiah(s.opening)} />
                <Stat
                    label={t('Penjualan tunai')}
                    value={rupiah(s.cash_sales)}
                />
                <Stat
                    label={t('Kas masuk/keluar')}
                    value={`${rupiah(s.cash_in)} / ${rupiah(s.cash_out)}`}
                />
                <Stat
                    label={t('Kas seharusnya di laci')}
                    value={rupiah(s.expected)}
                    hint={
                        s.cash_refunds
                            ? `${t('refund tunai')} ${rupiah(s.cash_refunds)}`
                            : undefined
                    }
                />
            </div>
            <SalesBreakdown shift={shift} />
            <Movement
                accounts={accounts}
                movements={shift.movements}
                operate={operate}
            />
            {operate && <CloseForm shift={shift} accounts={accounts.bank} />}
        </>
    );
}

function Movement({
    accounts,
    movements,
    operate,
}: {
    accounts: Props['accounts'];
    movements: ShiftData['movements'];
    operate: boolean;
}) {
    const [dir, setDir] = useState<'in' | 'out'>('out');
    const [amount, setAmount] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [account, setAccount] = useState<number | ''>('');
    const list = dir === 'out' ? accounts.expense : accounts.other;

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <h2 className="font-semibold">
                {t('Kas masuk / keluar (non-penjualan)')}
            </h2>
            {operate && (
                <>
                    <div role="radiogroup" className="grid grid-cols-2 gap-2">
                        <Button
                            type="button"
                            role="radio"
                            aria-checked={dir === 'out'}
                            variant={dir === 'out' ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => {
                                setDir('out');
                                setAccount('');
                            }}
                        >
                            {t('Keluar')}
                        </Button>
                        <Button
                            type="button"
                            role="radio"
                            aria-checked={dir === 'in'}
                            variant={dir === 'in' ? 'default' : 'outline'}
                            className="h-11"
                            onClick={() => {
                                setDir('in');
                                setAccount('');
                            }}
                        >
                            {t('Masuk')}
                        </Button>
                    </div>
                    <Field label={t('Nominal')}>
                        <MoneyInput value={amount} onChange={setAmount} />
                    </Field>
                    <Field label={t('Alasan')}>
                        <Input
                            className="h-11"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={t('mis. beli es batu')}
                        />
                    </Field>
                    <Field
                        label={
                            dir === 'out' ? t('Akun beban') : t('Akun lawan')
                        }
                    >
                        <Select
                            value={account}
                            onChange={(e) => setAccount(Number(e.target.value))}
                        >
                            <option value="">{t('— pilih —')}</option>
                            {list.map((a) => (
                                <option key={a.id} value={a.id}>
                                    {a.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button
                        className="h-12"
                        disabled={!amount || !reason.trim() || !account}
                        onClick={() =>
                            router.post(
                                '/shift/movements',
                                {
                                    direction: dir,
                                    amount,
                                    reason,
                                    account_id: account,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setAmount('');
                                        setReason('');
                                    },
                                },
                            )
                        }
                    >
                        {t('Catat')}
                    </Button>
                </>
            )}
            {movements.length > 0 && (
                <ul className="text-sm">
                    {movements.map((m, i) => (
                        <li
                            key={i}
                            className="flex justify-between border-t py-1"
                        >
                            <span>
                                {m.direction === 'in' ? '＋' : '－'} {m.reason}
                                {m.by && (
                                    <span className="text-muted-foreground">
                                        {' '}
                                        · {m.by}
                                    </span>
                                )}
                            </span>
                            <span className="tabular-nums">
                                {rupiah(m.amount)}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function CloseForm({
    shift,
    accounts,
    shiftId,
}: {
    shift: ShiftData;
    accounts: Acc[];
    shiftId?: number;
}) {
    const expected = shift.summary.expected;
    const [counted, setCounted] = useState<number | ''>('');
    const [left, setLeft] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());

    const c = typeof counted === 'number' ? counted : 0;
    const diff = c - expected;
    const leftN = typeof left === 'number' ? left : 0;
    const bad = leftN > c;
    const nonCash = shift.by_method.filter((m) => m.type !== 'cash');

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <h2 className="font-semibold">{t('Tutup shift')}</h2>

            <dl className="grid gap-1 rounded-lg bg-muted p-3 text-sm">
                <div className="flex justify-between">
                    <dt>{t('Modal awal')}</dt>
                    <dd className="tabular-nums">
                        {rupiah(shift.summary.opening)}
                    </dd>
                </div>
                <div className="flex justify-between">
                    <dt>＋ {t('Penjualan tunai')}</dt>
                    <dd className="tabular-nums">
                        {rupiah(shift.summary.cash_sales)}
                    </dd>
                </div>
                <div className="flex justify-between">
                    <dt>＋ {t('Kas masuk')}</dt>
                    <dd className="tabular-nums">
                        {rupiah(shift.summary.cash_in)}
                    </dd>
                </div>
                <div className="flex justify-between">
                    <dt>－ {t('Kas keluar')}</dt>
                    <dd className="tabular-nums">
                        {rupiah(shift.summary.cash_out)}
                    </dd>
                </div>
                <div className="flex justify-between">
                    <dt>－ {t('Refund tunai')}</dt>
                    <dd className="tabular-nums">
                        {rupiah(shift.summary.cash_refunds)}
                    </dd>
                </div>
                <div className="flex justify-between border-t pt-1 font-semibold">
                    <dt>{t('Kas seharusnya di laci')}</dt>
                    <dd className="tabular-nums">{rupiah(expected)}</dd>
                </div>
                {nonCash.map((m) => (
                    <div
                        key={m.name}
                        className="flex justify-between text-xs text-muted-foreground"
                    >
                        <dt>
                            {m.name} ({t('masuk rekening, bukan laci')})
                        </dt>
                        <dd className="tabular-nums">{rupiah(m.total)}</dd>
                    </div>
                ))}
            </dl>

            <Field
                label={t('Uang tunai di laci (hasil hitung kasir)')}
                hint={t(
                    'Isi total nominal uang tunai yang benar-benar ada, tidak perlu per pecahan.',
                )}
            >
                <MoneyInput value={counted} onChange={setCounted} autoFocus />
            </Field>
            <div
                className={`flex justify-between rounded-lg p-3 text-sm font-semibold ${counted === '' ? 'bg-muted' : diff === 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                aria-live="polite"
            >
                <span>{t('Selisih')}</span>
                <span className="tabular-nums">
                    {counted === ''
                        ? '—'
                        : `${diff > 0 ? '+' : ''}${rupiah(diff)}`}
                </span>
            </div>
            {counted !== '' && diff !== 0 && (
                <Field label={t('Alasan selisih (wajib)')}>
                    <Input
                        className="h-11"
                        value={reason}
                        onChange={(e) => setReason(e.target.value)}
                    />
                </Field>
            )}
            <Field label={t('Uang ditinggal di laci (modal shift berikutnya)')}>
                <MoneyInput value={left} onChange={setLeft} />
            </Field>
            <p className="text-sm">
                {t('Disetor')}:{' '}
                <strong className="tabular-nums">
                    {rupiah(Math.max(0, c - leftN))}
                </strong>
            </p>
            {c - leftN > 0 && (
                <Field label={t('Setor ke')}>
                    <DepositTo accounts={accounts} />
                </Field>
            )}
            {bad && (
                <p className="text-sm text-destructive" role="alert">
                    {t('Uang yang ditinggal melebihi kas fisik.')}
                </p>
            )}
            <Button
                variant="destructive"
                className="h-12"
                disabled={
                    busy ||
                    bad ||
                    counted === '' ||
                    left === '' ||
                    (diff !== 0 && !reason.trim())
                }
                onClick={() => {
                    if (
                        !window.confirm(
                            t(
                                'Tutup shift sekarang? Kamu akan keluar dari sesi.',
                            ),
                        )
                    ) {
                        return;
                    }

                    setBusy(true);
                    const target = (
                        document.getElementById(
                            'deposit-to',
                        ) as HTMLSelectElement | null
                    )?.value;
                    router.post(
                        '/shift/close',
                        {
                            shift_id: shiftId ?? null,
                            counted_cash: counted,
                            cash_left: left,
                            deposit_account_id: target ? Number(target) : null,
                            difference_reason: reason || null,
                            close_key: key.current,
                        },
                        {
                            onError: () =>
                                toast.error(t('Gagal menutup shift.')),
                            onFinish: () => setBusy(false),
                        },
                    );
                }}
            >
                {t('Tutup shift')}
            </Button>
        </section>
    );
}

function DepositTo({ accounts }: { accounts: Acc[] }) {
    return (
        <Select id="deposit-to" defaultValue="">
            <option value="">{t('Owner (tunai)')}</option>
            {accounts.map((a) => (
                <option key={a.id} value={a.id}>
                    {t('Bank')}: {a.name}
                </option>
            ))}
        </Select>
    );
}
