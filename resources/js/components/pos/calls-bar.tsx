import { BellRing, Check } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import type { ServiceCall } from '@/types/pos';

function ago(iso: string, now: number): string {
    const m = Math.max(0, Math.round((now - new Date(iso).getTime()) / 60000));

    return m < 1 ? t('baru saja') : `${m} ${t('mnt lalu')}`;
}

/** Tables calling for staff, most urgent (oldest) first. Stays on top of the screen until each is answered. */
export function CallsBar({
    calls,
    onDone,
}: {
    calls: ServiceCall[];
    onDone: (id: number) => void;
}) {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const id = window.setInterval(() => setNow(Date.now()), 15000);

        return () => window.clearInterval(id);
    }, []);

    if (calls.length === 0) {
        return null;
    }

    return (
        <section
            role="alert"
            aria-label={t('Panggilan meja')}
            className="flex flex-wrap items-center gap-2 border-b border-amber-300 bg-amber-100 px-3 py-2 text-amber-950 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-50"
        >
            <span className="flex items-center gap-1.5 text-sm font-semibold">
                <BellRing className="size-4 animate-pulse" aria-hidden />
                {t('Panggilan meja')}
            </span>
            {calls.map((c) => (
                <span
                    key={c.id}
                    className="flex items-center gap-2 rounded-full border border-amber-400 bg-white py-1 pr-1 pl-3 text-sm dark:bg-amber-900"
                >
                    <strong>{c.table}</strong>
                    <span className="text-xs text-amber-800 dark:text-amber-200">
                        {c.reason_label} · {ago(c.at, now)}
                        {c.repeats > 0 ? ` · ${c.repeats + 1}x` : ''}
                    </span>
                    <Button
                        type="button"
                        size="sm"
                        className="h-9 rounded-full"
                        onClick={() => onDone(c.id)}
                    >
                        <Check /> {t('Selesai')}
                    </Button>
                </span>
            ))}
        </section>
    );
}
