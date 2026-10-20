import { useEffect, useRef, useState } from 'react';
import { usePage } from '@inertiajs/react';
import { getEcho } from '@/lib/echo';
import type { RealtimeConfig } from '@/lib/echo';

type Subscription = { channel: string; private?: boolean; events: string[] };

/**
 * Subscribes to Reverb channels and calls `onEvent` for every event. When the
 * socket is down it polls `refresh` every 10 s and reports `online = false`
 * (PRD 7.5: "Offline" indicator + polling fallback).
 */
export function useRealtime(
    subs: Subscription[],
    onEvent: (event: string, payload: unknown) => void,
    refresh: () => void,
) {
    const cfg = usePage().props.realtime as RealtimeConfig | null;
    const [online, setOnline] = useState(false);
    const handler = useRef(onEvent);
    const refreshRef = useRef(refresh);
    const key = JSON.stringify(subs);

    useEffect(() => {
        handler.current = onEvent;
        refreshRef.current = refresh;
    });

    useEffect(() => {
        const echo = getEcho(cfg);
        let socketUp = false;
        const timer = window.setInterval(() => {
            if (!socketUp) {
                refreshRef.current();
            }
        }, 10000);

        if (!echo) {
            return () => window.clearInterval(timer);
        }

        const conn = echo.connector.pusher.connection;
        const onState = (state: { current: string }) => {
            socketUp = state.current === 'connected';
            setOnline(socketUp);

            if (socketUp) {
                refreshRef.current();
            }
        };
        conn.bind('state_change', onState);
        socketUp = conn.state === 'connected';

        const channels = (JSON.parse(key) as Subscription[]).map((s) => {
            const ch =
                s.private === false
                    ? echo.channel(s.channel)
                    : echo.private(s.channel);

            for (const ev of s.events) {
                ch.listen(`.${ev}`, (payload: unknown) =>
                    handler.current(ev, payload),
                );
            }

            return s;
        });

        return () => {
            window.clearInterval(timer);
            conn.unbind('state_change', onState);
            channels.forEach((s) => echo.leave(s.channel));
        };
    }, [key, cfg?.key, cfg?.host, cfg?.port, cfg?.scheme]);

    return { online };
}
