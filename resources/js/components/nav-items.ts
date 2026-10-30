import {
    BookOpenCheck,
    CalendarCheck,
    ClipboardCheck,
    ChefHat,
    Coins,
    History,
    FileBarChart,
    FileDown,
    LayoutGrid,
    Monitor,
    CreditCard,
    Percent,
    QrCode,
    Printer,
    Package,
    Receipt,
    Settings,
    Smartphone,
    Timer,
    Trash2,
    Truck,
    ShieldCheck,
    ShoppingBasket,
    Users,
    UtensilsCrossed,
    Wrench,
    Wallet,
    CalendarClock,
    CalendarDays,
    Hourglass,
    ScanFace,
    UserCheck,
} from 'lucide-react';
import type { NavSection } from '@/components/nav-main';
import type { NavItem } from '@/types';

export type Item = NavItem & {
    permission?: string | string[];
    feature?: 'kds' | 'inventory' | 'schedule' | 'attendance' | 'staffing';
};

export const top: Item[] = [
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
        title: 'Dapur (KDS)',
        href: '/kds',
        icon: ChefHat,
        permission: 'update-kitchen-status',
        feature: 'kds',
    },
    {
        title: 'Jadwal Kerja',
        href: '/schedule',
        icon: CalendarDays,
        permission: 'view-schedule',
        feature: 'schedule',
    },
    {
        title: 'Persetujuan Longshift',
        href: '/longshift',
        icon: Hourglass,
        permission: 'approve-longshift',
        feature: 'staffing',
    },
    {
        title: 'Kiosk Absensi',
        href: '/attendance/kiosk',
        icon: ScanFace,
        permission: 'use-attendance-kiosk',
        feature: 'attendance',
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
        title: 'Bahan Terbuang',
        href: '/stock/waste',
        icon: Trash2,
        permission: 'record-waste',
    },
    {
        title: 'Stasiun Cetak',
        href: '/print-station',
        icon: Printer,
        permission: 'use-print-station',
    },
];

export const sections: (Omit<NavSection, 'items'> & { items: Item[] })[] = [
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
                title: 'Diskon & Promo',
                href: '/admin/discounts',
                icon: Percent,
                permission: 'manage-discount-presets',
            },
            {
                title: 'Pemasok',
                href: '/admin/suppliers',
                icon: Truck,
                permission: 'manage-suppliers',
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
                title: 'Laporan Waste',
                href: '/admin/reports/waste',
                icon: Trash2,
                permission: 'view-waste-report',
            },
            {
                title: 'Pembayaran Online',
                href: '/admin/online-payments',
                icon: Smartphone,
                permission: 'manage-online-payments',
            },
            {
                title: 'Jejak Aktivitas',
                href: '/admin/activity',
                icon: History,
                permission: 'view-activity-log',
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
                title: 'Shift & Kas',
                href: '/shift',
                icon: Coins,
                permission: 'manage-shifts-any',
            },
            {
                title: 'Absensi',
                href: '/attendance',
                icon: UserCheck,
                permission: 'manage-attendance',
                feature: 'attendance',
            },
            {
                title: 'Beban Tetap',
                href: '/admin/fixed-expenses',
                icon: CalendarClock,
                permission: 'manage-fixed-expenses',
            },
            {
                title: 'Laporan Kas Laci',
                href: '/admin/cash-ledger',
                icon: Wallet,
                permission: 'view-cash-ledger',
            },
            {
                title: 'Pengeluaran',
                href: '/admin/expenses',
                icon: ShoppingBasket,
                permission: 'input-expense',
            },
            {
                title: 'Pembayaran & Pajak',
                href: '/admin/payment-settings',
                icon: CreditCard,
                permission: 'manage-pricing',
            },
            {
                title: 'Akuntansi',
                href: '/admin/accounting',
                icon: BookOpenCheck,
                permission: 'view-accounting',
            },
            {
                title: 'Laporan Harian',
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

type Who = { permissions?: string[]; roles?: string[] } | null | undefined;
type Features =
    | {
          kds: boolean;
          inventory: boolean;
          schedule: boolean;
          attendance: boolean;
          staffing: boolean;
      }
    | undefined;

/** Whether this person may see the item: permission and, when the item depends on one, its feature switched on. */
export function canSee(user: Who, features: Features, i: Item): boolean {
    const owner = user?.roles?.includes('owner') ?? false;
    const p = i.permission;
    const allowed =
        !p || owner || [p].flat().some((x) => user?.permissions?.includes(x));

    return allowed && (!i.feature || !!features?.[i.feature]);
}
