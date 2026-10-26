import { cn } from '@/lib/utils';

/** Soft tints used for menu items without a photo; the tint follows the category so a menu looks ordered, not random. */
const TINTS = [
    [262, 0.955, 0.42],
    [163, 0.95, 0.4],
    [55, 0.955, 0.45],
    [292, 0.955, 0.42],
    [20, 0.955, 0.45],
    [215, 0.955, 0.42],
] as const;

/** Light and dark colours as CSS variables, so the monogram follows the theme without a re-render. */
export function tintFor(index: number): React.CSSProperties {
    const [h, l, ink] = TINTS[Math.abs(index) % TINTS.length];

    return {
        '--art-bg': `oklch(${l} 0.04 ${h})`,
        '--art-ink': `oklch(${ink} 0.1 ${h})`,
        '--art-bg-dark': `oklch(0.3 0.05 ${h})`,
        '--art-ink-dark': `oklch(0.88 0.07 ${h})`,
    } as React.CSSProperties;
}

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase() ?? '')
        .join('');

/** Photo when the menu item has one, otherwise a tinted monogram. */
export function ProductArt({
    name,
    image,
    tint,
    className,
}: {
    name: string;
    image: string | null;
    tint: number;
    className?: string;
}) {
    if (image) {
        return (
            <img
                src={image}
                alt=""
                loading="lazy"
                className={cn('size-full object-cover', className)}
            />
        );
    }

    return (
        <div
            aria-hidden
            style={tintFor(tint)}
            className={cn(
                'grid size-full place-items-center bg-[var(--art-bg)] text-2xl font-semibold tracking-tight text-[var(--art-ink)] select-none dark:bg-[var(--art-bg-dark)] dark:text-[var(--art-ink-dark)]',
                className,
            )}
        >
            {initials(name)}
        </div>
    );
}
