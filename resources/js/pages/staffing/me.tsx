import { Head, router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { PageHeader, ResponsiveTable } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { dateId, timeId } from '@/lib/format';

type Props = {
    name: string;
    open_since: string | null;
    planned: {
        name: string;
        starts_at: string;
        ends_at: string;
        positions: string[];
    }[];
    recent: {
        id: number;
        work_date: string;
        clock_in_at: string;
        clock_out_at: string | null;
        hours: number | null;
        method: string;
    }[];
};

const since = (iso: string, now: number) => {
    const m = Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));

    return `${Math.floor(m / 60)} jam ${m % 60} menit`;
};

export default function Me({ name, open_since, planned, recent }: Props) {
    const errors = usePage().props.errors as Record<string, string>;
    const [now, setNow] = useState(Date.now());
    const [busy, setBusy] = useState(false);
    const firstError = Object.values(errors ?? {})[0];

    useEffect(() => {
        const t = setInterval(() => setNow(Date.now()), 30000);

        return () => clearInterval(t);
    }, []);

    return (
        <>
            <Head title="Absen Saya" />
            <div className="mx-auto grid w-full max-w-xl gap-5 p-4 md:p-6">
                <PageHeader title="Absen saya" subtitle={name} />
                {firstError && (
                    <p role="alert" className="text-sm text-destructive">
                        {firstError}
                    </p>
                )}

                <section className="grid gap-3 rounded-2xl border bg-card p-5 text-center">
                    {open_since ? (
                        <>
                            <p className="text-sm text-muted-foreground">
                                Masuk pukul {timeId(open_since)}
                            </p>
                            <p className="text-2xl font-semibold">
                                Sudah bekerja {since(open_since, now)}
                            </p>
                        </>
                    ) : (
                        <p className="text-2xl font-semibold">Belum masuk</p>
                    )}
                    <Button
                        className="h-14 text-lg"
                        variant={open_since ? 'outline' : 'default'}
                        disabled={busy}
                        onClick={() =>
                            router.post(
                                '/attendance/me/clock',
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setBusy(true),
                                    onFinish: () => setBusy(false),
                                },
                            )
                        }
                    >
                        {open_since ? 'Pulang' : 'Masuk'}
                    </Button>
                    {planned.length > 0 && (
                        <p className="text-sm text-muted-foreground">
                            Jadwal hari ini:{' '}
                            {planned
                                .map(
                                    (p) =>
                                        `${p.name} ${p.starts_at}–${p.ends_at}${p.positions.length ? ` (${p.positions.join(' + ')})` : ''}`,
                                )
                                .join(', ')}
                        </p>
                    )}
                </section>

                <section className="grid gap-2">
                    <h2 className="font-semibold">7 hari terakhir</h2>
                    <ResponsiveTable
                        headers={['Tanggal', 'Masuk', 'Pulang', 'Jam', 'Cara']}
                        numeric={[3]}
                        rows={recent.map((r) => [
                            dateId(r.work_date),
                            timeId(r.clock_in_at),
                            r.clock_out_at
                                ? timeId(r.clock_out_at)
                                : 'Belum pulang',
                            r.hours ?? '-',
                            r.method === 'face'
                                ? 'Wajah'
                                : r.method === 'button'
                                  ? 'Tombol'
                                  : 'Manual',
                        ])}
                    />
                </section>
            </div>
        </>
    );
}
