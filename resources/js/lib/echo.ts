import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        __echo?: Echo<'reverb'> | null;
    }
}

/** Shared by the server on every authenticated page (App\Support\RealtimeConfig). */
export type RealtimeConfig = {
    key: string;
    host: string;
    port: number;
    scheme: string;
};

let echo: Echo<'reverb'> | null | undefined;
let usedKey = '';

/** Lazily created Reverb connection. Returns null when the server has no WebSocket configured. */
export function getEcho(
    cfg: RealtimeConfig | null | undefined,
): Echo<'reverb'> | null {
    if (!cfg) {
        return null;
    }

    const signature = `${cfg.key}|${cfg.host}|${cfg.port}|${cfg.scheme}`;

    if (echo && usedKey === signature) {
        return echo;
    }

    echo?.disconnect();
    window.Pusher = Pusher;
    echo = new Echo({
        broadcaster: 'reverb',
        key: cfg.key,
        wsHost: cfg.host,
        wsPort: cfg.port,
        wssPort: cfg.port,
        forceTLS: cfg.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
        authEndpoint: '/broadcasting/auth',
        auth: {
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent(
                    document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? '',
                ),
            },
        },
    });
    usedKey = signature;
    window.__echo = echo;

    return echo;
}
