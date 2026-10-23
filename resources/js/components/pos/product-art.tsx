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

export function tintFor(index: number) {
    const [h, l, ink] = TINTS[Math.abs(index) % TINTS.length];

    return {
        background: `oklch(${l} 0.04 ${h})`,
        color: `oklch(${ink} 0.1 ${h})`,
    };
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
                'grid size-full place-items-center text-2xl font-semibold tracking-tight select-none',
                className,
            )}
        >
            {initials(name)}
        </div>
    );
}
