import { Link, usePage } from '@inertiajs/react';

type Tab = { label: string; href: string; show: boolean };
type Features =
    | { schedule?: boolean; attendance?: boolean; staffing?: boolean }
    | undefined;

function useAccess() {
    const page = usePage();
    const user = page.props.auth.user as unknown as {
        permissions?: string[];
        roles?: string[];
    } | null;
    const features = page.props.features as Features;
    const owner = user?.roles?.includes('owner') ?? false;
    const can = (...p: string[]) =>
        owner || p.some((x) => user?.permissions?.includes(x));

    return { can, features, path: new URL(page.url, 'http://x').pathname };
}

function Tabs({ tabs, path }: { tabs: Tab[]; path: string }) {
    const shown = tabs.filter((t) => t.show);
    if (shown.length < 2) {
        return null;
    }

    return (
        <nav
            aria-label="Bagian"
            className="-mx-1 flex [scrollbar-width:none] gap-1 overflow-x-auto px-1 [&::-webkit-scrollbar]:hidden"
        >
            {shown.map((t) => {
                const active = path === t.href;

                return (
                    <Link
                        key={t.href}
                        href={t.href}
                        aria-current={active ? 'page' : undefined}
                        className={`shrink-0 rounded-full border px-4 py-2 text-sm font-medium ${active ? 'border-primary bg-primary text-primary-foreground' : 'bg-card hover:bg-accent'}`}
                    >
                        {t.label}
                    </Link>
                );
            })}
        </nav>
    );
}

/** One sidebar entry "Absensi" with its pages as tabs: own attendance, schedule, team recap, longshift, faces. */
export function StaffingTabs() {
    const { can, features, path } = useAccess();
    const att = !!features?.attendance;
    const sch = !!features?.schedule;

    return (
        <Tabs
            path={path}
            tabs={[
                {
                    label: 'Absensi saya',
                    href: '/attendance/mine',
                    show:
                        att &&
                        can('view-own-attendance', 'use-attendance-kiosk'),
                },
                {
                    label: 'Jadwal',
                    href: '/schedule',
                    show: sch && can('view-schedule'),
                },
                {
                    label: 'Rekap tim',
                    href: '/attendance',
                    show: att && can('manage-attendance'),
                },
                {
                    label: 'Longshift',
                    href: '/longshift',
                    show:
                        !!features?.staffing &&
                        can(
                            'approve-longshift',
                            'manage-schedule',
                            'manage-attendance',
                        ),
                },
                {
                    label: 'Wajah',
                    href: '/attendance/faces',
                    show: att && can('manage-attendance'),
                },
            ]}
        />
    );
}

/** One sidebar entry "Pengeluaran" with expenses, fixed costs and the cash drawer report as tabs. */
export function SpendingTabs() {
    const { can, path } = useAccess();

    return (
        <Tabs
            path={path}
            tabs={[
                {
                    label: 'Pengeluaran',
                    href: '/admin/expenses',
                    show: can('input-expense'),
                },
                {
                    label: 'Beban tetap',
                    href: '/admin/fixed-expenses',
                    show: can('manage-fixed-expenses'),
                },
                {
                    label: 'Kas laci',
                    href: '/admin/cash-ledger',
                    show: can('view-cash-ledger'),
                },
            ]}
        />
    );
}
