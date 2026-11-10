/** Selling prices are rounded up to this step: nobody prices a plate at Rp46.429. */
export const PRICE_STEP = 500;

/** The price that brings the ingredient cost down to the target share of it: cost / target, rounded up to the step. */
export function suggestedPrice(
    cost: number,
    targetPct: number,
    step = PRICE_STEP,
): number | null {
    if (cost <= 0 || targetPct <= 0) {
        return null;
    }

    return Math.ceil(cost / (targetPct / 100) / step) * step;
}

/** Ingredient cost as a percentage of the selling price. */
export function foodCostPct(price: number, cost: number): number | null {
    return price > 0 ? (cost / price) * 100 : null;
}

export type PriceVerdict = 'good' | 'low' | 'none';

/** Is the current price at or above the suggested one? */
export function verdict(
    price: number,
    cost: number,
    targetPct: number,
): PriceVerdict {
    const food = foodCostPct(price, cost);

    if (cost <= 0 || food === null) {
        return 'none';
    }

    return food <= targetPct ? 'good' : 'low';
}
