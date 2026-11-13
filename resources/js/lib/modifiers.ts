import type { ModifierGroup } from '@/types/pos';

/**
 * Pre-select marked defaults (up to max). If none are marked and the group
 * requires picks, take the first available options so the guest/cashier taps less.
 */
export function initialModifierPicks(
    groups: ModifierGroup[],
): Record<number, number[]> {
    const out: Record<number, number[]> = {};

    for (const g of groups) {
        const available = g.modifiers.filter((m) => m.is_available);
        const marked = available
            .filter((m) => m.is_default)
            .slice(0, g.max)
            .map((m) => m.id);

        if (marked.length > 0) {
            out[g.id] = marked;
        } else if (g.min > 0) {
            out[g.id] = available.slice(0, g.min).map((m) => m.id);
        }
    }

    return out;
}
