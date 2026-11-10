import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { NewIngredientDialog } from '@/components/pos/new-ingredient';
import { Combobox, MoneyInput, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';

export type PIngredient = {
    id: number;
    name: string;
    unit: string;
    choices: { name: string; factor: number }[];
};
export type PLine = {
    ingredient_id: number | '';
    qty: number | '';
    unit: string;
    total: number | '';
};

export const blankLine = (): PLine => ({
    ingredient_id: '',
    qty: '',
    unit: '',
    total: '',
});

export const readyLines = (lines: PLine[]) =>
    lines.filter(
        (l) => l.ingredient_id !== '' && Number(l.qty) > 0 && l.total !== '',
    );

export const purchaseItems = (lines: PLine[]) =>
    readyLines(lines).map((l) => ({
        ingredient_id: l.ingredient_id,
        qty: l.qty,
        unit: l.unit || null,
        total: l.total,
    }));

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 3 });

/**
 * Under the price of a line: what this ingredient cost last time per the chosen unit, and a warning when the price
 * typed now is well above it (the percent is the store's price alert setting).
 */
function PriceHint({
    line,
    ing,
    lastPrices,
    alertPercent,
}: {
    line: PLine;
    ing: PIngredient | undefined;
    lastPrices?: Record<number, number>;
    alertPercent?: number;
}) {
    const base = ing ? lastPrices?.[ing.id] : undefined;

    if (!ing || !base) {
        return null;
    }

    const factor = ing.choices.find((c) => c.name === line.unit)?.factor ?? 1;
    const last = base * factor;
    const qty = Number(line.qty);
    const now = qty > 0 && line.total !== '' ? Number(line.total) / qty : null;
    const pct = now !== null ? Math.round((now / last - 1) * 100) : 0;
    const up = now !== null && !!alertPercent && pct >= alertPercent;

    return (
        <p
            className={
                up
                    ? 'mt-1 text-xs font-medium text-amber-800 dark:text-amber-300'
                    : 'mt-1 text-xs text-muted-foreground'
            }
        >
            {up
                ? `Naik ${pct}% dari terakhir (${rupiah(Math.round(last))} per ${line.unit}). Cek lagi harganya.`
                : `Terakhir ${rupiah(Math.round(last))} per ${line.unit}`}
        </p>
    );
}

/**
 * Purchase lines as a table. The unit of each line can only be one defined for that ingredient, so nobody
 * enters "1" meaning a bottle when the recipe works in ml: the conversion is shown under the quantity.
 */
export function PurchaseLines({
    ingredients,
    lines,
    onChange,
    canCreate = false,
    onCreated,
    lastPrices,
    alertPercent,
}: {
    ingredients: PIngredient[];
    lines: PLine[];
    onChange: (lines: PLine[]) => void;
    /** Whoever may add ingredients can do it from the list while typing a purchase. */
    canCreate?: boolean;
    onCreated?: (ingredient: PIngredient) => void;
    /** Last price per base unit by ingredient id, to show a hint and flag a jump. */
    lastPrices?: Record<number, number>;
    alertPercent?: number;
}) {
    const [creating, setCreating] = useState<{
        idx: number;
        name: string;
    } | null>(null);
    const find = (id: number | '') => ingredients.find((i) => i.id === id);
    const patch = (idx: number, p: Partial<PLine>) =>
        onChange(lines.map((l, i) => (i === idx ? { ...l, ...p } : l)));
    const total = lines.reduce((s, l) => s + Number(l.total || 0), 0);

    const cards = (
        <div className="grid gap-3 md:hidden">
            {lines.map((l, idx) => {
                const ing = find(l.ingredient_id);
                const factor =
                    ing?.choices.find((c) => c.name === l.unit)?.factor ?? 1;

                return (
                    <div
                        key={idx}
                        className="grid gap-3 rounded-2xl border bg-card p-3"
                    >
                        <div className="flex items-center justify-between">
                            <span className="text-sm font-medium text-muted-foreground">
                                Bahan {idx + 1}
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-10"
                                aria-label={`Hapus bahan ${idx + 1}`}
                                disabled={lines.length === 1}
                                onClick={() =>
                                    onChange(lines.filter((_, i) => i !== idx))
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                        <Combobox
                            aria-label={`Bahan ${idx + 1}`}
                            placeholder="Cari bahan…"
                            onCreate={
                                canCreate
                                    ? (name) => setCreating({ idx, name })
                                    : undefined
                            }
                            createLabel="Buat bahan baru"
                            options={ingredients.map((i) => ({
                                value: i.id,
                                label: i.name,
                                hint: i.unit,
                            }))}
                            value={l.ingredient_id}
                            onChange={(v) => {
                                const id = v === '' ? '' : Number(v);
                                patch(idx, {
                                    ingredient_id: id,
                                    unit: find(id)?.unit ?? '',
                                });
                            }}
                        />
                        <div className="grid grid-cols-2 gap-2">
                            <div className="grid gap-1">
                                <span className="text-xs text-muted-foreground">
                                    Jumlah
                                </span>
                                <Input
                                    className="h-11"
                                    aria-label={`Jumlah ${idx + 1}`}
                                    type="number"
                                    min={0}
                                    step="any"
                                    inputMode="decimal"
                                    value={l.qty}
                                    onChange={(e) =>
                                        patch(idx, {
                                            qty:
                                                e.target.value === ''
                                                    ? ''
                                                    : Number(e.target.value),
                                        })
                                    }
                                />
                            </div>
                            <div className="grid gap-1">
                                <span className="text-xs text-muted-foreground">
                                    Satuan
                                </span>
                                <Select
                                    aria-label={`Satuan ${idx + 1}`}
                                    value={l.unit}
                                    disabled={!ing}
                                    onChange={(e) =>
                                        patch(idx, { unit: e.target.value })
                                    }
                                >
                                    {!ing && <option value="">-</option>}
                                    {ing?.choices.map((c) => (
                                        <option key={c.name} value={c.name}>
                                            {c.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        </div>
                        {ing && factor !== 1 && Number(l.qty) > 0 && (
                            <p className="-mt-1 text-xs text-muted-foreground">
                                = {num(Number(l.qty) * factor)} {ing.unit}
                            </p>
                        )}
                        <div className="grid gap-1">
                            <span className="text-xs text-muted-foreground">
                                Total harga
                            </span>
                            <MoneyInput
                                value={l.total}
                                onChange={(v) => patch(idx, { total: v })}
                            />
                            <PriceHint
                                line={l}
                                ing={ing}
                                lastPrices={lastPrices}
                                alertPercent={alertPercent}
                            />
                        </div>
                    </div>
                );
            })}
            <div className="flex items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    onClick={() => onChange([...lines, blankLine()])}
                >
                    <Plus /> Tambah baris
                </Button>
                <span className="text-right">
                    <span className="block text-xs text-muted-foreground">
                        Total
                    </span>
                    <span className="font-semibold tabular-nums">
                        {rupiah(total)}
                    </span>
                </span>
            </div>
        </div>
    );

    return (
        <>
            {cards}
            <div className="hidden overflow-x-auto rounded-2xl border bg-card md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/60 text-left">
                        <tr>
                            <th className="px-3 py-2 font-medium">Bahan</th>
                            <th className="w-28 px-3 py-2 font-medium">
                                Jumlah
                            </th>
                            <th className="w-32 px-3 py-2 font-medium">
                                Satuan
                            </th>
                            <th className="w-40 px-3 py-2 font-medium">
                                Total harga
                            </th>
                            <th className="w-12 px-1 py-2" />
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l, idx) => {
                            const ing = find(l.ingredient_id);
                            const factor =
                                ing?.choices.find((c) => c.name === l.unit)
                                    ?.factor ?? 1;

                            return (
                                <tr key={idx} className="border-t align-top">
                                    <td className="min-w-44 p-2">
                                        <Combobox
                                            aria-label="Bahan"
                                            placeholder="Cari bahan…"
                                            onCreate={
                                                canCreate
                                                    ? (name) =>
                                                          setCreating({
                                                              idx,
                                                              name,
                                                          })
                                                    : undefined
                                            }
                                            createLabel="Buat bahan baru"
                                            options={ingredients.map((i) => ({
                                                value: i.id,
                                                label: i.name,
                                                hint: i.unit,
                                            }))}
                                            value={l.ingredient_id}
                                            onChange={(v) => {
                                                const id =
                                                    v === '' ? '' : Number(v);
                                                patch(idx, {
                                                    ingredient_id: id,
                                                    unit: find(id)?.unit ?? '',
                                                });
                                            }}
                                        />
                                    </td>
                                    <td className="p-2">
                                        <Input
                                            className="h-11"
                                            aria-label="Jumlah"
                                            type="number"
                                            min={0}
                                            step="any"
                                            inputMode="decimal"
                                            value={l.qty}
                                            onChange={(e) =>
                                                patch(idx, {
                                                    qty:
                                                        e.target.value === ''
                                                            ? ''
                                                            : Number(
                                                                  e.target
                                                                      .value,
                                                              ),
                                                })
                                            }
                                        />
                                    </td>
                                    <td className="p-2">
                                        <Select
                                            aria-label="Satuan"
                                            value={l.unit}
                                            disabled={!ing}
                                            onChange={(e) =>
                                                patch(idx, {
                                                    unit: e.target.value,
                                                })
                                            }
                                        >
                                            {!ing && (
                                                <option value="">-</option>
                                            )}
                                            {ing?.choices.map((c) => (
                                                <option
                                                    key={c.name}
                                                    value={c.name}
                                                >
                                                    {c.name}
                                                </option>
                                            ))}
                                        </Select>
                                        {ing &&
                                            factor !== 1 &&
                                            Number(l.qty) > 0 && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    ={' '}
                                                    {num(
                                                        Number(l.qty) * factor,
                                                    )}{' '}
                                                    {ing.unit}
                                                </p>
                                            )}
                                    </td>
                                    <td className="p-2">
                                        <MoneyInput
                                            value={l.total}
                                            onChange={(v) =>
                                                patch(idx, { total: v })
                                            }
                                        />
                                        <PriceHint
                                            line={l}
                                            ing={ing}
                                            lastPrices={lastPrices}
                                            alertPercent={alertPercent}
                                        />
                                    </td>
                                    <td className="p-1 text-center">
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-11"
                                            aria-label="Hapus baris"
                                            disabled={lines.length === 1}
                                            onClick={() =>
                                                onChange(
                                                    lines.filter(
                                                        (_, i) => i !== idx,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                    <tfoot>
                        <tr className="border-t bg-muted/40 font-semibold">
                            <td className="px-3 py-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-10"
                                    onClick={() =>
                                        onChange([...lines, blankLine()])
                                    }
                                >
                                    <Plus /> Tambah baris
                                </Button>
                            </td>
                            <td colSpan={2} className="px-3 py-2 text-right">
                                Total
                            </td>
                            <td className="px-3 py-2 tabular-nums">
                                {rupiah(total)}
                            </td>
                            <td />
                        </tr>
                    </tfoot>
                </table>
            </div>
            {creating && (
                <NewIngredientDialog
                    name={creating.name}
                    onClose={() => setCreating(null)}
                    onCreated={(i) => {
                        onCreated?.(i);
                        onChange(
                            lines.map((l, k) =>
                                k === creating.idx
                                    ? {
                                          ...l,
                                          ingredient_id: i.id,
                                          unit: i.unit,
                                      }
                                    : l,
                            ),
                        );
                    }}
                />
            )}
        </>
    );
}
