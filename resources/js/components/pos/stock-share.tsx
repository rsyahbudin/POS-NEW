import { usePage } from '@inertiajs/react';

/** With stock held per station, says whose ingredients these pages show; otherwise it renders nothing. */
export function StockShare() {
    const share = usePage().props.share as
        | { by_station: boolean; stations: string[] }
        | undefined;

    if (!share?.by_station) {
        return null;
    }

    return share.stations.length > 0 ? (
        <p className="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground">
            Bahan station:
            {share.stations.map((n) => (
                <span
                    key={n}
                    className="rounded-full bg-muted px-2.5 py-0.5 font-medium text-foreground"
                >
                    {n}
                </span>
            ))}
        </p>
    ) : (
        <p
            role="status"
            className="rounded-lg bg-amber-100 p-3 text-sm text-amber-900 dark:bg-amber-950/50 dark:text-amber-200"
        >
            Akunmu belum punya station, jadi belum ada bahan yang bisa diisi.
            Minta manager mengaturnya di Manajemen user.
        </p>
    );
}
