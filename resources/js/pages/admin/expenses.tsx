import { Head, router, usePage } from '@inertiajs/react';
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

type Props = {
    filters: { from: string; to: string };
    expenses: {
        id: number;
        date: string;
        category: string;
        amount: number;
        note: string | null;
        receipt: string | null;
    }[];
    categories: { id: number; name: string }[];
    sources: { id: number; code: string; name: string }[];
    today: string;
};

export default function ExpensesPage({
    filters,
    expenses,
    categories,
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

    return (
        <>
            <Head title="Pengeluaran" />
            <div className="grid gap-4 p-4">
                <PageHeader
                    title="Pengeluaran operasional"
                    subtitle={`Total periode: ${rupiah(total)}`}
                />
                {Object.values(errors ?? {})[0] && (
                    <p role="alert" className="text-sm text-destructive">
                        {Object.values(errors)[0]}
                    </p>
                )}

                <section className="grid max-w-2xl gap-3 rounded-xl border bg-card p-4">
                    <h2 className="font-semibold">Catat pengeluaran</h2>
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
                        <input
                            type="file"
                            accept="image/*"
                            onChange={(e) =>
                                setF({
                                    ...f,
                                    receipt: e.target.files?.[0] ?? null,
                                })
                            }
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
                    className="flex flex-wrap items-end gap-2"
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

                <ResponsiveTable
                    headers={[
                        'Tanggal',
                        'Kategori',
                        'Nominal',
                        'Catatan',
                        'Nota',
                    ]}
                    numeric={[2]}
                    rows={expenses.map((e) => [
                        dateId(e.date),
                        e.category,
                        rupiah(e.amount),
                        e.note ?? '-',
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
            </div>
        </>
    );
}
