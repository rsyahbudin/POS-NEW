import { Link, router, usePage } from '@inertiajs/react';
import { Ban, BarChart3, History, LogOut, Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { t } from '@/lang';
import { logout } from '@/routes';
import { canSee, sections, top } from '@/components/nav-items';

/** Who is signed in, plus the way out. The POS, kitchen and shift screens have no sidebar, so this is their account menu. */
export function AccountMenu({
    onHistory,
    onPrinter,
    onSoldOut,
}: {
    onHistory?: () => void;
    onPrinter?: () => void;
    onSoldOut?: () => void;
}) {
    const user = usePage().props.auth.user;
    const page = usePage();
    const who = user as unknown as {
        permissions?: string[];
        roles?: string[];
    } | null;
    const canToday = (who?.permissions ?? []).includes('view-today-summary');
    const features = page.props.features as
        | {
              kds: boolean;
              inventory: boolean;
              schedule: boolean;
              attendance: boolean;
              staffing: boolean;
          }
        | undefined;
    // The screens that have no sidebar still reach every page this person may use.
    const pages = [...top, ...sections.flatMap((s) => s.items)].filter(
        (i) => canSee(who, features, i) && i.href !== page.url.split('?')[0],
    );
    const initials = useInitials();

    if (!user) {
        return null;
    }

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    className="size-11 shrink-0 rounded-full text-sm font-semibold"
                    aria-label={`${t('Akun')} ${user.name}`}
                >
                    {initials(user.name)}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="max-h-[80dvh] w-64 overflow-y-auto"
            >
                <DropdownMenuLabel className="font-normal">
                    <p className="truncate text-sm font-semibold">
                        {user.name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </p>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />
                {onHistory && (
                    <DropdownMenuItem
                        className="h-11 text-base"
                        onSelect={onHistory}
                    >
                        <History className="mr-2" />
                        {t('Riwayat transaksi')}
                    </DropdownMenuItem>
                )}
                {canToday && (
                    <DropdownMenuItem asChild className="h-11 text-base">
                        <Link className="w-full cursor-pointer" href="/today">
                            <BarChart3 className="mr-2" />
                            {t('Ringkasan hari ini')}
                        </Link>
                    </DropdownMenuItem>
                )}
                {onSoldOut && (
                    <DropdownMenuItem
                        className="h-11 text-base sm:hidden"
                        onSelect={onSoldOut}
                    >
                        <Ban className="mr-2" />
                        {t('Stok & menu habis')}
                    </DropdownMenuItem>
                )}
                {onPrinter && (
                    <DropdownMenuItem
                        className="h-11 text-base"
                        onSelect={onPrinter}
                    >
                        <Printer className="mr-2" />
                        {t('Pengaturan printer')}
                    </DropdownMenuItem>
                )}
                {pages.length > 0 && (
                    <>
                        <DropdownMenuLabel className="text-xs font-medium text-muted-foreground">
                            {t('Halaman lain')}
                        </DropdownMenuLabel>
                        {pages.map((i) => (
                            <DropdownMenuItem
                                key={i.title}
                                asChild
                                className="h-11 text-base"
                            >
                                <Link
                                    className="w-full cursor-pointer"
                                    href={i.href}
                                >
                                    {i.icon && <i.icon className="mr-2" />}
                                    {i.title}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                        <DropdownMenuSeparator />
                    </>
                )}
                <DropdownMenuItem asChild className="h-11 text-base">
                    <Link
                        className="block w-full cursor-pointer"
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        data-test="logout-button"
                    >
                        <LogOut className="mr-2" />
                        {t('Keluar')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
