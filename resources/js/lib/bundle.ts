import type { ModifierGroup, Product, Variant } from '@/types/pos';

/** What the guest can still choose for one menu item inside a bundle. */
export type BundlePart = {
    product_id: number;
    name: string;
    qty: number;
    fixed_variant: string | null;
    variants: Variant[];
    modifier_groups: ModifierGroup[];
};

export type BundlePick = {
    variantId: number | null;
    picked: Record<number, number[]>;
};
export type BundlePicks = Record<number, BundlePick>;
export type BundlePayload = {
    product_id: number;
    variant_id: number | null;
    modifier_ids: number[];
}[];

export const bundleParts = (p: Product): BundlePart[] => p.bundle ?? [];

/** Whether the guest has anything to choose in this bundle. */
export const bundleNeedsChoice = (p: Product): boolean =>
    bundleParts(p).some(
        (part) => part.variants.length > 0 || part.modifier_groups.length > 0,
    );

export const initialPicks = (p: Product): BundlePicks =>
    Object.fromEntries(
        bundleParts(p).map((part) => [
            part.product_id,
            {
                variantId:
                    (
                        part.variants.find((v) => v.is_available) ??
                        part.variants[0]
                    )?.id ?? null,
                picked: {},
            },
        ]),
    );

export const bundleExtra = (p: Product, picks: BundlePicks): number =>
    bundleParts(p).reduce(
        (sum, part) =>
            sum +
            part.modifier_groups
                .flatMap((g) =>
                    g.modifiers.filter((m) =>
                        picks[part.product_id]?.picked[g.id]?.includes(m.id),
                    ),
                )
                .reduce((s, m) => s + m.price_delta, 0),
        0,
    );

/** The first group still missing a pick, as a sentence, or null when the bundle is complete. */
export const bundleProblem = (
    p: Product,
    picks: BundlePicks,
): string | null => {
    for (const part of bundleParts(p)) {
        for (const g of part.modifier_groups) {
            if ((picks[part.product_id]?.picked[g.id]?.length ?? 0) < g.min) {
                return `Pilih minimal ${g.min} untuk ${g.name} (${part.name})`;
            }
        }
    }

    return null;
};

export const bundlePayload = (p: Product, picks: BundlePicks): BundlePayload =>
    bundleParts(p).map((part) => ({
        product_id: part.product_id,
        variant_id: part.variants.length
            ? (picks[part.product_id]?.variantId ?? null)
            : null,
        modifier_ids: Object.values(
            picks[part.product_id]?.picked ?? {},
        ).flat(),
    }));

/** Short text of the choices, e.g. "Es Teh Manis (Ice, Manis) · Ayam Goreng (Paha)". */
export const bundleLabel = (p: Product, picks: BundlePicks): string =>
    bundleParts(p)
        .map((part) => {
            const v = part.variants.find(
                (x) => x.id === picks[part.product_id]?.variantId,
            );
            const mods = part.modifier_groups.flatMap((g) =>
                g.modifiers
                    .filter((m) =>
                        picks[part.product_id]?.picked[g.id]?.includes(m.id),
                    )
                    .map((m) => m.name),
            );
            const detail = [v?.name, ...mods].filter(Boolean).join(', ');

            return detail ? `${part.name} (${detail})` : '';
        })
        .filter(Boolean)
        .join(' · ');
