import { Head, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { dateId } from '@/lib/format';
import { cn } from '@/lib/utils';

type Row = {
    id: number;
    code: string;
    name: string;
    pax: number;
    status: 'booked' | 'seated';
    note: string | null;
    tables: string[];
    order: string | null;
    summary: { name: string; qty: number }[];
};

/** What the waiter at the door needs: who booked, how many, which tables, and one button when they arrive. */
export default function Arrivals({
    date,
    time,
    reservations,
}: {
    date: string;
    time: string;
    reservations: Row[];
}) {
    const [q, setQ] = useState('');
    const shown = reservations.filter(
        (r) =>
            r.name.toLowerCase().includes(q.toLowerCase()) ||
            r.code.toLowerCase().includes(q.toLowerCase()),
    );
    const waiting = reservations.filter((r) => r.status === 'booked');

    return (
        <>
            <Head title="Tamu Reservasi" />
            <div className="mx-auto grid w-full max-w-2xl gap-4 p-4 md:p-6">
                <PageHeader
                    title="Tamu reservasi"
                    subtitle={`${dateId(date)} · buka puasa pukul ${time} · ${waiting.length} belum datang. Tekan Sudah datang saat tamunya tiba: pesanan dibuka dan menu masuk ke dapur.`}
                />
                <Input
                    className="h-12"
                    placeholder="Cari nama atau kode…"
                    value={q}
                    onChange={(e) => setQ(e.target.value)}
                />
                {shown.length === 0 && (
                    <p className="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                        Belum ada reservasi terkonfirmasi untuk hari ini.
                    </p>
                )}
                <div className="grid gap-3">
                    {shown.map((r) => (
                        <article
                            key={r.id}
                            className={cn(
                                'grid gap-2 rounded-xl border bg-card p-4',
                                r.status === 'seated' && 'opacity-70',
                            )}
                        >
                            <header className="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <p className="text-lg font-semibold">
                                        {r.name}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {r.pax} orang · {r.code}
                                    </p>
                                </div>
                                <p className="text-sm font-medium">
                                    {r.tables.length > 0
                                        ? `Meja ${r.tables.join(' + ')}`
                                        : 'Meja dipilihkan saat datang'}
                                </p>
                            </header>
                            <p className="text-sm text-muted-foreground">
                                {r.summary
                                    .map((l) => `${l.qty}× ${l.name}`)
                                    .join(', ')}
                            </p>
                            {r.note && (
                                <p className="text-sm italic">“{r.note}”</p>
                            )}
                            {r.status === 'booked' ? (
                                <Button
                                    className="h-12"
                                    onClick={() =>
                                        confirm(
                                            `${r.name} (${r.pax} orang) sudah datang?`,
                                        ) &&
                                        router.post(
                                            `/reservations/${r.id}/seat`,
                                            {},
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Check /> Sudah datang
                                </Button>
                            ) : (
                                <p className="text-sm font-medium text-emerald-700 dark:text-emerald-400">
                                    Sudah duduk · {r.order}
                                </p>
                            )}
                        </article>
                    ))}
                </div>
            </div>
        </>
    );
}
