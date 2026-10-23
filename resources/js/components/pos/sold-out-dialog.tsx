import { ChevronDown } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { t } from '@/lang';
import { api, ApiError } from '@/lib/api';
import { cn } from '@/lib/utils';
import type { Category, Product } from '@/types/pos';

/** Cashier tool: mark a menu item (or one option such as a sambal) sold out, or correct its stock. */
export function SoldOutDialog({
    menu,
    onClose,
    onChanged,
}: {
    menu: Category[];
    onClose: () => void;
    onChanged: () => void;
}) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState<number | null>(null);
    const [busy, setBusy] = useState(false);

    const rows = useMemo(() => {
        const needle = q.trim().toLowerCase();

        return menu.flatMap((c) =>
            c.products
                .filter((p) => !needle || p.name.toLowerCase().includes(needle))
                .map((p) => ({ p, category: c.name })),
        );
    }, [menu, q]);

    const call = async (url: string, body: Record<string, unknown>) => {
        setBusy(true);

        try {
            await api('POST', url, body);
            onChanged();
        } catch (e) {
            toast.error(e instanceof ApiError ? e.message : t('Gagal.'));
        } finally {
            setBusy(false);
        }
    };

    return (
        <Modal
            open
            onClose={onClose}
            title={t('Stok & menu habis')}
            description={t(
                'Tandai menu atau pilihan yang habis. Pelanggan tetap melihatnya, tapi tidak bisa memesan.',
            )}
            className="sm:max-w-xl"
        >
            <input
                type="search"
                value={q}
                onChange={(e) => setQ(e.target.value)}
                placeholder={t('Cari menu')}
                aria-label={t('Cari menu')}
                className="h-11 w-full rounded-lg border bg-background px-3 text-sm"
            />
            <ul className="grid max-h-[60dvh] gap-2 overflow-y-auto">
                {rows.length === 0 && (
                    <li className="py-6 text-center text-sm text-muted-foreground">
                        {t('Menu tidak ditemukan.')}
                    </li>
                )}
                {rows.map(({ p, category }) => (
                    <Row
                        key={p.id}
                        p={p}
                        category={category}
                        busy={busy}
                        expanded={open === p.id}
                        onToggle={() => setOpen(open === p.id ? null : p.id)}
                        call={call}
                    />
                ))}
            </ul>
        </Modal>
    );
}

function Row({
    p,
    category,
    busy,
    expanded,
    onToggle,
    call,
}: {
    p: Product;
    category: string;
    busy: boolean;
    expanded: boolean;
    onToggle: () => void;
    call: (url: string, body: Record<string, unknown>) => Promise<void>;
}) {
    const options = p.variants.length + p.modifier_groups.length;
    const soldOptions =
        p.variants.filter((v) => !v.is_available).length +
        p.modifier_groups.flatMap((g) =>
            g.modifiers.filter((m) => !m.is_available),
        ).length;
    const [stock, setStock] = useState(String(p.stock_qty ?? ''));

    const setQty = (n: number) =>
        void call(`/pos/products/${p.id}/stock`, {
            stock_qty: Math.max(0, n),
        });

    return (
        <li className="rounded-xl border bg-card">
            <div className="flex flex-wrap items-center gap-2 p-3">
                <div className="min-w-0 flex-1">
                    <p
                        className={cn(
                            'truncate text-sm font-medium',
                            !p.is_available && 'text-muted-foreground',
                        )}
                    >
                        {p.name}
                    </p>
                    <p className="truncate text-xs text-muted-foreground">
                        {category}
                        {soldOptions > 0 &&
                            ` · ${soldOptions} ${t('pilihan habis')}`}
                    </p>
                </div>
                {p.stock_qty !== null && (
                    <div className="flex items-center gap-1">
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-10"
                            aria-label={t('Kurangi stok')}
                            disabled={busy || p.stock_qty <= 0}
                            onClick={() => setQty((p.stock_qty ?? 0) - 1)}
                        >
                            −
                        </Button>
                        <input
                            inputMode="numeric"
                            aria-label={`${t('Stok')} ${p.name}`}
                            className="h-10 w-14 rounded-lg border bg-background text-center text-sm tabular-nums"
                            value={stock}
                            onChange={(e) =>
                                setStock(e.target.value.replace(/\D/g, ''))
                            }
                            onBlur={() => {
                                if (
                                    stock !== '' &&
                                    Number(stock) !== p.stock_qty
                                ) {
                                    setQty(Number(stock));
                                }
                            }}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            className="size-10"
                            aria-label={t('Tambah stok')}
                            disabled={busy}
                            onClick={() => setQty((p.stock_qty ?? 0) + 1)}
                        >
                            +
                        </Button>
                    </div>
                )}
                <Button
                    type="button"
                    size="sm"
                    variant={p.is_available ? 'outline' : 'destructive'}
                    className="h-10"
                    disabled={busy}
                    aria-pressed={!p.is_available}
                    onClick={() =>
                        void call(`/pos/products/${p.id}/availability`, {
                            is_available: !p.is_available,
                        })
                    }
                >
                    {p.is_available ? t('Tandai habis') : t('Habis · aktifkan')}
                </Button>
                {options > 0 && (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-10"
                        aria-expanded={expanded}
                        aria-label={`${t('Pilihan')} ${p.name}`}
                        onClick={onToggle}
                    >
                        <ChevronDown
                            className={cn(
                                'transition',
                                expanded && 'rotate-180',
                            )}
                        />
                    </Button>
                )}
            </div>
            {expanded && (
                <div className="grid gap-3 border-t p-3">
                    {p.variants.length > 0 && (
                        <OptionList
                            title={t('Varian')}
                            busy={busy}
                            items={p.variants.map((v) => ({
                                id: v.id,
                                name: v.name,
                                on: v.is_available,
                                url: `/pos/variants/${v.id}/availability`,
                            }))}
                            call={call}
                        />
                    )}
                    {p.modifier_groups.map((g) => (
                        <OptionList
                            key={g.id}
                            title={g.name}
                            busy={busy}
                            items={g.modifiers.map((m) => ({
                                id: m.id,
                                name: m.name,
                                on: m.is_available,
                                url: `/pos/modifiers/${m.id}/availability`,
                            }))}
                            call={call}
                        />
                    ))}
                    {p.modifier_groups.length > 0 && (
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Pilihan berlaku untuk semua menu yang memakainya.',
                            )}
                        </p>
                    )}
                </div>
            )}
        </li>
    );
}

function OptionList({
    title,
    items,
    busy,
    call,
}: {
    title: string;
    items: { id: number; name: string; on: boolean; url: string }[];
    busy: boolean;
    call: (url: string, body: Record<string, unknown>) => Promise<void>;
}) {
    return (
        <div className="grid gap-1.5">
            <p className="text-xs font-medium text-muted-foreground">{title}</p>
            <div className="flex flex-wrap gap-2">
                {items.map((i) => (
                    <Button
                        key={i.id}
                        type="button"
                        size="sm"
                        variant={i.on ? 'outline' : 'destructive'}
                        className="h-10"
                        disabled={busy}
                        aria-pressed={!i.on}
                        onClick={() =>
                            void call(i.url, { is_available: !i.on })
                        }
                    >
                        {i.name}
                        {!i.on && ` · ${t('habis')}`}
                    </Button>
                ))}
            </div>
        </div>
    );
}
