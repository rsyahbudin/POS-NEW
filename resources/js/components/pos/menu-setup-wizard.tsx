import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ImagePicker } from '@/components/pos/image-picker';
import { Modal } from '@/components/pos/modal';
import { PriceCard } from '@/components/pos/price-card';
import {
    RecipeLines,
    blankRecipeLine,
    recipeCost,
    recipePayload,
    type RecipeIngredient,
    type RecipeLine,
} from '@/components/pos/recipe-lines';
import { Field, Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

type Category = { id: number; name: string };

type WizardStart = {
    /** Resume an existing unpriced product at step 2 or 3. */
    productId?: number;
    name?: string;
    categoryId?: number;
    step?: 1 | 2 | 3;
    lines?: RecipeLine[];
};

const STEPS = [
    { id: 1, label: 'Identitas' },
    { id: 2, label: 'Resep' },
    { id: 3, label: 'Harga' },
] as const;

/** Three-step flow: name/category/photo → recipe → suggested selling price. */
export function MenuSetupWizard({
    open,
    onClose,
    categories,
    ingredients,
    targetFoodCost,
    canSetPrice,
    start,
}: {
    open: boolean;
    onClose: () => void;
    categories: Category[];
    ingredients: RecipeIngredient[];
    targetFoodCost: number;
    canSetPrice: boolean;
    start?: WizardStart | null;
}) {
    const flash = usePage().props.flash as
        | { product_id?: number | null }
        | undefined;
    const [step, setStep] = useState<1 | 2 | 3>(start?.step ?? 1);
    const [productId, setProductId] = useState<number | null>(
        start?.productId ?? null,
    );
    const [name, setName] = useState(start?.name ?? '');
    const [categoryId, setCategoryId] = useState<number | ''>(
        start?.categoryId ?? categories[0]?.id ?? '',
    );
    const [image, setImage] = useState<File | null>(null);
    const [lines, setLines] = useState<RecipeLine[]>([blankRecipeLine()]);
    const [price, setPrice] = useState(0);
    const [busy, setBusy] = useState(false);
    const errors = usePage().props.errors as Record<string, string>;

    useEffect(() => {
        if (!open) {
            return;
        }
        setStep(start?.step ?? 1);
        setProductId(start?.productId ?? null);
        setName(start?.name ?? '');
        setCategoryId(start?.categoryId ?? categories[0]?.id ?? '');
        setImage(null);
        setLines(
            start?.lines && start.lines.length > 0
                ? start.lines.map((l) => ({ ...l }))
                : [blankRecipeLine()],
        );
        setPrice(0);
    }, [open, start, categories]);

    // After step-1 create, continue with the new product id from flash.
    useEffect(() => {
        if (!open || step !== 1 || productId || !flash?.product_id) {
            return;
        }
        setProductId(Number(flash.product_id));
        setStep(2);
    }, [flash?.product_id, open, productId, step]);

    const cost = Math.round(recipeCost(lines, ingredients, null));

    const saveIdentity = () => {
        if (!name.trim() || categoryId === '') {
            return;
        }
        setBusy(true);
        const body: Record<string, unknown> = {
            name: name.trim(),
            category_id: categoryId,
            base_price: 0,
            is_available: 1,
            is_bundle: 0,
            variants: [],
            modifier_group_ids: [],
            printer_ids: [],
            components: [],
            sku: null,
            description: null,
            station_id: null,
            stock_qty: null,
        };
        if (image) {
            body.image = image;
        }
        router.post(
            productId ? `/admin/products/${productId}` : '/admin/products',
            body as Record<string, string | number | boolean | File | null>,
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => setBusy(false),
                onSuccess: (page) => {
                    const id = (
                        page.props.flash as { product_id?: number } | undefined
                    )?.product_id;
                    if (id) {
                        setProductId(Number(id));
                    }
                    setStep(2);
                },
            },
        );
    };

    const saveRecipe = (then: 'price' | 'done') => {
        if (!productId) {
            return;
        }
        setBusy(true);
        router.post(
            `/admin/recipes/products/${productId}`,
            { lines: recipePayload(lines, true) },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    if (then === 'price') {
                        setStep(3);
                    } else {
                        onClose();
                    }
                },
            },
        );
    };

    const applyPrice = (rec: number) => {
        if (!productId) {
            return;
        }
        setBusy(true);
        router.post(
            `/admin/recipes/products/${productId}/price`,
            { price: rec },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
                onSuccess: () => {
                    setPrice(rec);
                    onClose();
                },
            },
        );
    };

    return (
        <Modal
            open={open}
            onClose={onClose}
            title="Siapkan menu"
            description={
                step === 1
                    ? 'Nama, kategori, dan foto. Harga diisi setelah resep.'
                    : step === 2
                      ? 'Bahan untuk 1 porsi. Boleh dilewati dan diisi nanti.'
                      : 'Pakai harga yang disarankan agar menu mulai dijual.'
            }
            className="sm:max-w-xl"
        >
            <ol className="flex items-center gap-2" aria-label="Langkah">
                {STEPS.map((s) => (
                    <li key={s.id} className="flex flex-1 items-center gap-2">
                        <span
                            className={cn(
                                'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-semibold',
                                step === s.id
                                    ? 'bg-primary text-primary-foreground'
                                    : step > s.id
                                      ? 'bg-primary/20 text-primary'
                                      : 'bg-muted text-muted-foreground',
                            )}
                        >
                            {s.id}
                        </span>
                        <span
                            className={cn(
                                'hidden text-sm sm:inline',
                                step === s.id
                                    ? 'font-semibold'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {s.label}
                        </span>
                        {s.id < 3 && (
                            <span
                                aria-hidden
                                className="h-px flex-1 bg-border"
                            />
                        )}
                    </li>
                ))}
            </ol>

            {Object.values(errors ?? {})[0] && (
                <p role="alert" className="text-sm text-destructive">
                    {Object.values(errors)[0]}
                </p>
            )}

            {step === 1 && (
                <>
                    <Field label="Nama" error={errors?.name}>
                        <Input
                            className="h-11"
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            autoFocus
                        />
                    </Field>
                    <Field label="Kategori">
                        <Select
                            value={categoryId}
                            onChange={(e) =>
                                setCategoryId(Number(e.target.value))
                            }
                        >
                            {categories.map((c) => (
                                <option key={c.id} value={c.id}>
                                    {c.name}
                                </option>
                            ))}
                        </Select>
                    </Field>
                    <Field label="Foto menu">
                        <ImagePicker
                            file={image}
                            recommend={{ w: 800, h: 800 }}
                            previewClass="max-w-40"
                            note="Opsional. Otomatis diubah ke WebP, sisi terpanjang maks 800 px."
                            onChange={setImage}
                        />
                    </Field>
                    <Button
                        className="h-12"
                        disabled={busy || !name.trim() || categoryId === ''}
                        onClick={saveIdentity}
                    >
                        Lanjut ke resep
                    </Button>
                </>
            )}

            {step === 2 && productId && (
                <>
                    <RecipeLines
                        lines={lines}
                        onChange={setLines}
                        ingredients={ingredients}
                    />
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            className="h-12 flex-1"
                            disabled={busy}
                            onClick={() => setStep(1)}
                        >
                            Kembali
                        </Button>
                        <Button
                            variant="outline"
                            className="h-12 flex-1"
                            disabled={busy}
                            onClick={() => setStep(3)}
                        >
                            Lewati
                        </Button>
                        <Button
                            className="h-12 flex-1"
                            disabled={busy}
                            onClick={() => saveRecipe('price')}
                        >
                            Simpan & lanjut
                        </Button>
                    </div>
                </>
            )}

            {step === 3 && productId && (
                <>
                    <PriceCard
                        price={price}
                        cost={cost}
                        target={targetFoodCost}
                        onApply={
                            canSetPrice && cost > 0
                                ? (rec) => applyPrice(rec)
                                : undefined
                        }
                    />
                    {!canSetPrice && (
                        <p className="text-sm text-muted-foreground">
                            Anda belum punya izin mengatur harga. Tutup saja;
                            harga bisa diisi nanti di Resep & HPP.
                        </p>
                    )}
                    {cost <= 0 && (
                        <p className="text-sm text-muted-foreground">
                            Belum ada HPP. Kembali isi resep, atau tutup dan
                            atur harga manual di menu lengkap.
                        </p>
                    )}
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            className="h-12 flex-1"
                            disabled={busy}
                            onClick={() => setStep(2)}
                        >
                            Kembali
                        </Button>
                        <Button
                            variant="outline"
                            className="h-12 flex-1"
                            disabled={busy}
                            onClick={onClose}
                        >
                            Selesai nanti
                        </Button>
                    </div>
                </>
            )}
        </Modal>
    );
}
