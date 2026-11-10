import { useState } from 'react';
import { toast } from 'sonner';
import { InfoTip } from '@/components/pos/info-tip';
import { Modal } from '@/components/pos/modal';
import { Field, MoneyInput } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api, ApiError } from '@/lib/api';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

export type NewIngredient = {
    id: number;
    name: string;
    unit: string;
    choices: { name: string; factor: number }[];
};

const UNITS = [
    { key: 'g', label: 'Gram', hint: 'daging, bumbu, tepung' },
    { key: 'ml', label: 'Mililiter', hint: 'minyak, susu, sirup' },
    { key: 'pcs', label: 'Buah', hint: 'telur, daun, bungkus' },
] as const;

const num = (n: number) =>
    n.toLocaleString('id-ID', { maximumFractionDigits: 4 });

/**
 * One short form to add an ingredient from wherever it is needed (stock list, purchase, recipe): its name, what it is
 * counted in, how it is bought, and what it costs. Prices and stock typed per package are turned into the counting unit here.
 */
export function NewIngredientDialog({
    name: initial = '',
    onClose,
    onCreated,
}: {
    name?: string;
    onClose: () => void;
    onCreated: (ingredient: NewIngredient) => void;
}) {
    const [name, setName] = useState(initial);
    const [unit, setUnit] = useState<'g' | 'ml' | 'pcs'>('g');
    const [packed, setPacked] = useState(false);
    const [pack, setPack] = useState('');
    const [size, setSize] = useState<number | ''>('');
    const [price, setPrice] = useState<number | ''>('');
    const [per, setPer] = useState<'pack' | 'unit'>('pack');
    const [stock, setStock] = useState<number | ''>('');
    const [low, setLow] = useState<number | ''>('');
    const [busy, setBusy] = useState(false);

    const factor = packed && Number(size) > 0 ? Number(size) : 1;
    const usePack = packed && factor > 1;
    const unitCost =
        price === '' ? 0 : per === 'pack' && usePack ? price / factor : price;
    // stock is counted in packages when the ingredient is bought in them
    const stockBase = stock === '' ? 0 : usePack ? stock * factor : stock;
    const ready =
        name.trim() !== '' && (!packed || (pack.trim() !== '' && factor > 1));

    const save = async () => {
        setBusy(true);

        try {
            const res = await api<{ ingredient: NewIngredient }>(
                'POST',
                '/admin/inventory/ingredients/quick',
                {
                    name: name.trim(),
                    unit,
                    min_stock: low === '' ? 0 : low,
                    stock: stockBase > 0 ? stockBase : null,
                    unit_cost: unitCost > 0 ? unitCost : null,
                    packs: usePack ? [{ name: pack.trim(), factor }] : [],
                },
            );
            toast.success(`Bahan ${res.ingredient.name} ditambahkan.`);
            onCreated(res.ingredient);
            onClose();
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : 'Gagal menyimpan.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal open onClose={onClose} title="Bahan baru">
            <Field label="Nama bahan">
                <Input
                    className="h-11"
                    autoFocus
                    value={name}
                    maxLength={80}
                    placeholder="mis. Ayam, Lengkuas, Susu UHT"
                    onChange={(e) => setName(e.target.value)}
                />
            </Field>

            <div className="grid gap-2">
                <p className="flex items-center gap-1.5 text-sm font-medium">
                    Dihitung dalam satuan apa?
                    <InfoTip label="Info satuan">
                        Pilih satuan terkecil yang dipakai di resep. Resep dan
                        stok selalu memakai satuan ini, sedangkan belanja boleh
                        memakai kemasan (ekor, botol, karung) yang nanti diubah
                        otomatis.
                    </InfoTip>
                </p>
                <div className="grid grid-cols-3 gap-2">
                    {UNITS.map((u) => (
                        <button
                            key={u.key}
                            type="button"
                            aria-pressed={unit === u.key}
                            onClick={() => setUnit(u.key)}
                            className={cn(
                                'grid h-16 place-content-center rounded-xl border px-2 text-center transition-colors',
                                unit === u.key
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'bg-card',
                            )}
                        >
                            <span className="font-semibold">{u.label}</span>
                            <span className="text-[11px] opacity-80">
                                {u.hint}
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="grid gap-2 rounded-xl border p-3">
                <label className="flex min-h-10 items-center gap-2 text-sm font-medium">
                    <input
                        type="checkbox"
                        className="size-5"
                        checked={packed}
                        onChange={(e) => {
                            setPacked(e.target.checked);
                            setPer(e.target.checked ? 'pack' : 'unit');
                        }}
                    />
                    Biasa dibeli per kemasan (ekor, botol, karung...)
                </label>
                {packed && (
                    <div className="grid grid-cols-2 gap-2">
                        <Field label="Nama kemasan">
                            <Input
                                className="h-11"
                                value={pack}
                                maxLength={20}
                                placeholder="mis. ekor"
                                onChange={(e) => setPack(e.target.value)}
                            />
                        </Field>
                        <Field label={`Isi 1 ${pack || 'kemasan'} (${unit})`}>
                            <Input
                                className="h-11"
                                type="number"
                                inputMode="decimal"
                                min={0}
                                step="any"
                                value={size}
                                placeholder="mis. 700"
                                onChange={(e) =>
                                    setSize(
                                        e.target.value === ''
                                            ? ''
                                            : Number(e.target.value),
                                    )
                                }
                            />
                        </Field>
                    </div>
                )}
            </div>

            <div className="grid gap-2">
                <Field label="Harga beli (opsional)">
                    <div className="grid grid-cols-[1fr_auto] gap-2">
                        <MoneyInput value={price} onChange={setPrice} />
                        {usePack ? (
                            <div
                                role="radiogroup"
                                aria-label="Harga per"
                                className="flex gap-1"
                            >
                                {(
                                    [
                                        ['pack', `per ${pack}`],
                                        ['unit', `per ${unit}`],
                                    ] as const
                                ).map(([k, l]) => (
                                    <Button
                                        key={k}
                                        type="button"
                                        role="radio"
                                        aria-checked={per === k}
                                        variant={
                                            per === k ? 'default' : 'outline'
                                        }
                                        className="h-11 px-3"
                                        onClick={() => setPer(k)}
                                    >
                                        {l}
                                    </Button>
                                ))}
                            </div>
                        ) : (
                            <span className="grid h-11 place-content-center text-sm text-muted-foreground">
                                per {unit}
                            </span>
                        )}
                    </div>
                </Field>
                {unitCost > 0 && (
                    <p className="text-sm text-muted-foreground">
                        = {rupiah(Math.round(unitCost * 100) / 100)} per {unit}.
                        Dipakai untuk menghitung HPP resep.
                    </p>
                )}
            </div>

            <div className="grid grid-cols-2 gap-2">
                <Field
                    label={`Stok sekarang (${usePack ? pack : unit})`}
                    hint="Opsional. Kosongkan kalau belum dihitung."
                >
                    <Input
                        className="h-11"
                        type="number"
                        inputMode="decimal"
                        min={0}
                        step="any"
                        value={stock}
                        onChange={(e) =>
                            setStock(
                                e.target.value === ''
                                    ? ''
                                    : Number(e.target.value),
                            )
                        }
                    />
                </Field>
                <Field
                    label={`Ingatkan bila tinggal (${unit})`}
                    hint="Opsional."
                >
                    <Input
                        className="h-11"
                        type="number"
                        inputMode="decimal"
                        min={0}
                        step="any"
                        value={low}
                        onChange={(e) =>
                            setLow(
                                e.target.value === ''
                                    ? ''
                                    : Number(e.target.value),
                            )
                        }
                    />
                </Field>
            </div>
            {stockBase > 0 && usePack && (
                <p className="-mt-1 text-sm text-muted-foreground">
                    = {num(stockBase)} {unit}
                </p>
            )}

            <Button
                className="h-12"
                disabled={!ready || busy}
                onClick={() => void save()}
            >
                {busy ? 'Menyimpan…' : 'Simpan bahan'}
            </Button>
        </Modal>
    );
}
