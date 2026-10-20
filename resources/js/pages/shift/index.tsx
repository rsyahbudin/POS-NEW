import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    Field,
    MoneyInput,
    PageHeader,
    Select,
    Stat,
} from '@/components/pos/ui';
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
    by_method: { name: string; total: number; count: number }[];
    orders: number;
    voids: number;
    discounts: number;
    comps: number;
    movements: {
        direction: 'in' | 'out';
        amount: number;
        reason: string;
        created_at: string;
    }[];
};
type Acc = { id: number; code: string; name: string };

type Props = {
    shift: ShiftData | null;
    suggestedOpening: number;
    accounts: { expense: Acc[]; other: Acc[]; bank: Acc[] };
    recent: ShiftData[];
    can: { any: boolean };
};

const DENOMS = [100000, 50000, 20000, 10000, 5000, 2000, 1000, 500, 200, 100];

export default function ShiftPage({
    shift,
    suggestedOpening,
    accounts,
    recent,
}: Props) {
    const errors = usePage().props.errors as Record<string, string> | undefined;

    return (
        <>
            <Head title="Shift" />
            <div className="mx-auto grid max-w-3xl gap-4 p-4">
                <PageHeader
                    title="Shift kasir"
                    subtitle={
                        shift
                            ? `${t('Dibuka')} ${dateId(shift.opened_at)} ${timeId(shift.opened_at)}`
                            : t('Belum ada shift terbuka')
                    }
                    actions={
                        <Button asChild variant="outline" className="h-11">
                            <Link href="/pos">← {t('Kasir')}</Link>
                        </Button>
                    }
                />
                {errors && Object.values(errors).length > 0 && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}
                {shift ? (
                    <Open shift={shift} accounts={accounts} />
                ) : (
                    <OpenForm suggested={suggestedOpening} />
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

function Open({
    shift,
    accounts,
}: {
    shift: ShiftData;
    accounts: Props['accounts'];
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
                    label={t('Kas seharusnya')}
                    value={rupiah(s.expected)}
                    hint={
                        s.cash_refunds
                            ? `${t('refund tunai')} ${rupiah(s.cash_refunds)}`
                            : undefined
                    }
                />
            </div>
            <section className="rounded-xl border bg-card p-3 text-sm">
                <h2 className="mb-1 font-semibold">{t('Rekap sementara')}</h2>
                <p>
                    {shift.orders} order · {shift.voids} void · {t('diskon')}{' '}
                    {rupiah(shift.discounts)} · {shift.comps} {t('komplimen')}
                </p>
                {shift.by_method.map((m) => (
                    <p key={m.name} className="flex justify-between">
                        <span>
                            {m.name} ({m.count})
                        </span>
                        <span className="tabular-nums">{rupiah(m.total)}</span>
                    </p>
                ))}
            </section>
            <Movement accounts={accounts} movements={shift.movements} />
            <CloseForm expected={s.expected} accounts={accounts.bank} />
        </>
    );
}

function Movement({
    accounts,
    movements,
}: {
    accounts: Props['accounts'];
    movements: ShiftData['movements'];
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
            <Field label={dir === 'out' ? t('Akun beban') : t('Akun lawan')}>
                <Select
                    value={account}
                    onChange={(e) => setAccount(Number(e.target.value))}
                >
                    <option value="">{t('— pilih —')}</option>
                    {list.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.code} {a.name}
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
                        { direction: dir, amount, reason, account_id: account },
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
            {movements.length > 0 && (
                <ul className="text-sm">
                    {movements.map((m, i) => (
                        <li
                            key={i}
                            className="flex justify-between border-t py-1"
                        >
                            <span>
                                {m.direction === 'in' ? '＋' : '－'} {m.reason}
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
    expected,
    accounts,
}: {
    expected: number;
    accounts: Acc[];
}) {
    const [counts, setCounts] = useState<Record<number, number>>({});
    const [left, setLeft] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const key = useRef(uuid());

    const counted = useMemo(
        () =>
            Object.entries(counts).reduce((s, [d, c]) => s + Number(d) * c, 0),
        [counts],
    );
    const diff = counted - expected;
    const leftN = typeof left === 'number' ? left : 0;
    const bad = leftN > counted;

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <h2 className="font-semibold">{t('Tutup shift')}</h2>
            <p className="text-xs text-muted-foreground">
                {t('Hitung uang fisik per pecahan.')}
            </p>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                {DENOMS.map((d) => (
                    <label key={d} className="grid gap-1 text-xs">
                        {rupiah(d)}
                        <Input
                            className="h-11 text-center"
                            inputMode="numeric"
                            value={counts[d] ?? ''}
                            placeholder="0"
                            onChange={(e) =>
                                setCounts((c) => ({
                                    ...c,
                                    [d]: Math.max(
                                        0,
                                        Number(
                                            e.target.value.replace(/\D/g, ''),
                                        ) || 0,
                                    ),
                                }))
                            }
                            aria-label={`${t('Jumlah lembar')} ${rupiah(d)}`}
                        />
                    </label>
                ))}
            </div>
            <dl
                className="grid gap-1 rounded-lg bg-muted p-3 text-sm"
                aria-live="polite"
            >
                <div className="flex justify-between">
                    <dt>{t('Kas seharusnya')}</dt>
                    <dd className="tabular-nums">{rupiah(expected)}</dd>
                </div>
                <div className="flex justify-between">
                    <dt>{t('Kas fisik')}</dt>
                    <dd className="tabular-nums">{rupiah(counted)}</dd>
                </div>
                <div
                    className={`flex justify-between font-semibold ${diff === 0 ? 'text-emerald-700' : 'text-red-700'}`}
                >
                    <dt>{t('Selisih')}</dt>
                    <dd className="tabular-nums">{rupiah(diff)}</dd>
                </div>
            </dl>
            {diff !== 0 && (
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
                    {rupiah(Math.max(0, counted - leftN))}
                </strong>
            </p>
            {counted - leftN > 0 && (
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
                    busy || bad || left === '' || (diff !== 0 && !reason.trim())
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
                            denominations: Object.fromEntries(
                                DENOMS.map((d) => [d, counts[d] ?? 0]),
                            ),
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
