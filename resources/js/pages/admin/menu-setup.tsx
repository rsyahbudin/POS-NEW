import { router } from '@inertiajs/react';
import { Check, Pencil, Plus, Trash2, X } from 'lucide-react';
import { useState } from 'react';
import { InfoTip } from '@/components/pos/info-tip';
import { Select } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { rupiah } from '@/lib/format';
import { cn } from '@/lib/utils';

export type Station = { id: number; name: string; is_active: boolean };
export type CategoryRow = {
    id: number;
    name: string;
    sort: number;
    station_id: number | null;
    printer_ids: number[];
};
export type PrinterRow = { id: number; name: string };
export type GroupRow = {
    id: number;
    name: string;
    min_select: number;
    max_select: number;
    is_required: boolean;
    modifiers: { id?: number; name: string; price_delta: number }[];
};

const keep = { preserveScroll: true };

/** One input and a button that adds a name to a list. */
function AddRow({ placeholder, url }: { placeholder: string; url: string }) {
    const [name, setName] = useState('');
    const add = () =>
        name.trim() &&
        router.post(url, { name }, { ...keep, onSuccess: () => setName('') });

    return (
        <form
            className="flex gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                add();
            }}
        >
            <Input
                className="h-11"
                placeholder={placeholder}
                aria-label={placeholder}
                value={name}
                onChange={(e) => setName(e.target.value)}
            />
            <Button className="h-11 shrink-0" disabled={!name.trim()}>
                <Plus /> Tambah
            </Button>
        </form>
    );
}

/** A name that turns into an input when the pencil is tapped. */
function EditableName({
    name,
    onSave,
}: {
    name: string;
    onSave: (name: string) => void;
}) {
    const [value, setValue] = useState<string | null>(null);

    if (value === null) {
        return (
            <div className="flex min-w-0 items-center gap-1">
                <h3 className="truncate font-semibold">{name}</h3>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-9 shrink-0 text-muted-foreground"
                    aria-label={`Ubah nama ${name}`}
                    onClick={() => setValue(name)}
                >
                    <Pencil className="size-4" />
                </Button>
            </div>
        );
    }

    const save = () => {
        if (value.trim() && value !== name) {
            onSave(value.trim());
        }

        setValue(null);
    };

    return (
        <form
            className="flex min-w-0 items-center gap-1"
            onSubmit={(e) => {
                e.preventDefault();
                save();
            }}
        >
            <Input
                autoFocus
                className="h-10 min-w-0"
                aria-label="Nama"
                value={value}
                onChange={(e) => setValue(e.target.value)}
            />
            <Button
                size="icon"
                className="size-10 shrink-0"
                aria-label="Simpan nama"
            >
                <Check />
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="icon"
                className="size-10 shrink-0"
                aria-label="Batal"
                onClick={() => setValue(null)}
            >
                <X />
            </Button>
        </form>
    );
}

function DeleteButton({
    label,
    onConfirm,
}: {
    label: string;
    onConfirm: () => void;
}) {
    return (
        <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-9 shrink-0 text-muted-foreground hover:text-destructive"
            aria-label={`Hapus ${label}`}
            onClick={() => confirm(`Hapus ${label}?`) && onConfirm()}
        >
            <Trash2 className="size-4" />
        </Button>
    );
}

export function CategoriesTab({
    categories,
    stations,
    printers,
}: {
    categories: CategoryRow[];
    stations: Station[];
    printers: PrinterRow[];
}) {
    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">Kategori</h2>
                <InfoTip label="Info kategori">
                    Atur station dan printer sekali per kategori, semua menunya
                    ikut. Satu menu bisa menimpa keduanya di form menu.
                </InfoTip>
            </div>
            <div className="max-w-xl">
                <AddRow placeholder="Kategori baru" url="/admin/categories" />
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {categories.map((c) => (
                    <article
                        key={c.id}
                        className="grid content-start gap-3 rounded-xl border bg-card p-3"
                    >
                        <div className="flex items-center justify-between gap-2">
                            <EditableName
                                name={c.name}
                                onSave={(name) =>
                                    router.put(
                                        `/admin/categories/${c.id}`,
                                        { name },
                                        keep,
                                    )
                                }
                            />
                            <DeleteButton
                                label={c.name}
                                onConfirm={() =>
                                    router.delete(
                                        `/admin/categories/${c.id}`,
                                        keep,
                                    )
                                }
                            />
                        </div>
                        {stations.length > 0 && (
                            <div className="grid gap-1.5">
                                <span className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                                    Station
                                    <InfoTip label="Info station">
                                        Layar dapur atau bar yang menampilkan
                                        menu kategori ini.
                                    </InfoTip>
                                </span>
                                <Select
                                    aria-label={`Station ${c.name}`}
                                    value={c.station_id ?? ''}
                                    onChange={(e) =>
                                        router.put(
                                            `/admin/categories/${c.id}`,
                                            {
                                                name: c.name,
                                                sort: c.sort,
                                                station_id: e.target.value
                                                    ? Number(e.target.value)
                                                    : null,
                                            },
                                            keep,
                                        )
                                    }
                                >
                                    <option value="">Tanpa station</option>
                                    {stations.map((s) => (
                                        <option key={s.id} value={s.id}>
                                            {s.name}
                                        </option>
                                    ))}
                                </Select>
                            </div>
                        )}
                        {printers.length > 0 && (
                            <div className="grid gap-1.5">
                                <span className="flex items-center gap-1 text-xs font-medium text-muted-foreground">
                                    Cetak tiket di
                                    <InfoTip label="Info printer tiket">
                                        Printer tempat tiket pesanan kategori
                                        ini tercetak. Boleh lebih dari satu,
                                        mis. minuman di Bar dan Kasir.
                                    </InfoTip>
                                </span>
                                <div className="flex flex-wrap gap-2">
                                    {printers.map((pr) => {
                                        const on = c.printer_ids.includes(
                                            pr.id,
                                        );

                                        return (
                                            <button
                                                key={pr.id}
                                                type="button"
                                                aria-pressed={on}
                                                onClick={() =>
                                                    router.post(
                                                        `/admin/categories/${c.id}/printers`,
                                                        {
                                                            printer_ids: on
                                                                ? c.printer_ids.filter(
                                                                      (x) =>
                                                                          x !==
                                                                          pr.id,
                                                                  )
                                                                : [
                                                                      ...c.printer_ids,
                                                                      pr.id,
                                                                  ],
                                                        },
                                                        keep,
                                                    )
                                                }
                                                className={cn(
                                                    'inline-flex h-10 items-center gap-1.5 rounded-full border px-3 text-sm transition-colors',
                                                    on
                                                        ? 'border-primary bg-primary text-primary-foreground'
                                                        : 'bg-card text-foreground hover:bg-muted',
                                                )}
                                            >
                                                {on && (
                                                    <Check className="size-4" />
                                                )}
                                                {pr.name}
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>
                        )}
                    </article>
                ))}
            </div>
            {categories.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Belum ada kategori.
                </p>
            )}
        </div>
    );
}

export function StationsTab({
    stations,
    categories,
}: {
    stations: Station[];
    categories: CategoryRow[];
}) {
    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">Station</h2>
                <InfoTip label="Info station">
                    Station adalah pos kerja tempat pesanan disiapkan, mis.
                    Dapur dan Bar. Dipakai untuk layar dapur dan laporan waktu
                    penyajian. Dipilih per kategori di tab Kategori.
                </InfoTip>
            </div>
            <div className="max-w-xl">
                <AddRow placeholder="Station baru" url="/admin/stations" />
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {stations.map((s) => {
                    const used = categories.filter(
                        (c) => c.station_id === s.id,
                    );

                    return (
                        <article
                            key={s.id}
                            className="grid content-start gap-2 rounded-xl border bg-card p-3"
                        >
                            <EditableName
                                name={s.name}
                                onSave={(name) =>
                                    router.put(
                                        `/admin/stations/${s.id}`,
                                        { name },
                                        keep,
                                    )
                                }
                            />
                            <p className="text-sm text-muted-foreground">
                                {used.length > 0
                                    ? used.map((c) => c.name).join(', ')
                                    : 'Belum dipakai kategori mana pun'}
                            </p>
                        </article>
                    );
                })}
            </div>
            {stations.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Belum ada station. Tambahkan Dapur atau Bar.
                </p>
            )}
        </div>
    );
}

export function ModifiersTab({
    groups,
    onEdit,
}: {
    groups: GroupRow[];
    onEdit: (g: GroupRow) => void;
}) {
    return (
        <div className="grid gap-4">
            <div className="flex flex-wrap items-center gap-2">
                <h2 className="font-semibold">Grup modifier</h2>
                <InfoTip label="Info modifier">
                    Modifier adalah pilihan tambahan di atas menu, seperti level
                    gula atau topping. Harganya ditambahkan ke harga menu. Beda
                    dengan varian, yang menggantikan harga dasar (mis. ukuran
                    Regular atau Large).
                </InfoTip>
            </div>
            <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                {groups.map((g) => (
                    <article
                        key={g.id}
                        className="grid content-start gap-3 rounded-xl border bg-card p-3"
                    >
                        <div className="flex items-start justify-between gap-2">
                            <div className="min-w-0">
                                <h3 className="truncate font-semibold">
                                    {g.name}
                                </h3>
                                <p className="mt-1 flex flex-wrap items-center gap-1.5 text-xs">
                                    <span
                                        className={cn(
                                            'rounded-full px-2 py-0.5 font-medium',
                                            g.is_required
                                                ? 'bg-amber-100 text-amber-900 dark:bg-amber-950/50 dark:text-amber-200'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {g.is_required ? 'Wajib' : 'Opsional'}
                                    </span>
                                    <span className="text-muted-foreground">
                                        Pilih{' '}
                                        {g.min_select === g.max_select
                                            ? g.max_select
                                            : `${g.min_select}–${g.max_select}`}
                                    </span>
                                </p>
                            </div>
                            <div className="flex shrink-0">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="icon"
                                    className="size-9 text-muted-foreground"
                                    aria-label={`Ubah ${g.name}`}
                                    onClick={() => onEdit(g)}
                                >
                                    <Pencil className="size-4" />
                                </Button>
                                <DeleteButton
                                    label={`grup ${g.name}`}
                                    onConfirm={() =>
                                        router.delete(
                                            `/admin/modifier-groups/${g.id}`,
                                            keep,
                                        )
                                    }
                                />
                            </div>
                        </div>
                        <ul className="flex flex-wrap gap-1.5">
                            {g.modifiers.map((m) => (
                                <li
                                    key={m.id ?? m.name}
                                    className="rounded-full border bg-background px-2.5 py-1 text-sm"
                                >
                                    {m.name}
                                    {m.price_delta !== 0 && (
                                        <span className="ml-1 text-muted-foreground">
                                            +{rupiah(m.price_delta)}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </article>
                ))}
            </div>
            {groups.length === 0 && (
                <p className="text-sm text-muted-foreground">
                    Belum ada grup modifier. Tekan Grup modifier di kanan atas.
                </p>
            )}
        </div>
    );
}
