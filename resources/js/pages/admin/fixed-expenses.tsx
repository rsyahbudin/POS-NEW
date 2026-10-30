import { Head, router, usePage } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Plus } from 'lucide-react';
import { useState } from 'react';
import { Modal } from '@/components/pos/modal';
import {
    Field,
    MoneyInput,
    PageHeader,
    Select,
    Stat,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah } from '@/lib/format';

type Bill = {
    id: number;
    name: string;
    category: string;
    due_date: string;
    amount: number;
    status: 'unpaid' | 'paid' | 'skipped';
    paid_on: string | null;
    source_account_id: number | null;
    receipt: string | null;
};
type Cost = {
    id: number;
    name: string;
    category_id: number;
    category: string;
    amount: number;
    due_day: number;
    source_account_id: number | null;
    note: string | null;
    is_active: boolean;
};
type CostForm = {
    id?: number;
    name: string;
    amount: number | '';
    due_day: number;
    category_id: number | '';
    source_account_id: number | '';
    note: string;
    is_active?: boolean;
};
type Props = {
    month: string;
    today: string;
    bills: Bill[];
    costs: Cost[];
    categories: { id: number; name: string }[];
    sources: { id: number; code: string; name: string }[];
};

const shiftMonth = (m: string, by: number) => {
    const d = new Date(`${m}-01T00:00:00`);
    d.setMonth(d.getMonth() + by);

    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
};
const monthName = (m: string) =>
    new Date(`${m}-01T00:00:00`).toLocaleDateString('id-ID', {
        month: 'long',
        year: 'numeric',
    });

function Status({ bill, today }: { bill: Bill; today: string }) {
    const base = 'rounded-full border px-2 py-0.5 text-xs font-medium';
    if (bill.status === 'paid') {
        return (
            <span
                className={`${base} border-emerald-600/40 text-emerald-700 dark:text-emerald-400`}
            >
                Lunas · {dateId(bill.paid_on)}
            </span>
        );
    }
    if (bill.status === 'skipped') {
        return (
            <span className={`${base} text-muted-foreground`}>Dilewati</span>
        );
    }
    const days = Math.round(
        (new Date(bill.due_date).getTime() - new Date(today).getTime()) /
            86400000,
    );
    if (days < 0) {
        return (
            <span
                className={`${base} border-red-600/40 text-red-700 dark:text-red-400`}
            >
                Terlambat {-days} hari
            </span>
        );
    }
    if (days <= 3) {
        return (
            <span
                className={`${base} border-amber-600/40 text-amber-700 dark:text-amber-400`}
            >
                {days === 0 ? 'Jatuh tempo hari ini' : `${days} hari lagi`}
            </span>
        );
    }

    return (
        <span className={`${base} text-muted-foreground`}>Belum dibayar</span>
    );
}

export default function FixedExpenses({
    month,
    today,
    bills,
    costs,
    categories,
    sources,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [cost, setCost] = useState<CostForm | null>(null);
    const [pay, setPay] = useState<{
        bill: Bill;
        date: string;
        amount: number | '';
        source: number | '';
        note: string;
        receipt: File | null;
    } | null>(null);

    const live = bills.filter((b) => b.status !== 'skipped');
    const total = live.reduce((s, b) => s + b.amount, 0);
    const paid = bills
        .filter((b) => b.status === 'paid')
        .reduce((s, b) => s + b.amount, 0);
    const go = (m: string) =>
        router.get(
            '/admin/fixed-expenses',
            { month: m },
            { preserveState: true },
        );
    const firstError = Object.values(errors ?? {})[0];

    return (
        <>
            <Head title="Beban Tetap" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Beban tetap bulanan"
                    subtitle="Gaji, sewa, listrik, internet, dan tagihan rutin lain. Satu tagihan dibuat tiap bulan; bayar atau lewati."
                    actions={
                        <Button
                            className="h-11"
                            disabled={categories.length === 0}
                            onClick={() =>
                                setCost({
                                    name: '',
                                    amount: '',
                                    due_day: 1,
                                    category_id: categories[0]?.id ?? '',
                                    source_account_id: sources[0]?.id ?? '',
                                    note: '',
                                })
                            }
                        >
                            <Plus /> Beban tetap
                        </Button>
                    }
                />
                {firstError && (
                    <p role="alert" className="text-sm text-destructive">
                        {firstError}
                    </p>
                )}

                <div className="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Bulan sebelumnya"
                        onClick={() => go(shiftMonth(month, -1))}
                    >
                        <ChevronLeft />
                    </Button>
                    <h2 className="min-w-40 text-center text-lg font-semibold capitalize">
                        {monthName(month)}
                    </h2>
                    <Button
                        variant="outline"
                        size="icon"
                        className="size-11"
                        aria-label="Bulan berikutnya"
                        onClick={() => go(shiftMonth(month, 1))}
                    >
                        <ChevronRight />
                    </Button>
                </div>

                <div className="grid grid-cols-3 gap-3">
                    <Stat label="Total tagihan" value={rupiah(total)} />
                    <Stat label="Sudah dibayar" value={rupiah(paid)} />
                    <Stat label="Belum dibayar" value={rupiah(total - paid)} />
                </div>

                <section className="grid gap-2">
                    {bills.length === 0 && (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {costs.length === 0
                                ? 'Belum ada beban tetap. Klik "Beban tetap" untuk menambah gaji, sewa, listrik, atau internet.'
                                : 'Tidak ada tagihan di bulan ini.'}
                        </p>
                    )}
                    {bills.map((b) => (
                        <div
                            key={b.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                        >
                            <div className="grid gap-1">
                                <p className="font-medium">
                                    {b.name}{' '}
                                    <span className="text-xs text-muted-foreground">
                                        · {b.category}
                                    </span>
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Jatuh tempo {dateId(b.due_date)}
                                </p>
                                <Status bill={b} today={today} />
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="text-lg font-semibold tabular-nums">
                                    {rupiah(b.amount)}
                                </span>
                                {b.status === 'unpaid' && (
                                    <>
                                        <Button
                                            className="h-11"
                                            onClick={() =>
                                                setPay({
                                                    bill: b,
                                                    date: today,
                                                    amount: b.amount,
                                                    source:
                                                        b.source_account_id ??
                                                        sources[0]?.id ??
                                                        '',
                                                    note: '',
                                                    receipt: null,
                                                })
                                            }
                                        >
                                            Bayar
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            className="h-11"
                                            onClick={() =>
                                                confirm(
                                                    `Lewati ${b.name} bulan ini?`,
                                                ) &&
                                                router.post(
                                                    `/admin/fixed-expense-bills/${b.id}/skip`,
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Lewati
                                        </Button>
                                    </>
                                )}
                                {b.receipt && (
                                    <a
                                        className="text-sm underline"
                                        href={b.receipt}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Nota
                                    </a>
                                )}
                            </div>
                        </div>
                    ))}
                </section>

                {costs.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="font-semibold">Daftar beban tetap</h2>
                        {costs.map((c) => (
                            <div
                                key={c.id}
                                className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                            >
                                <p className="font-medium">
                                    {c.name}{' '}
                                    <span className="text-xs text-muted-foreground">
                                        · {c.category} · tiap tanggal{' '}
                                        {c.due_day} · {rupiah(c.amount)}
                                        {!c.is_active && ' · nonaktif'}
                                    </span>
                                </p>
                                <Button
                                    variant="ghost"
                                    className="h-11"
                                    onClick={() =>
                                        setCost({
                                            ...c,
                                            amount: c.amount,
                                            category_id: c.category_id,
                                            source_account_id:
                                                c.source_account_id ?? '',
                                            note: c.note ?? '',
                                        })
                                    }
                                >
                                    Ubah
                                </Button>
                            </div>
                        ))}
                    </section>
                )}
            </div>

            {cost && (
                <Modal
                    open
                    onClose={() => setCost(null)}
                    title={cost.id ? 'Ubah beban tetap' : 'Beban tetap baru'}
                >
                    <Field label="Nama">
                        <Input
                            className="h-11"
                            value={cost.name}
                            placeholder="mis. Sewa ruko"
                            onChange={(e) =>
                                setCost({ ...cost, name: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Kategori beban">
                        <Select
                            value={cost.category_id}
                            onChange={(e) =>
                                setCost({
                                    ...cost,
                                    category_id: Number(e.target.value),
                                })
                            }
                        >
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Nominal per bulan">
                            <MoneyInput
                                value={cost.amount}
                                onChange={(v) =>
                                    setCost({ ...cost, amount: v })
                                }
                            />
                        </Field>
                        <Field
                            label="Jatuh tempo tanggal"
                            hint="Bulan pendek memakai tanggal terakhir."
                        >
                            <Input
                                className="h-11"
                                type="number"
                                min={1}
                                max={31}
                                value={cost.due_day}
                                onChange={(e) =>
                                    setCost({
                                        ...cost,
                                        due_day: Number(e.target.value),
                                    })
                                }
                            />
                        </Field>
                    </div>
                    <Field label="Dibayar dari">
                        <Select
                            value={cost.source_account_id}
                            onChange={(e) =>
                                setCost({
                                    ...cost,
                                    source_account_id: Number(e.target.value),
                                })
                            }
                        >
                            {sources.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Catatan (opsional)">
                        <Input
                            className="h-11"
                            value={cost.note}
                            onChange={(e) =>
                                setCost({ ...cost, note: e.target.value })
                            }
                        />
                    </Field>
                    {cost.id && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={cost.is_active ?? true}
                                onChange={(e) =>
                                    setCost({
                                        ...cost,
                                        is_active: e.target.checked,
                                    })
                                }
                            />
                            Aktif (tagihan bulan berikutnya tetap dibuat)
                        </label>
                    )}
                    <Button
                        className="h-12"
                        disabled={
                            !cost.name.trim() ||
                            cost.amount === '' ||
                            !cost.category_id
                        }
                        onClick={() => {
                            const body = {
                                name: cost.name,
                                expense_category_id: cost.category_id,
                                amount: cost.amount,
                                due_day: cost.due_day,
                                source_account_id:
                                    cost.source_account_id || null,
                                note: cost.note || null,
                                is_active: cost.is_active ?? true,
                            };
                            const o = {
                                preserveScroll: true,
                                onSuccess: () => setCost(null),
                            };
                            if (cost.id) {
                                router.put(
                                    `/admin/fixed-expenses/${cost.id}`,
                                    body,
                                    o,
                                );
                            } else {
                                router.post('/admin/fixed-expenses', body, o);
                            }
                        }}
                    >
                        Simpan
                    </Button>
                </Modal>
            )}

            {pay && (
                <Modal
                    open
                    onClose={() => setPay(null)}
                    title={`Bayar ${pay.bill.name}`}
                >
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Tanggal bayar">
                            <Input
                                className="h-11"
                                type="date"
                                value={pay.date}
                                onChange={(e) =>
                                    setPay({ ...pay, date: e.target.value })
                                }
                            />
                        </Field>
                        <Field
                            label="Nominal"
                            hint="Ubah bila tagihan bulan ini berbeda (mis. listrik)."
                        >
                            <MoneyInput
                                value={pay.amount}
                                onChange={(v) => setPay({ ...pay, amount: v })}
                            />
                        </Field>
                    </div>
                    <Field label="Dibayar dari">
                        <Select
                            value={pay.source}
                            onChange={(e) =>
                                setPay({
                                    ...pay,
                                    source: Number(e.target.value),
                                })
                            }
                        >
                            {sources.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Catatan (opsional)">
                        <Input
                            className="h-11"
                            value={pay.note}
                            onChange={(e) =>
                                setPay({ ...pay, note: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Foto bukti / nota (opsional)">
                        <input
                            type="file"
                            accept="image/*"
                            onChange={(e) =>
                                setPay({
                                    ...pay,
                                    receipt: e.target.files?.[0] ?? null,
                                })
                            }
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={!pay.amount || !pay.source}
                        onClick={() =>
                            router.post(
                                `/admin/fixed-expense-bills/${pay.bill.id}/pay`,
                                {
                                    date: pay.date,
                                    amount: pay.amount,
                                    source_account_id: pay.source,
                                    note: pay.note || undefined,
                                    receipt: pay.receipt ?? undefined,
                                },
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () => setPay(null),
                                },
                            )
                        }
                    >
                        Bayar & catat pengeluaran
                    </Button>
                </Modal>
            )}
        </>
    );
}
