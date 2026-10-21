import { Link, usePage } from '@inertiajs/react';
import {
    BookOpenCheck,
    CalendarCheck,
    ClipboardCheck,
    ChefHat,
    Coins,
    FileBarChart,
    LayoutGrid,
    Monitor,
    QrCode,
    Printer,
    Package,
    Receipt,
    Settings,
    Timer,
    ShieldCheck,
    ShoppingBasket,
    Users,
    UtensilsCrossed,
    Wrench,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import type { NavSection } from '@/components/nav-main';
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

type Item = NavItem & {
    permission?: string | string[];
    feature?: 'kds' | 'inventory';
};

const top: Item[] = [
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
        feature: 'kds',
    },
    {
        title: 'Daftar Stok',
        href: '/stock',
        icon: Package,
        permission: 'view-stock',
    },
    {
        title: 'Stok Opname',
        href: '/stock/count',
        icon: ClipboardCheck,
        permission: 'count-stock',
    },
    {
        title: 'Belanja Bahan',
        href: '/stock/purchase',
        icon: ShoppingBasket,
        permission: 'record-purchase',
    },
    {
        title: 'Stasiun Cetak',
        href: '/print-station',
        icon: Printer,
        permission: 'use-print-station',
    },
];

const sections: (Omit<NavSection, 'items'> & { items: Item[] })[] = [
    {
        title: 'Menu, Stok & Aset',
        icon: UtensilsCrossed,
        items: [
            {
                title: 'Menu',
                href: '/admin/menu',
                icon: UtensilsCrossed,
                permission: 'manage-menu',
            },
            {
                title: 'Stok & Bahan',
                href: '/admin/inventory',
                icon: Package,
                permission: 'manage-inventory',
            },
            {
                title: 'Resep & HPP',
                href: '/admin/recipes',
                icon: BookOpenCheck,
                permission: 'manage-inventory',
            },
            {
                title: 'Aset & Alat',
                href: '/assets',
                icon: Wrench,
                permission: 'view-assets',
            },
            {
                title: 'Meja & QR',
                href: '/admin/tables',
                icon: QrCode,
                permission: 'manage-tables',
            },
        ],
    },
    {
        title: 'Laporan',
        icon: FileBarChart,
        items: [
            {
                title: 'Laporan Penjualan',
                href: '/admin/reports/sales',
                icon: FileBarChart,
                permission: 'view-sales-reports',
            },
            {
                title: 'Margin & HPP',
                href: '/admin/reports/margin',
                icon: FileBarChart,
                permission: 'view-margin-report',
            },
            {
                title: 'Kinerja Dapur',
                href: '/admin/reports/kitchen',
                icon: Timer,
                permission: 'view-kitchen-report',
                feature: 'kds',
            },
            {
                title: 'Diskon/Void/Refund',
                href: '/admin/reports/adjustments',
                icon: Receipt,
                permission: 'view-sales-reports',
            },
        ],
    },
    {
        title: 'Keuangan',
        icon: BookOpenCheck,
        items: [
            {
                title: 'Pengeluaran',
                href: '/admin/expenses',
                icon: ShoppingBasket,
                permission: 'input-expense',
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
        ],
    },
    {
        title: 'Pengaturan',
        icon: Settings,
        items: [
            {
                title: 'Pengaturan',
                href: '/admin/settings',
                icon: Settings,
                permission: 'manage-settings',
            },
            {
                title: 'Printer',
                href: '/admin/printers',
                icon: Printer,
                permission: 'manage-printers',
            },
            {
                title: 'User',
                href: '/admin/users',
                icon: Users,
                permission: 'manage-users',
            },
            {
                title: 'Peran & Akses',
                href: '/admin/roles',
                icon: ShieldCheck,
                permission: 'manage-roles',
            },
        ],
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
    const features = usePage().props.features as
        | { kds: boolean; inventory: boolean }
        | undefined;
    const show = (i: Item) =>
        has(i.permission) && (!i.feature || features?.[i.feature]);
    const visibleSections = sections
        .map((s) => ({ ...s, items: s.items.filter(show) }))
        .filter((s) => s.items.length > 0);

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
                <NavMain top={top.filter(show)} sections={visibleSections} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
