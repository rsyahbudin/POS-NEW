import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    Field,
    MoneyInput,
    PageHeader,
    ResponsiveTable,
    Select,
    Stat,
    Tabs,
} from '@/components/pos/ui';
import { ImagePicker } from '@/components/pos/image-picker';
import { InfoTip } from '@/components/pos/info-tip';
import { AccountMenu } from '@/components/pos/account-menu';
import { Modal } from '@/components/pos/modal';
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
        receipt: string | null;
    }[];
};
type Acc = { id: number; code: string; name: string };

type Props = {
    shift: ShiftData | null;
    suggestedOpening: number;
    accounts: { expense: Acc[]; other: Acc[]; bank: Acc[] };
    categories: { id: number; name: string }[];
    can_free_form: boolean;
    recent: ShiftData[];
    transactions: {
        id: number;
        number: string;
        where: string;
        at: string | null;
        status: string;
        total: number;
        paid_by: string;
    }[];
    unsettled: {
        id: number;
        number: string;
        where: string;
        total: number;
        paid: number;
        status: string;
    }[];
    can: { operate: boolean; reopen_id: number | null };
};

export default function ShiftPage({
    shift,
    suggestedOpening,
    accounts,
    categories,
    can_free_form,
    recent,
    unsettled,
    transactions,
    can,
}: Props) {
    const errors = usePage().props.errors as Record<string, string> | undefined;
    const [tab, setTab] = useState<'summary' | 'cash' | 'close'>('summary');

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
                        categories={categories}
                        canFreeForm={can_free_form}
                        operate={can.operate}
                        unsettled={unsettled}
                        tab={tab}
                        onTab={setTab}
                    />
                ) : can.operate ? (
                    <>
                        {can.reopen_id !== null && (
                            <ReopenCard id={can.reopen_id} />
                        )}
                        <OpenForm suggested={suggestedOpening} />
                    </>
                ) : (
                    <p className="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground">
                        {t(
                            'Belum ada shift terbuka. Minta kasir atau manager membukanya.',
                        )}
                    </p>
                )}
                {shift && tab === 'summary' && (
                    <p className="text-xs text-muted-foreground">
                        {t(
                            'Satu shift dipakai bersama oleh semua staf toko. Tiap pembayaran dan kas tercatat atas nama orang yang melakukannya.',
                        )}
                    </p>
                )}

                {shift && tab === 'summary' && (
                    <section
                        aria-label={t('Transaksi shift ini')}
                        className="grid gap-2"
                    >
                        <h2 className="font-semibold">
                            {t('Transaksi shift ini')}
                        </h2>
                        <ResponsiveTable
                            headers={[
                                t('Order'),
                                t('Waktu'),
                                t('Status'),
                                t('Diterima oleh'),
                                t('Total'),
                            ]}
                            numeric={[4]}
                            empty={t('Belum ada transaksi di shift ini.')}
                            rows={transactions.map((o) => [
                                `${o.where} · ${o.number}`,
                                o.at ? timeId(o.at) : '-',
                                o.status,
                                o.paid_by || '-',
                                rupiah(o.total),
                            ])}
                        />
                    </section>
                )}

                {recent.length > 0 && (!shift || tab === 'summary') && (
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
                                                    ? 'font-semibold text-red-700 dark:text-red-400'
                                                    : 'text-emerald-700 dark:text-emerald-400'
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

/** Undo a close pressed by mistake: the last closed shift opens again, its cash variance and deposit entries are reversed. */
function ReopenCard({ id }: { id: number }) {
    const [asking, setAsking] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);

    return (
        <section className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200">
            <p className="min-w-0 flex-1">
                <strong>{t('Salah tutup shift?')}</strong>{' '}
                {t(
                    'Shift terakhir bisa dibuka kembali, penutupan dan setorannya dibatalkan.',
                )}
            </p>
            <Button
                variant="outline"
                className="h-11 bg-white dark:bg-card"
                onClick={() => setAsking(true)}
            >
                {t('Buka kembali shift')}
            </Button>
            <Modal
                open={asking}
                onClose={() => setAsking(false)}
                title={t('Buka kembali shift terakhir?')}
                description={t(
                    'Hasil hitung kas, selisih, dan setoran saat menutup akan dibatalkan. Shift terbuka lagi untuk semua staf, dan tutup shift perlu diisi ulang.',
                )}
            >
                <Field label={t('Alasan (opsional)')}>
                    <Input
                        className="h-11"
                        value={reason}
                        maxLength={200}
                        onChange={(e) => setReason(e.target.value)}
                    />
                </Field>
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        className="h-12"
                        onClick={() => setAsking(false)}
                    >
                        {t('Batal')}
                    </Button>
                    <Button
                        className="h-12"
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            router.post(
                                `/shift/${id}/reopen`,
                                { reason: reason || null },
                                {
                                    onFinish: () => {
                                        setBusy(false);
                                        setAsking(false);
                                    },
                                },
                            );
                        }}
                    >
                        {t('Ya, buka kembali')}
                    </Button>
                </div>
            </Modal>
        </section>
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
    categories,
    canFreeForm,
    operate,
    unsettled,
    tab,
    onTab,
}: {
    shift: ShiftData;
    accounts: Props['accounts'];
    categories: Props['categories'];
    canFreeForm: boolean;
    operate: boolean;
    unsettled: Props['unsettled'];
    tab: 'summary' | 'cash' | 'close';
    onTab: (t: 'summary' | 'cash' | 'close') => void;
}) {
    const s = shift.summary;

    return (
        <>
            <Tabs
                value={tab}
                onChange={onTab}
                tabs={[
                    { id: 'summary', label: t('Ringkasan') },
                    { id: 'cash', label: t('Uang laci') },
                    ...(operate
                        ? [{ id: 'close' as const, label: t('Tutup shift') }]
                        : []),
                ]}
            />
            {tab === 'summary' && (
                <>
                    <section className="grid gap-1 rounded-2xl border bg-card p-5">
                        <p className="text-sm text-muted-foreground">
                            {t('Kas seharusnya di laci')}
                        </p>
                        <p className="text-3xl font-bold tabular-nums">
                            {rupiah(s.expected)}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {t('Modal awal')} {rupiah(s.opening)} ＋{' '}
                            {t('tunai')} {rupiah(s.cash_sales)} ＋{' '}
                            {t('kas masuk')} {rupiah(s.cash_in)} －{' '}
                            {t('kas keluar')} {rupiah(s.cash_out)}
                            {s.cash_refunds
                                ? ` － ${t('refund')} ${rupiah(s.cash_refunds)}`
                                : ''}
                        </p>
                    </section>
                    <div className="grid grid-cols-2 gap-2">
                        <Stat
                            label={t('Kas masuk')}
                            value={rupiah(s.cash_in)}
                        />
                        <Stat
                            label={t('Kas keluar')}
                            value={rupiah(s.cash_out)}
                        />
                    </div>
                    <SalesBreakdown shift={shift} />
                </>
            )}
            {tab === 'cash' && (
                <Movement
                    accounts={accounts}
                    categories={categories}
                    canFreeForm={canFreeForm}
                    movements={shift.movements}
                    operate={operate}
                />
            )}
            {tab === 'close' && operate && (
                <CloseForm
                    shift={shift}
                    accounts={accounts.bank}
                    unsettled={unsettled}
                />
            )}
        </>
    );
}

type Mode = 'spend' | 'in' | 'other';

/**
 * Money going in or out of the drawer outside of sales. Staff record what they spent on supplies (a category and a
 * receipt photo) or money they put in; whoever runs the store's shifts can also book other costs against an account.
 */
function Movement({
    accounts,
    categories,
    canFreeForm,
    movements,
    operate,
}: {
    accounts: Props['accounts'];
    categories: Props['categories'];
    canFreeForm: boolean;
    movements: ShiftData['movements'];
    operate: boolean;
}) {
    const [mode, setMode] = useState<Mode>('spend');
    const [amount, setAmount] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [account, setAccount] = useState<number | ''>('');
    const [category, setCategory] = useState<number | ''>('');
    const [receipt, setReceipt] = useState<File | null>(null);
    const spend = mode === 'spend';
    const ready =
        !!amount && (spend ? !!category : !!reason.trim() && !!account);
    const modes: { id: Mode; label: string }[] = [
        { id: 'spend', label: t('Belanja') },
        { id: 'in', label: t('Tambah uang') },
        ...(canFreeForm
            ? [{ id: 'other' as const, label: t('Biaya lain') }]
            : []),
    ];

    const reset = () => {
        setAmount('');
        setReason('');
        setCategory('');
        setReceipt(null);
    };
    const pick = (m: Mode) => {
        setMode(m);
        setAccount('');
        setCategory('');
    };

    return (
        <section className="grid gap-4 rounded-2xl border bg-card p-4">
            <div className="flex items-center gap-1.5">
                <h2 className="font-semibold">{t('Uang laci')}</h2>
                <InfoTip label={t('Info uang laci')}>
                    {t(
                        'Catat uang yang keluar dari laci untuk belanja kecil, atau uang yang kamu tambahkan ke laci. Penjualan tidak dicatat di sini. Foto nota membantu manager mencocokkan.',
                    )}
                </InfoTip>
            </div>

            {operate && (
                <>
                    <div
                        role="radiogroup"
                        aria-label={t('Jenis catatan')}
                        className={`grid gap-2 ${modes.length === 3 ? 'grid-cols-3' : 'grid-cols-2'}`}
                    >
                        {modes.map((m) => (
                            <Button
                                key={m.id}
                                type="button"
                                role="radio"
                                aria-checked={mode === m.id}
                                variant={mode === m.id ? 'default' : 'outline'}
                                className="h-12"
                                onClick={() => pick(m.id)}
                            >
                                {m.label}
                            </Button>
                        ))}
                    </div>

                    {spend && (
                        <Field label={t('Untuk apa?')}>
                            {categories.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Belum ada kategori belanja. Minta manager mengaturnya di Pengeluaran.',
                                    )}
                                </p>
                            ) : (
                                <div className="flex flex-wrap gap-2">
                                    {categories.map((c) => (
                                        <Button
                                            key={c.id}
                                            type="button"
                                            aria-pressed={category === c.id}
                                            variant={
                                                category === c.id
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            className="h-11 rounded-full"
                                            onClick={() => setCategory(c.id)}
                                        >
                                            {c.name}
                                        </Button>
                                    ))}
                                </div>
                            )}
                        </Field>
                    )}

                    <Field label={t('Nominal')}>
                        <MoneyInput value={amount} onChange={setAmount} />
                    </Field>

                    <Field
                        label={spend ? t('Catatan (opsional)') : t('Alasan')}
                    >
                        <Input
                            className="h-11"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            placeholder={
                                spend
                                    ? t('mis. galon 2 buah')
                                    : mode === 'in'
                                      ? t('mis. tambah uang kembalian')
                                      : t('mis. servis AC')
                            }
                        />
                        {mode === 'in' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                                {[
                                    t('Tambah uang kembalian'),
                                    t('Setor modal'),
                                ].map((r) => (
                                    <Button
                                        key={r}
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        className="h-9 rounded-full"
                                        onClick={() => setReason(r)}
                                    >
                                        {r}
                                    </Button>
                                ))}
                            </div>
                        )}
                    </Field>

                    {!spend && (
                        <Field
                            label={
                                mode === 'other'
                                    ? t('Akun beban')
                                    : t('Akun lawan')
                            }
                        >
                            <Select
                                value={account}
                                onChange={(e) =>
                                    setAccount(Number(e.target.value))
                                }
                            >
                                <option value="">{t('— pilih —')}</option>
                                {(mode === 'other'
                                    ? accounts.expense
                                    : accounts.other
                                ).map((a) => (
                                    <option key={a.id} value={a.id}>
                                        {a.name}
                                    </option>
                                ))}
                            </Select>
                        </Field>
                    )}

                    {spend && (
                        <Field label={t('Foto nota (opsional)')}>
                            <ImagePicker
                                aspect="aspect-[4/3]"
                                previewClass="max-w-40"
                                file={receipt}
                                onChange={setReceipt}
                            />
                        </Field>
                    )}

                    <Button
                        className="h-12"
                        disabled={!ready}
                        onClick={() =>
                            router.post(
                                '/shift/movements',
                                {
                                    direction: mode === 'in' ? 'in' : 'out',
                                    amount,
                                    reason,
                                    ...(spend
                                        ? {
                                              category_id: category,
                                              receipt: receipt ?? undefined,
                                          }
                                        : { account_id: account }),
                                },
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: reset,
                                },
                            )
                        }
                    >
                        {mode === 'in'
                            ? t('Catat uang masuk')
                            : t('Catat pengeluaran')}
                    </Button>
                </>
            )}

            <div className="grid gap-1">
                <h3 className="text-sm font-medium text-muted-foreground">
                    {t('Tercatat di shift ini')}
                </h3>
                {movements.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('Belum ada uang masuk atau keluar.')}
                    </p>
                ) : (
                    <ul className="text-sm">
                        {movements.map((m, i) => (
                            <li
                                key={i}
                                className="flex items-start justify-between gap-3 border-t py-2"
                            >
                                <span className="min-w-0">
                                    {m.reason}
                                    <span className="block text-xs text-muted-foreground">
                                        {m.by}
                                        {m.receipt && (
                                            <a
                                                className="ml-2 underline"
                                                href={m.receipt}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {t('nota')}
                                            </a>
                                        )}
                                    </span>
                                </span>
                                <span
                                    className={`shrink-0 font-medium tabular-nums ${m.direction === 'in' ? 'text-emerald-700 dark:text-emerald-400' : ''}`}
                                >
                                    {m.direction === 'in' ? '＋' : '－'}
                                    {rupiah(m.amount)}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}

function CloseForm({
    shift,
    accounts,
    shiftId,
    unsettled = [],
}: {
    shift: ShiftData;
    accounts: Acc[];
    shiftId?: number;
    unsettled?: Props['unsettled'];
}) {
    const blocked = unsettled.length > 0;
    const expected = shift.summary.expected;
    const [counted, setCounted] = useState<number | ''>('');
    const [left, setLeft] = useState<number | ''>('');
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [confirming, setConfirming] = useState(false);
    const key = useRef(uuid());

    const c = typeof counted === 'number' ? counted : 0;
    const diff = c - expected;
    const leftN = typeof left === 'number' ? left : 0;
    const bad = leftN > c;
    const nonCash = shift.by_method.filter((m) => m.type !== 'cash');

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <h2 className="font-semibold">{t('Tutup shift')}</h2>

            {blocked && (
                <div
                    role="alert"
                    className="grid gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200"
                >
                    <p className="font-semibold">
                        {t('Belum bisa menutup shift')}: {unsettled.length}{' '}
                        {t('pesanan belum dibayar')}
                    </p>
                    <ul className="grid gap-1">
                        {unsettled.map((o) => (
                            <li
                                key={o.id}
                                className="flex justify-between gap-3"
                            >
                                <span>
                                    {o.where} · {o.number}
                                    {o.paid > 0 &&
                                        ` (${t('dibayar sebagian')})`}
                                </span>
                                <span className="tabular-nums">
                                    {rupiah(o.total - o.paid)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <p>
                        {t(
                            'Lunasi atau batalkan di Kasir dulu. Pesanan yang sudah dibayar sebagian harus dilunasi.',
                        )}
                    </p>
                    <Button
                        asChild
                        variant="outline"
                        className="h-11 bg-background"
                    >
                        <Link href="/pos">{t('Buka Kasir')}</Link>
                    </Button>
                </div>
            )}

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
                className={`flex justify-between rounded-lg p-3 text-sm font-semibold ${counted === '' ? 'bg-muted' : diff === 0 ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200' : 'bg-red-50 text-red-800 dark:bg-red-950/50 dark:text-red-200'}`}
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
                    blocked ||
                    busy ||
                    bad ||
                    counted === '' ||
                    left === '' ||
                    (diff !== 0 && !reason.trim())
                }
                onClick={() => setConfirming(true)}
            >
                {t('Tutup shift')}
            </Button>
            <Modal
                open={confirming}
                onClose={() => setConfirming(false)}
                title={t('Tutup shift sekarang?')}
                description={t(
                    'Setelah ditutup, transaksi baru perlu membuka shift baru, dan kamu keluar dari sesi.',
                )}
            >
                <dl className="grid gap-1 rounded-lg bg-muted p-3 text-sm">
                    <div className="flex justify-between">
                        <dt>{t('Kas seharusnya')}</dt>
                        <dd className="tabular-nums">{rupiah(expected)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>{t('Kas dihitung')}</dt>
                        <dd className="tabular-nums">{rupiah(c)}</dd>
                    </div>
                    <div
                        className={`flex justify-between font-semibold ${diff === 0 ? 'text-emerald-800 dark:text-emerald-200' : 'text-red-700 dark:text-red-400'}`}
                    >
                        <dt>{t('Selisih')}</dt>
                        <dd className="tabular-nums">
                            {diff > 0 ? '+' : ''}
                            {rupiah(diff)}
                        </dd>
                    </div>
                    <div className="flex justify-between border-t pt-1">
                        <dt>{t('Ditinggal di laci')}</dt>
                        <dd className="tabular-nums">{rupiah(leftN)}</dd>
                    </div>
                    <div className="flex justify-between">
                        <dt>{t('Disetor')}</dt>
                        <dd className="tabular-nums">
                            {rupiah(Math.max(0, c - leftN))}
                        </dd>
                    </div>
                </dl>
                <div className="grid grid-cols-2 gap-2">
                    <Button
                        variant="outline"
                        className="h-12"
                        onClick={() => setConfirming(false)}
                    >
                        {t('Kembali')}
                    </Button>
                    <Button
                        variant="destructive"
                        className="h-12"
                        disabled={busy}
                        onClick={() => {
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
                                    deposit_account_id: target
                                        ? Number(target)
                                        : null,
                                    difference_reason: reason || null,
                                    close_key: key.current,
                                },
                                {
                                    onError: () =>
                                        toast.error(t('Gagal menutup shift.')),
                                    onFinish: () => {
                                        setBusy(false);
                                        setConfirming(false);
                                    },
                                },
                            );
                        }}
                    >
                        {t('Ya, tutup shift')}
                    </Button>
                </div>
            </Modal>
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
