import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { useRealtime } from '@/hooks/use-realtime';
import type { useSound } from '@/hooks/use-sound';
import { t } from '@/lang';
import { api } from '@/lib/api';
import type { ServiceCall } from '@/types/pos';

/** A bell left unanswered rings again after this long, a few times. */
const RERING_MS = 45000;
const MAX_RERINGS = 6;

/**
 * Guests pressing "Panggil waiter": the open calls for this screen, a sound and a toast for each new ring (also
 * when the same table presses again), and a way to mark one as answered.
 */
export function useServiceCalls(
    outletId: number,
    screen: 'pos' | 'kitchen',
    initial: ServiceCall[],
    sound: ReturnType<typeof useSound>,
    enabled = true,
) {
    const [calls, setCalls] = useState(initial);
    const rang = useRef<Record<number, string>>(
        Object.fromEntries(initial.map((c) => [c.id, c.rang_at])),
    );
    const last = useRef<Record<number, number>>({});
    const count = useRef<Record<number, number>>({});

    const refresh = useCallback(async () => {
        if (!enabled) {
            return;
        }

        try {
            const res = await api<{ calls: ServiceCall[] }>(
                'GET',
                `/pos/calls?to=${screen}`,
            );
            setCalls(res.calls);
        } catch {
            // transient; the next poll or event retries
        }
    }, [screen, enabled]);

    useEffect(() => {
        for (const c of calls) {
            if (rang.current[c.id] !== c.rang_at) {
                rang.current[c.id] = c.rang_at;
                last.current[c.id] = Date.now();
                count.current[c.id] = 0;
                sound.beep('call');
                toast.warning(
                    `${t('Meja')} ${c.table} ${t('memanggil')}: ${c.reason_label}`,
                    { duration: 12000 },
                );
            }
        }
    }, [calls, sound]);

    useEffect(() => {
        const id = window.setInterval(() => {
            const now = Date.now();

            for (const c of calls) {
                if (
                    now - (last.current[c.id] ?? now) >= RERING_MS &&
                    (count.current[c.id] ?? 0) < MAX_RERINGS
                ) {
                    last.current[c.id] = now;
                    count.current[c.id] = (count.current[c.id] ?? 0) + 1;
                    sound.beep('call');
                }
            }
        }, 5000);

        return () => window.clearInterval(id);
    }, [calls, sound]);

    const { online } = useRealtime(
        enabled
            ? [
                  {
                      channel: `outlet.${outletId}.calls`,
                      events: ['ServiceCallChanged'],
                  },
              ]
            : [],
        () => void refresh(),
        refresh,
    );

    const done = async (id: number) => {
        setCalls((cs) => cs.filter((c) => c.id !== id));

        try {
            await api('POST', `/pos/calls/${id}/done`, {});
        } catch {
            void refresh();
        }
    };

    return { calls, done, online };
}
