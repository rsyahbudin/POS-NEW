import { Link, router, usePage } from '@inertiajs/react';
import { Ban, History, LogOut, Printer } from 'lucide-react';
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

/** Who is signed in, plus the way out. The POS, kitchen and shift screens have no sidebar, so this is their account menu. */
export function AccountMenu({
    onHistory,
    onPrinter,
    onSoldOut,
    soldOutOn,
}: {
    onHistory?: () => void;
    onPrinter?: () => void;
    onSoldOut?: () => void;
    soldOutOn?: boolean;
}) {
    const user = usePage().props.auth.user;
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
            <DropdownMenuContent align="end" className="w-60">
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
                {onSoldOut && (
                    <DropdownMenuItem
                        className="h-11 text-base sm:hidden"
                        onSelect={onSoldOut}
                    >
                        <Ban className="mr-2" />
                        {soldOutOn
                            ? t('Selesai tandai habis')
                            : t('Tandai menu habis')}
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
