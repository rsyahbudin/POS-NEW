import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

/** The cafe's logo, or a monogram in the accent colour when no logo is uploaded yet. */
export function BrandMark({ className }: { className?: string }) {
    const { brand } = usePage().props;

    if (brand.logo) {
        return (
            <img
                src={brand.logo}
                alt={brand.name}
                className={cn(
                    'size-20 rounded-3xl bg-card object-cover shadow-sm ring-1 ring-black/5',
                    className,
                )}
            />
        );
    }

    return (
        <div
            aria-hidden
            className={cn(
                'grid size-20 place-items-center rounded-3xl bg-primary text-2xl font-bold tracking-tight text-primary-foreground shadow-sm',
                className,
            )}
        >
            {initials(brand.name)}
        </div>
    );
}
