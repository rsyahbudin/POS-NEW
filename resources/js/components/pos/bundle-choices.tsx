import { bundleParts } from '@/lib/bundle';
import type { BundlePicks } from '@/lib/bundle';
import { rupiah } from '@/lib/format';
import type { Product } from '@/types/pos';

const chip = (on: boolean) =>
    `flex min-h-12 items-center justify-between gap-2 rounded-xl border-2 px-3.5 py-2 text-left text-sm font-medium transition-colors disabled:opacity-45 ${on ? 'border-primary bg-primary/10 text-foreground' : 'border-transparent bg-muted'}`;

/**
 * What the guest chooses inside a bundle: per menu item, its variant (when the bundle leaves it open) and its own options.
 * Shared by the cashier's sheet and the QR menu; the server checks every choice again.
 */
export function BundleChoices({
    product,
    picks,
    onChange,
}: {
    product: Product;
    picks: BundlePicks;
    onChange: (next: BundlePicks) => void;
}) {
    const set = (id: number, patch: Partial<BundlePicks[number]>) => {
        const cur = picks[id] ?? { variantId: null, picked: {} };

        onChange({ ...picks, [id]: { ...cur, ...patch } });
    };

    const toggle = (
        partId: number,
        groupId: number,
        id: number,
        max: number,
    ) => {
        const cur = picks[partId]?.picked[groupId] ?? [];
        const next = cur.includes(id)
            ? cur.filter((x) => x !== id)
            : max === 1
              ? [id]
              : cur.length < max
                ? [...cur, id]
                : cur;

        set(partId, { picked: { ...picks[partId]?.picked, [groupId]: next } });
    };

    return (
        <div className="grid gap-5">
            {bundleParts(product)
                .filter(
                    (p) =>
                        p.variants.length > 0 || p.modifier_groups.length > 0,
                )
                .map((part) => (
                    <section
                        key={part.product_id}
                        className="grid gap-3 rounded-xl border p-3"
                    >
                        <h3 className="text-sm font-bold">
                            {part.qty > 1 ? `${part.qty}× ` : ''}
                            {part.name}
                        </h3>
                        {part.variants.length > 0 && (
                            <fieldset className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    Varian
                                </legend>
                                <div className="grid grid-cols-2 gap-2">
                                    {part.variants.map((v) => (
                                        <button
                                            key={v.id}
                                            type="button"
                                            disabled={!v.is_available}
                                            aria-pressed={
                                                picks[part.product_id]
                                                    ?.variantId === v.id
                                            }
                                            onClick={() =>
                                                set(part.product_id, {
                                                    variantId: v.id,
                                                })
                                            }
                                            className={chip(
                                                picks[part.product_id]
                                                    ?.variantId === v.id,
                                            )}
                                        >
                                            <span>{v.name}</span>
                                            {!v.is_available && (
                                                <span className="text-xs">
                                                    Habis
                                                </span>
                                            )}
                                        </button>
                                    ))}
                                </div>
                            </fieldset>
                        )}
                        {part.modifier_groups.map((g) => (
                            <fieldset key={g.id} className="grid gap-2">
                                <legend className="mb-1 text-sm font-medium">
                                    {g.name}{' '}
                                    <span className="font-normal text-muted-foreground">
                                        · {g.required ? 'wajib' : 'opsional'}
                                        {g.max > 1 ? `, maks ${g.max}` : ''}
                                    </span>
                                </legend>
                                <div className="grid grid-cols-2 gap-2">
                                    {g.modifiers.map((m) => {
                                        const on =
                                            picks[part.product_id]?.picked[
                                                g.id
                                            ]?.includes(m.id) ?? false;

                                        return (
                                            <button
                                                key={m.id}
                                                type="button"
                                                disabled={!m.is_available}
                                                aria-pressed={on}
                                                onClick={() =>
                                                    toggle(
                                                        part.product_id,
                                                        g.id,
                                                        m.id,
                                                        g.max,
                                                    )
                                                }
                                                className={chip(on)}
                                            >
                                                <span>{m.name}</span>
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {!m.is_available
                                                        ? 'Habis'
                                                        : m.price_delta
                                                          ? `+${rupiah(m.price_delta)}`
                                                          : ''}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </fieldset>
                        ))}
                    </section>
                ))}
        </div>
    );
}
