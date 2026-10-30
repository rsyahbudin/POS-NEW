import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { NotificationBell } from '@/components/notification-bell';
import { AccountMenu } from '@/components/pos/account-menu';

/**
 * The shell for floor staff (cashier, waiter, kitchen, bar, MOD): no sidebar, ever. A plain top bar with the way back to
 * the work screen, the bell and the account menu (which lists every other page they may open).
 */
export default function StaffLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage();
    const home = (page.props.home as string | undefined) ?? '/';
    const here = page.url.split('?')[0];

    return (
        <div className="min-h-svh bg-background">
            <header className="sticky top-0 z-20 flex h-14 items-center justify-between gap-2 border-b bg-background/95 px-3 backdrop-blur md:px-5">
                {here === home ? (
                    <span className="px-2 text-sm font-semibold">
                        {String(page.props.name ?? '')}
                    </span>
                ) : (
                    <Link
                        href={home}
                        className="inline-flex h-11 items-center gap-2 rounded-xl px-3 text-sm font-medium hover:bg-accent"
                    >
                        <ArrowLeft className="size-4" /> Layar kerja
                    </Link>
                )}
                <div className="flex items-center gap-2">
                    <NotificationBell />
                    <AccountMenu />
                </div>
            </header>
            <main className="min-w-0">{children}</main>
        </div>
    );
}
