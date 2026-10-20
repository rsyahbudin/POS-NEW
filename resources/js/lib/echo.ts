import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        __echo?: Echo<'reverb'> | null;
    }
}

let echo: Echo<'reverb'> | null | undefined;

/** Lazily created Reverb connection. Returns null when WebSocket is not configured. */
export function getEcho(): Echo<'reverb'> | null {
    if (echo !== undefined) {
        return echo;
    }

    const key = import.meta.env.VITE_REVERB_APP_KEY;

    if (!key) {
        echo = null;

        return echo;
    }

    window.Pusher = Pusher;
    echo = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
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
    window.__echo = echo;

    return echo;
}
