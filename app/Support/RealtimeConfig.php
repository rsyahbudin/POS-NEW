<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Browser-side WebSocket settings, decided at request time so a frontend built
 * in CI works on any server (no key or host baked into the JS bundle).
 *
 * Defaults: same host as the page. In production the socket sits behind Nginx
 * (wss/ws on the normal port, path /app); locally it is Reverb's own port.
 * Override with REVERB_PUBLIC_HOST / _PORT / _SCHEME when needed.
 */
final class RealtimeConfig
{
    /** @return array{key: string, host: string, port: int, scheme: string}|null */
    public static function forRequest(Request $request): ?array
    {
        $key = config('reverb.apps.apps.0.key');
        if (! is_string($key) || $key === '' || config('broadcasting.default') !== 'reverb') {
            return null;
        }

        $secure = $request->isSecure();
        $cfg = (array) config('pos.realtime');
        $scheme = (string) ($cfg['scheme'] ?: ($secure ? 'https' : 'http'));
        $port = (int) ($cfg['port'] ?: (app()->isProduction() ? ($scheme === 'https' ? 443 : 80) : $cfg['local_port']));

        return [
            'key' => $key,
            'host' => (string) ($cfg['host'] ?: $request->getHost()),
            'port' => $port,
            'scheme' => $scheme,
        ];
    }
}
