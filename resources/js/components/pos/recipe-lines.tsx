import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { NewIngredientDialog } from '@/components/pos/new-ingredient';
import { InfoTip } from '@/components/pos/info-tip';
import { Combobox, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { router } from '@inertiajs/react';

export type RecipeIngredient = {
    id: number;
    name: string;
    unit: string;
    avg_cost: number;
};

export type RecipeLine = {
    variant_id?: number | null;
    ingredient_id: number | '';
    qty: number | '';
};

export type RecipeVariant = {
    id: number;
    name: string;
    price: number;
    cost?: number;
};

export const blankRecipeLine = (): RecipeLine => ({
    variant_id: null,
    ingredient_id: '',
    qty: '',
});

/** Sum of qty × avg_cost for lines that apply to this variant (or all). */
export function recipeCost(
    lines: RecipeLine[],
    ingredients: RecipeIngredient[],
    variantId: number | null,
): number {
    const ing = (id: number | '') => ingredients.find((i) => i.id === id);

    return lines
        .filter(
            (l) =>
                l.ingredient_id !== '' &&
                (l.variant_id == null || l.variant_id === variantId),
        )
        .reduce(
            (s, l) =>
                s +
                Number(l.qty || 0) * (ing(l.ingredient_id)?.avg_cost ?? 0),
            0,
        );
}

export function recipePayload(lines: RecipeLine[], withVariant: boolean) {
    return lines
        .filter((l) => l.ingredient_id !== '' && Number(l.qty) > 0)
        .map((l) => ({
            ingredient_id: l.ingredient_id,
            qty: l.qty,
            ...(withVariant ? { variant_id: l.variant_id ?? null } : {}),
        }));
}

/** Editable list of ingredients for one portion — focus on name + qty; cost is a hint. */
export function RecipeLines({
    lines,
    onChange,
    ingredients,
    variants = [],
    showVariants = false,
}: {
    lines: RecipeLine[];
    onChange: (lines: RecipeLine[]) => void;
    ingredients: RecipeIngredient[];
    variants?: RecipeVariant[];
    showVariants?: boolean;
}) {
    const [creating, setCreating] = useState<{
        idx: number;
        name: string;
    } | null>(null);
    const ing = (id: number | '') => ingredients.find((i) => i.id === id);
    const withVariants = showVariants && variants.length > 0;

    const setLine = (idx: number, patch: Partial<RecipeLine>) =>
        onChange(lines.map((x, j) => (j === idx ? { ...x, ...patch } : x)));

    return (
        <div className="grid gap-3">
            {withVariants && (
                <p className="flex items-center gap-1.5 text-sm text-muted-foreground">
                    Menu ini punya varian
                    <InfoTip label="Info varian di resep">
                        Baris dengan varian tertentu hanya dipakai saat varian
                        itu dipesan. Pilih Semua untuk bahan yang dipakai di
                        semua varian.
                    </InfoTip>
                </p>
            )}
            {lines.map((l, idx) => (
                <div key={idx} className="grid gap-1">
                    <div
                        className={
                            withVariants
                                ? 'grid grid-cols-[1fr_6.5rem_2.75rem] items-center gap-2 sm:grid-cols-[7rem_1fr_6.5rem_2.75rem]'
                                : 'grid grid-cols-[1fr_6.5rem_2.75rem] items-center gap-2'
                        }
                    >
                        {withVariants && (
                            <div className="col-span-3 sm:col-span-1">
                                <Select
                                    aria-label="Varian"
                                    value={l.variant_id ?? ''}
                                    onChange={(e) =>
                                        setLine(idx, {
                                            variant_id: e.target.value
                                                ? Number(e.target.value)
                                                : null,
                                        })
                                    }
                                >
                                    <option value="">Semua</option>
                                    {variants.map((v) => (
                                        <option key={v.id} value={v.id}>
                                            {v.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        )}
                        <Combobox
                            aria-label="Bahan"
                            placeholder="Cari bahan…"
                            onCreate={(name) => setCreating({ idx, name })}
                            createLabel="Buat bahan baru"
                            options={ingredients.map((i) => ({
                                value: i.id,
                                label: i.name,
                                hint: i.unit,
                            }))}
                            value={l.ingredient_id}
                            onChange={(v) =>
                                setLine(idx, {
                                    ingredient_id: v === '' ? '' : Number(v),
                                })
                            }
                        />
                        <div className="relative">
                            <Input
                                className="h-11 pr-12"
                                aria-label="Jumlah"
                                placeholder={
                                    ing(l.ingredient_id) ? 'Jumlah' : 'Pilih bahan'
                                }
                                type="number"
                                min={0}
                                step="any"
                                value={l.qty}
                                onChange={(e) =>
                                    setLine(idx, {
                                        qty:
                                            e.target.value === ''
                                                ? ''
                                                : Number(e.target.value),
                                    })
                                }
                            />
                            <span
                                aria-hidden
                                className="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-sm font-medium text-muted-foreground"
                            >
                                {ing(l.ingredient_id)?.unit ?? ''}
                            </span>
                        </div>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-11"
                            aria-label="Hapus baris"
                            onClick={() =>
                                onChange(lines.filter((_, j) => j !== idx))
                            }
                        >
                            <Trash2 />
                        </Button>
                    </div>
                    {ing(l.ingredient_id) && Number(l.qty) > 0 && (
                        <p className="pl-1 text-xs text-muted-foreground tabular-nums">
                            {l.qty} {ing(l.ingredient_id)?.unit} ×{' '}
                            {rupiah(
                                Math.round(
                                    (ing(l.ingredient_id)?.avg_cost ?? 0) * 100,
                                ) / 100,
                            )}
                            /{ing(l.ingredient_id)?.unit} ={' '}
                            <span className="font-medium text-foreground">
                                {rupiah(
                                    Math.round(
                                        Number(l.qty) *
                                            (ing(l.ingredient_id)?.avg_cost ??
                                                0),
                                    ),
                                )}
                            </span>
                            {(ing(l.ingredient_id)?.avg_cost ?? 0) === 0 &&
                                ' · harga bahan belum diisi'}
                        </p>
                    )}
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                className="h-10"
                onClick={() => onChange([...lines, blankRecipeLine()])}
            >
                <Plus /> Tambah bahan
            </Button>
            {creating && (
                <NewIngredientDialog
                    name={creating.name}
                    onClose={() => setCreating(null)}
                    onCreated={(i) => {
                        router.reload({ only: ['ingredients'] });
                        setLine(creating.idx, { ingredient_id: i.id });
                        setCreating(null);
                    }}
                />
            )}
        </div>
    );
}
