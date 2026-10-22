import { Head, router } from '@inertiajs/react';
import {
    Field,
    PageHeader,
    ResponsiveTable,
    Select,
} from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Row = {
    id: number;
    at: string;
    actor: string;
    event: string;
    group: string;
    detail: string;
};
type Props = {
    filters: {
        from: string;
        to: string;
        user_id: number | null;
        group: string | null;
        q: string | null;
    };
    rows: Row[];
    users: { id: number; name: string }[];
    groups: { key: string; label: string }[];
};

export default function ActivityPage({ filters, rows, users, groups }: Props) {
    const qs = (format: string) =>
        new URLSearchParams(
            Object.entries({ ...filters, format }).filter(
                ([, v]) => v !== null && v !== '',
            ) as [string, string][],
        ).toString();

    return (
        <>
            <Head title="Jejak Aktivitas" />
            <div className="mx-auto grid w-full max-w-[1400px] gap-5 p-4 md:p-6">
                <PageHeader
                    title="Jejak aktivitas"
                    subtitle="Siapa melakukan apa dan kapan: pesanan, pembayaran, shift, stok, dan pengaturan."
                    actions={
                        <>
                            <Button asChild variant="outline" className="h-11">
                                <a
                                    href={`/admin/activity/export?${qs('xlsx')}`}
                                >
                                    Unduh Excel
                                </a>
                            </Button>
                            <Button asChild variant="outline" className="h-11">
                                <a href={`/admin/activity/export?${qs('pdf')}`}>
                                    Unduh PDF
                                </a>
                            </Button>
                        </>
                    }
                />
                <form
                    className="flex flex-wrap items-end gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        const data = Object.fromEntries(
                            [...new FormData(e.currentTarget).entries()].filter(
                                ([, v]) => v !== '',
                            ),
                        );
                        router.get('/admin/activity', data, {
                            preserveState: true,
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
                    <Field label="Pelaku">
                        <Select
                            name="user_id"
                            defaultValue={filters.user_id ?? ''}
                        >
                            <option value="">Semua orang</option>
                            {users.map((u) => (
                                <option key={u.id} value={u.id}>
                                    {u.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Kelompok">
                        <Select name="group" defaultValue={filters.group ?? ''}>
                            <option value="">Semua</option>
                            {groups.map((g) => (
                                <option key={g.key} value={g.key}>
                                    {g.label}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Button className="h-11" type="submit">
                        Tampilkan
                    </Button>
                </form>

                {rows.length >= 800 && (
                    <p
                        role="status"
                        className="rounded-lg bg-amber-50 p-3 text-sm text-amber-950"
                    >
                        Menampilkan 800 aktivitas terbaru. Persempit tanggal,
                        pelaku, atau kelompok untuk melihat yang lebih lama.
                    </p>
                )}

                <ResponsiveTable
                    headers={['Waktu', 'Pelaku', 'Aksi', 'Kelompok', 'Detail']}
                    empty="Belum ada aktivitas pada periode ini."
                    rows={rows.map((r) => [
                        <span
                            key={r.id}
                            className="whitespace-nowrap tabular-nums"
                        >
                            {r.at}
                        </span>,
                        <span key="a" className="font-medium">
                            {r.actor}
                        </span>,
                        r.event,
                        r.group,
                        <span key="d" className="text-muted-foreground">
                            {r.detail || '-'}
                        </span>,
                    ])}
                />
            </div>
        </>
    );
}
