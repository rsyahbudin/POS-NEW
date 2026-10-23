import { useMemo, useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { TextArea } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import { rupiah, uuid } from '@/lib/format';
import type { CartLine, Product } from '@/types/pos';

/** Variant + modifier picker. Rules are mirrored here for UX; the server enforces them again. */
export function ModifierSheet({
    product,
    onClose,
    onAdd,
}: {
    product: Product | null;
    onClose: () => void;
    onAdd: (line: CartLine) => void;
}) {
    return (
        <Modal
            open={product !== null}
            onClose={onClose}
            title={product?.name ?? ''}
            description={product?.description ?? undefined}
        >
            {product && (
                <Body
                    key={product.id}
                    product={product}
                    onAdd={(l) => {
                        onAdd(l);
                        onClose();
                    }}
                />
            )}
        </Modal>
    );
}

function Body({
    product,
    onAdd,
}: {
    product: Product;
    onAdd: (line: CartLine) => void;
}) {
    const [variantId, setVariantId] = useState<number | null>(
        product.variants[0]?.id ?? null,
    );
    const [picked, setPicked] = useState<Record<number, number[]>>({});
    const [qty, setQty] = useState(1);
    const [note, setNote] = useState('');

    const unit = useMemo(() => {
        const base =
            product.variants.find((v) => v.id === variantId)?.price ??
            product.price;
        const extra = product.modifier_groups
            .flatMap((g) =>
                g.modifiers.filter((m) => picked[g.id]?.includes(m.id)),
            )
            .reduce((s, m) => s + m.price_delta, 0);

        return base + extra;
    }, [product, variantId, picked]);

    const problems = product.modifier_groups
        .filter((g) => (picked[g.id]?.length ?? 0) < g.min)
        .map((g) => t('Pilih minimal {n} untuk {g}', { n: g.min, g: g.name }));

    const toggle = (groupId: number, id: number, max: number) =>
        setPicked((p) => {
            const cur = p[groupId] ?? [];

            if (cur.includes(id)) {
                return { ...p, [groupId]: cur.filter((x) => x !== id) };
            }

            return {
                ...p,
                [groupId]:
                    max === 1 ? [id] : cur.length < max ? [...cur, id] : cur,
            };
        });

    const submit = () => {
        const modifierIds = Object.values(picked).flat();
        const names = product.modifier_groups.flatMap((g) =>
            g.modifiers
                .filter((m) => modifierIds.includes(m.id))
                .map((m) => m.name),
        );
        const v = product.variants.find((x) => x.id === variantId);

        onAdd({
            key: uuid(),
            product,
            variantId,
            modifierIds,
            qty,
            note: note.trim(),
            unitPrice: unit,
            label: [
                product.name,
                v ? `(${v.name})` : '',
                names.length ? `· ${names.join(', ')}` : '',
            ]
                .filter(Boolean)
                .join(' '),
        });
    };

    return (
        <div className="grid gap-5">
            {product.variants.length > 0 && (
                <fieldset className="grid gap-2">
                    <legend className="mb-1 text-sm font-medium">
                        {t('Varian')}
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {product.variants.map((v) => (
                            <Button
                                key={v.id}
                                type="button"
                                variant={
                                    variantId === v.id ? 'default' : 'outline'
                                }
                                className="h-12 justify-between"
                                onClick={() => setVariantId(v.id)}
                                aria-pressed={variantId === v.id}
                            >
                                <span>{v.name}</span>
                                <span className="tabular-nums">
                                    {rupiah(v.price)}
                                </span>
                            </Button>
                        ))}
                    </div>
                </fieldset>
            )}

            {product.modifier_groups.map((g) => (
                <fieldset key={g.id} className="grid gap-2">
                    <legend className="mb-1 text-sm font-medium">
                        {g.name}{' '}
                        <span className="font-normal text-muted-foreground">
                            · {g.required ? t('wajib') : t('opsional')} ·{' '}
                            {g.min === g.max
                                ? `pilih ${g.max}`
                                : `${g.min}-${g.max}`}
                        </span>
                    </legend>
                    <div className="grid grid-cols-2 gap-2">
                        {g.modifiers.map((m) => {
                            const on = picked[g.id]?.includes(m.id) ?? false;

                            return (
                                <Button
                                    key={m.id}
                                    type="button"
                                    variant={on ? 'default' : 'outline'}
                                    className="h-12 justify-between"
                                    onClick={() => toggle(g.id, m.id, g.max)}
                                    aria-pressed={on}
                                >
                                    <span>{m.name}</span>
                                    {m.price_delta !== 0 && (
                                        <span className="tabular-nums">
                                            +{rupiah(m.price_delta)}
                                        </span>
                                    )}
                                </Button>
                            );
                        })}
                    </div>
                </fieldset>
            ))}

            <TextArea
                placeholder={t('Catatan (opsional)')}
                value={note}
                maxLength={200}
                onChange={(e) => setNote(e.target.value)}
                aria-label={t('Catatan')}
            />

            <div className="sticky -bottom-6 z-10 -mx-6 -mb-6 grid gap-2 border-t bg-background px-6 pt-3 pb-4 max-sm:-bottom-6">
                {problems.length > 0 && (
                    <p className="text-xs text-destructive">{problems[0]}</p>
                )}
                <div className="flex items-center justify-between gap-3">
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-12"
                            aria-label={t('Kurangi')}
                            onClick={() => setQty((q) => Math.max(1, q - 1))}
                        >
                            −
                        </Button>
                        <span
                            className="w-9 text-center text-lg font-semibold tabular-nums"
                            aria-live="polite"
                        >
                            {qty}
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-12"
                            aria-label={t('Tambah')}
                            onClick={() => setQty((q) => Math.min(99, q + 1))}
                        >
                            +
                        </Button>
                    </div>
                    <Button
                        type="button"
                        className="h-12 flex-1 text-base"
                        disabled={problems.length > 0}
                        onClick={submit}
                    >
                        {t('Tambah')} · {rupiah(unit * qty)}
                    </Button>
                </div>
            </div>
        </div>
    );
}
