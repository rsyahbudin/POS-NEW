import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import StaffLayout from '@/layouts/app/staff-layout';
import type { BreadcrumbItem } from '@/types';

type Flash = { success?: string | null; error?: string | null };

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const page = usePage();
    const flash = page.props.flash as Flash | undefined;
    const floor = page.props.shell === 'floor';

    useEffect(() => {
        if (flash?.success) {
            toast.success(flash.success);
        }

        if (flash?.error) {
            toast.error(flash.error);
        }
    }, [flash?.success, flash?.error]);

    // Back-office people get the sidebar; floor staff get the plain top bar. One rule for every page.
    return floor ? (
        <StaffLayout>{children}</StaffLayout>
    ) : (
        <AppLayoutTemplate breadcrumbs={breadcrumbs}>
            {children}
        </AppLayoutTemplate>
    );
}
