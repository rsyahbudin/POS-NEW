import { router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCheck,
    Info,
    OctagonAlert,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useRealtime } from '@/hooks/use-realtime';
import { api } from '@/lib/api';
import { cn } from '@/lib/utils';

type Item = {
    id: number;
    kind: string;
    title: string;
    body: string | null;
    url: string | null;
    level: 'info' | 'warning' | 'danger';
    at: string | null;
    read: boolean;
};
type Feed = { unread: number; items: Item[] };

const ICON = { info: Info, warning: AlertTriangle, danger: OctagonAlert };
const TONE = {
    info: 'text-sky-600 dark:text-sky-400',
    warning: 'text-amber-600 dark:text-amber-400',
    danger: 'text-red-600 dark:text-red-400',
};

function ago(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const s = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );

    if (s < 60) {
        return 'baru saja';
    }

    if (s < 3600) {
        return `${Math.floor(s / 60)} mnt lalu`;
    }

    if (s < 86400) {
        return `${Math.floor(s / 3600)} jam lalu`;
    }

    return `${Math.floor(s / 86400)} hari lalu`;
}

/** Bell in the header: alerts for what this person's role can act on (stock to approve, low stock ...). */
export function NotificationBell() {
    const outletId = (
        usePage().props.auth.user as unknown as { outlet_id?: number } | null
    )?.outlet_id;
    const [feed, setFeed] = useState<Feed>({ unread: 0, items: [] });
    const [open, setOpen] = useState(false);
    const known = useRef<number | null>(null);
    const last = useRef(0);

    // Gentle on the server: a poll is skipped while the tab is hidden or a fetch happened in the last 45 s;
    // a realtime nudge (force) always fetches.
    const load = useCallback(async (force = false) => {
        if (!force && (document.hidden || Date.now() - last.current < 45000)) {
            return;
        }

        last.current = Date.now();

        try {
            const next = await api<Feed>('GET', '/notifications');

            if (known.current !== null && next.unread > known.current) {
                const newest = next.items.find((i) => !i.read);

                if (newest) {
                    toast(newest.title, {
                        description: newest.body ?? undefined,
                    });
                }
            }

            known.current = next.unread;
            setFeed(next);
        } catch {
            // keep what is shown
        }
    }, []);

    useEffect(() => {
        void load(true);
        const id = window.setInterval(() => void load(), 60000);
        const visible = () => {
            if (!document.hidden) {
                void load();
            }
        };
        document.addEventListener('visibilitychange', visible);

        return () => {
            window.clearInterval(id);
            document.removeEventListener('visibilitychange', visible);
        };
    }, [load]);

    useRealtime(
        outletId
            ? [
                  {
                      channel: `outlet.${outletId}.notify`,
                      events: ['NotificationCreated'],
                  },
              ]
            : [],
        () => void load(true),
        () => void load(),
    );

    const go = async (n: Item) => {
        if (!n.read) {
            await api('POST', `/notifications/${n.id}/read`).catch(
                () => undefined,
            );
        }

        setOpen(false);
        await load(true);

        if (n.url) {
            router.visit(n.url);
        }
    };

    return (
        <DropdownMenu open={open} onOpenChange={setOpen}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="relative size-10"
                    aria-label={`Notifikasi${feed.unread ? `, ${feed.unread} belum dibaca` : ''}`}
                >
                    <Bell />
                    {feed.unread > 0 && (
                        <span className="absolute top-1 right-1 grid min-w-5 place-items-center rounded-full bg-red-600 px-1 text-[11px] font-semibold text-white">
                            {feed.unread > 99 ? '99+' : feed.unread}
                        </span>
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-[22rem] max-w-[calc(100vw-1.5rem)] p-0"
            >
                <div className="flex items-center justify-between border-b px-3 py-2">
                    <p className="font-semibold">Notifikasi</p>
                    {feed.unread > 0 && (
                        <button
                            type="button"
                            className="flex items-center gap-1 text-xs text-primary underline"
                            onClick={async () => {
                                await api(
                                    'POST',
                                    '/notifications/read-all',
                                ).catch(() => undefined);
                                await load(true);
                            }}
                        >
                            <CheckCheck className="size-3.5" /> Tandai semua
                            dibaca
                        </button>
                    )}
                </div>
                <ul className="max-h-[26rem] overflow-y-auto">
                    {feed.items.map((n) => {
                        const Icon = ICON[n.level];

                        return (
                            <li key={n.id}>
                                <button
                                    type="button"
                                    onClick={() => void go(n)}
                                    className={cn(
                                        'flex w-full gap-2.5 border-b px-3 py-2.5 text-left last:border-b-0 hover:bg-muted/60',
                                        !n.read && 'bg-primary/5',
                                    )}
                                >
                                    <Icon
                                        className={cn(
                                            'mt-0.5 size-4 shrink-0',
                                            TONE[n.level],
                                        )}
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span
                                            className={cn(
                                                'block text-sm',
                                                !n.read && 'font-semibold',
                                            )}
                                        >
                                            {n.title}
                                        </span>
                                        {n.body && (
                                            <span className="block text-xs text-muted-foreground">
                                                {n.body}
                                            </span>
                                        )}
                                        <span className="block text-[11px] text-muted-foreground">
                                            {ago(n.at)}
                                        </span>
                                    </span>
                                    {!n.read && (
                                        <span
                                            className="mt-1.5 size-2 shrink-0 rounded-full bg-primary"
                                            aria-hidden
                                        />
                                    )}
                                </button>
                            </li>
                        );
                    })}
                    {feed.items.length === 0 && (
                        <li className="px-3 py-8 text-center text-sm text-muted-foreground">
                            Belum ada notifikasi.
                        </li>
                    )}
                </ul>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
