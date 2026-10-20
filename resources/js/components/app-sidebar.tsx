import { Link, usePage } from '@inertiajs/react';
import {
    BookOpenCheck,
    CalendarCheck,
    ChefHat,
    Coins,
    FileBarChart,
    LayoutGrid,
    Monitor,
    QrCode,
    Receipt,
    Settings,
    ShoppingBasket,
    Users,
    UtensilsCrossed,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import type { NavItem } from '@/types';

type Item = NavItem & { permission?: string | string[] };

const items: Item[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
        icon: LayoutGrid,
        permission: 'view-sales-reports',
    },
    {
        title: 'Kasir',
        href: '/pos',
        icon: Monitor,
        permission: ['create-order', 'receive-payment'],
    },
    {
        title: 'Shift',
        href: '/shift',
        icon: Coins,
        permission: 'open-close-shift',
    },
    {
        title: 'Dapur (KDS)',
        href: '/kds',
        icon: ChefHat,
        permission: 'update-kitchen-status',
    },
    {
        title: 'Menu',
        href: '/admin/menu',
        icon: UtensilsCrossed,
        permission: 'manage-menu',
    },
    {
        title: 'Meja & QR',
        href: '/admin/tables',
        icon: QrCode,
        permission: 'manage-tables',
    },
    {
        title: 'Pengeluaran',
        href: '/admin/expenses',
        icon: ShoppingBasket,
        permission: 'input-expense',
    },
    {
        title: 'Laporan Penjualan',
        href: '/admin/reports/sales',
        icon: FileBarChart,
        permission: 'view-sales-reports',
    },
    {
        title: 'Diskon/Void/Refund',
        href: '/admin/reports/adjustments',
        icon: Receipt,
        permission: 'view-sales-reports',
    },
    {
        title: 'Akuntansi',
        href: '/admin/accounting',
        icon: BookOpenCheck,
        permission: 'view-accounting',
    },
    {
        title: 'Tutup Hari',
        href: '/admin/day-close',
        icon: CalendarCheck,
        permission: 'manage-shifts-any',
    },
    {
        title: 'User',
        href: '/admin/users',
        icon: Users,
        permission: 'manage-users',
    },
    {
        title: 'Pengaturan',
        href: '/admin/settings',
        icon: Settings,
        permission: 'manage-settings',
    },
];

export function AppSidebar() {
    const user = usePage().props.auth.user as unknown as {
        permissions?: string[];
        roles?: string[];
    } | null;
    const isOwner = user?.roles?.includes('owner') ?? false;
    const has = (p?: string | string[]) =>
        !p || isOwner || [p].flat().some((x) => user?.permissions?.includes(x));
    const visible = items.filter((i) => has(i.permission));

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={visible} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
