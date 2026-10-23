import { Plus, Trash2 } from 'lucide-react';
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
 * Purchase lines as a table. The unit of each line can only be one defined for that ingredient, so nobody
 * enters "1" meaning a bottle when the recipe works in ml: the conversion is shown under the quantity.
 */
export function PurchaseLines({
    ingredients,
    lines,
    onChange,
}: {
    ingredients: PIngredient[];
    lines: PLine[];
    onChange: (lines: PLine[]) => void;
}) {
    const find = (id: number | '') => ingredients.find((i) => i.id === id);
    const patch = (idx: number, p: Partial<PLine>) =>
        onChange(lines.map((l, i) => (i === idx ? { ...l, ...p } : l)));
    const total = lines.reduce((s, l) => s + Number(l.total || 0), 0);

    return (
        <div className="overflow-x-auto rounded-2xl border bg-card">
            <table className="w-full text-sm">
                <thead className="bg-muted/60 text-left">
                    <tr>
                        <th className="px-3 py-2 font-medium">Bahan</th>
                        <th className="w-28 px-3 py-2 font-medium">Jumlah</th>
                        <th className="w-32 px-3 py-2 font-medium">Satuan</th>
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
                                                              e.target.value,
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
                                    {ing &&
                                        factor !== 1 &&
                                        Number(l.qty) > 0 && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                = {num(Number(l.qty) * factor)}{' '}
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
    );
}
