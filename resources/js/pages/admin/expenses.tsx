import { Head, router, usePage } from '@inertiajs/react';
import { BarList, ChartCard } from '@/components/pos/charts';
import { useState } from 'react';
import {
    Field,
    MoneyInput,
    PageHeader,
    ResponsiveTable,
    Select,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId, rupiah } from '@/lib/format';
import { SpendingTabs } from '@/components/pos/section-tabs';
import { InfoNote, InfoTip } from '@/components/pos/info-tip';
import { Switch } from '@/components/pos/switch';
import { ImagePicker } from '@/components/pos/image-picker';

type Props = {
    filters: { from: string; to: string };
    expenses: {
        id: number;
        date: string;
        category: string;
        amount: number;
        note: string | null;
        receipt: string | null;
        from_drawer: boolean;
    }[];
    categories: {
        id: number;
        name: string;
        account_id: number;
        allow_drawer: boolean;
    }[];
    expenseAccounts: { id: number; code: string; name: string }[];
    canManageCategories: boolean;
    sources: { id: number; code: string; name: string }[];
    today: string;
};

export default function ExpensesPage({
    filters,
    expenses,
    categories,
    expenseAccounts,
    canManageCategories,
    sources,
    today,
}: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [f, setF] = useState({
        date: today,
        category_id: categories[0]?.id ?? '',
        amount: '' as number | '',
        source_account_id: sources[0]?.id ?? '',
        note: '',
        receipt: null as File | null,
    });
    const total = expenses.reduce((s, e) => s + e.amount, 0);
    const byCategory = Object.entries(
        expenses.reduce<Record<string, number>>(
            (acc, e) => ({
                ...acc,
                [e.category]: (acc[e.category] ?? 0) + e.amount,
            }),
            {},
        ),
    )
        .map(([label, value]) => ({ label, value }))
        .sort((a, b) => b.value - a.value);
    const exportQs = new URLSearchParams(filters).toString();

    return (
        <>
            <Head title="Pengeluaran" />
            <div className="mx-auto grid w-full max-w-[1400px] grid-cols-1 gap-5 p-4 md:p-6">
                <SpendingTabs />
                <PageHeader
                    title="Pengeluaran operasional"
                    subtitle={`Total periode: ${rupiah(total)}`}
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/expenses/export?${exportQs}&format=xlsx`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/expenses/export?${exportQs}&format=pdf`}
                                >
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                <section className="grid max-w-2xl gap-3 rounded-xl border bg-card p-4">
                    <h2 className="font-semibold">Catat pengeluaran</h2>
                    <InfoNote>
                        Untuk uang yang bukan dari laci kasir (transfer, kartu,
                        kas besar). Belanja dengan uang laci dicatat kasir di
                        halaman Shift, supaya kas laci tetap cocok.
                    </InfoNote>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field label="Tanggal">
                            <Input
                                className="h-11"
                                type="date"
                                value={f.date}
                                onChange={(e) =>
                                    setF({ ...f, date: e.target.value })
                                }
                            />
                        </Field>
                        <Field label="Kategori beban">
                            <Select
                                value={f.category_id}
                                onChange={(e) =>
                                    setF({
                                        ...f,
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
                        <Field label="Nominal">
                            <MoneyInput
                                value={f.amount}
                                onChange={(v) => setF({ ...f, amount: v })}
                            />
                        </Field>
                        <Field label="Sumber dana">
                            <Select
                                value={f.source_account_id}
                                onChange={(e) =>
                                    setF({
                                        ...f,
                                        source_account_id: Number(
                                            e.target.value,
                                        ),
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
                    </div>
                    <Field label="Catatan">
                        <Input
                            className="h-11"
                            value={f.note}
                            onChange={(e) =>
                                setF({ ...f, note: e.target.value })
                            }
                        />
                    </Field>
                    <Field label="Foto nota (opsional)">
                        <ImagePicker
                            aspect="aspect-[4/3]"
                            file={f.receipt}
                            onChange={(receipt) => setF({ ...f, receipt })}
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={!f.amount}
                        onClick={() =>
                            router.post(
                                '/admin/expenses',
                                { ...f, receipt: f.receipt ?? undefined },
                                {
                                    forceFormData: true,
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        setF({
                                            ...f,
                                            amount: '',
                                            note: '',
                                            receipt: null,
                                        }),
                                },
                            )
                        }
                    >
                        Simpan & jurnalkan
                    </Button>
                </section>

                <form
                    className="grid grid-cols-2 items-end gap-3 rounded-xl border bg-card p-3 sm:flex sm:flex-wrap [&>button]:col-span-2 sm:[&>button]:col-span-1"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const d = new FormData(e.currentTarget);
                        router.get('/admin/expenses', {
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

                <ChartCard title="Pengeluaran per kategori">
                    <BarList
                        title="Pengeluaran per kategori"
                        data={byCategory}
                        color="#e11d48"
                    />
                </ChartCard>
                <ResponsiveTable
                    headers={[
                        'Tanggal',
                        'Kategori',
                        'Nominal',
                        'Catatan',
                        'Sumber',
                        'Nota',
                    ]}
                    numeric={[2]}
                    rows={expenses.map((e) => [
                        dateId(e.date),
                        e.category,
                        rupiah(e.amount),
                        e.note ?? '-',
                        e.from_drawer ? 'Kas laci' : 'Rekening',
                        e.receipt ? (
                            <a
                                key={e.id}
                                className="underline"
                                href={e.receipt}
                                target="_blank"
                                rel="noreferrer"
                            >
                                Lihat
                            </a>
                        ) : (
                            '-'
                        ),
                    ])}
                />

                {canManageCategories && (
                    <CategoryManager
                        categories={categories}
                        accounts={expenseAccounts}
                    />
                )}
            </div>
        </>
    );
}

/** Which costs staff may pay out of the cash drawer, and the categories themselves. */
function CategoryManager({
    categories,
    accounts,
}: {
    categories: Props['categories'];
    accounts: Props['expenseAccounts'];
}) {
    const [name, setName] = useState('');
    const [account, setAccount] = useState<number | ''>(accounts[0]?.id ?? '');
    const save = (
        c: { id?: number; name: string; account_id: number },
        allow: boolean,
    ) => {
        const body = {
            name: c.name,
            account_id: c.account_id,
            allow_drawer: allow ? 1 : 0,
        };
        const opts = { preserveScroll: true };

        if (c.id) {
            router.put(`/admin/expense-categories/${c.id}`, body, opts);
        } else {
            router.post('/admin/expense-categories', body, {
                ...opts,
                onSuccess: () => setName(''),
            });
        }
    };

    return (
        <section className="grid gap-3 rounded-xl border bg-card p-4">
            <div className="flex items-center gap-1.5">
                <h2 className="font-semibold">Kategori beban</h2>
                <InfoTip label="Info kategori beban">
                    Tiap kategori terhubung ke satu akun beban. Nyalakan
                    &quot;Boleh dari laci kas&quot; hanya untuk keperluan yang
                    wajar dibayar staf dari uang laci, seperti belanja bahan
                    atau galon. Gaji, sewa, dan listrik sebaiknya tetap mati:
                    dibayar lewat rekening di halaman ini.
                </InfoTip>
            </div>
            <ul className="divide-y">
                {categories.map((c) => (
                    <li
                        key={c.id}
                        className="flex items-center justify-between gap-3 py-2.5"
                    >
                        <span className="min-w-0">
                            <span className="block font-medium">{c.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {c.allow_drawer
                                    ? 'Staf boleh bayar dari laci kas'
                                    : 'Tidak dari laci kas'}
                            </span>
                        </span>
                        <Switch
                            label={`Boleh dari laci kas: ${c.name}`}
                            checked={c.allow_drawer}
                            onChange={(on) => save(c, on)}
                        />
                    </li>
                ))}
            </ul>
            <form
                className="grid gap-2 border-t pt-3 sm:grid-cols-[1fr_1fr_auto]"
                onSubmit={(e) => {
                    e.preventDefault();

                    if (name.trim() && account !== '') {
                        save(
                            { name: name.trim(), account_id: Number(account) },
                            false,
                        );
                    }
                }}
            >
                <Input
                    className="h-11"
                    placeholder="Kategori baru"
                    aria-label="Kategori baru"
                    value={name}
                    onChange={(e) => setName(e.target.value)}
                />
                <Select
                    aria-label="Akun beban"
                    value={account}
                    onChange={(e) => setAccount(Number(e.target.value))}
                >
                    {accounts.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.code} · {a.name}
                        </option>
                    ))}
                </Select>
                <Button
                    className="h-11"
                    disabled={!name.trim() || account === ''}
                >
                    Tambah
                </Button>
            </form>
        </section>
    );
}
