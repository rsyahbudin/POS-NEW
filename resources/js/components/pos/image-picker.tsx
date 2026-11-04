import { ImagePlus, TriangleAlert } from 'lucide-react';
import { useEffect, useId, useState } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

type Size = { w: number; h: number };

const kb = (bytes: number) =>
    bytes >= 1024 * 1024
        ? `${(bytes / 1024 / 1024).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;

/**
 * A photo field that shows what will be used and how big it is: the current image or the one just picked,
 * its pixel size and file size, the size we recommend, and a warning when the picked photo is too small or too heavy.
 */
export function ImagePicker({
    file,
    onChange,
    current,
    recommend,
    maxMb = 8,
    aspect = 'aspect-square',
    previewClass = 'max-w-sm',
    note,
    disabled,
}: {
    file: File | null;
    onChange: (file: File | null) => void;
    /** URL of the image that is saved now. */
    current?: string | null;
    /** Recommended pixel size; a picked photo smaller than this is flagged. */
    recommend?: Size;
    maxMb?: number;
    /** Tailwind aspect class of the preview box. */
    aspect?: string;
    /** Width limit of the preview box. */
    previewClass?: string;
    /** What the server does with it, e.g. "Otomatis jadi WebP, maks 800 px". */
    note?: string;
    disabled?: boolean;
}) {
    const id = useId();
    const [url, setUrl] = useState<string | null>(null);
    const [size, setSize] = useState<Size | null>(null);
    const [savedSize, setSavedSize] = useState<Size | null>(null);

    useEffect(() => {
        if (!file) {
            setUrl(null);
            setSize(null);

            return;
        }

        const u = URL.createObjectURL(file);
        setUrl(u);
        const img = new Image();
        img.onload = () =>
            setSize({ w: img.naturalWidth, h: img.naturalHeight });
        img.src = u;

        return () => URL.revokeObjectURL(u);
    }, [file]);

    const shown = url ?? current ?? null;
    const small =
        !!size && !!recommend && (size.w < recommend.w || size.h < recommend.h);
    const heavy = !!file && file.size > maxMb * 1024 * 1024;

    return (
        <div className="grid gap-2">
            <div
                className={cn(
                    'relative w-full overflow-hidden rounded-xl border bg-muted',
                    previewClass,
                    aspect,
                )}
            >
                {shown ? (
                    <img
                        src={shown}
                        alt="Pratinjau foto"
                        className="size-full object-cover"
                        onLoad={(e) => {
                            if (!url) {
                                setSavedSize({
                                    w: e.currentTarget.naturalWidth,
                                    h: e.currentTarget.naturalHeight,
                                });
                            }
                        }}
                    />
                ) : (
                    <div className="flex size-full flex-col items-center justify-center gap-1 text-sm text-muted-foreground">
                        <ImagePlus className="size-6" />
                        Belum ada foto
                    </div>
                )}
            </div>

            <p className="text-sm tabular-nums">
                {file && size ? (
                    <>
                        <span className="font-medium">
                            {size.w} × {size.h} px
                        </span>{' '}
                        · {kb(file.size)}
                    </>
                ) : !file && current && savedSize ? (
                    <>
                        Tersimpan:{' '}
                        <span className="font-medium">
                            {savedSize.w} × {savedSize.h} px
                        </span>
                    </>
                ) : (
                    <span className="text-muted-foreground">
                        {recommend
                            ? `Disarankan ${recommend.w} × ${recommend.h} px`
                            : 'Pilih foto'}
                    </span>
                )}
                <span className="text-muted-foreground">
                    {' '}
                    · maks {maxMb} MB
                    {recommend && (file || current)
                        ? ` · disarankan ${recommend.w} × ${recommend.h} px`
                        : ''}
                </span>
            </p>
            {note && <p className="text-xs text-muted-foreground">{note}</p>}

            {(small || heavy) && (
                <p
                    role="alert"
                    className="flex items-start gap-1.5 text-sm text-amber-700 dark:text-amber-400"
                >
                    <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                    {heavy
                        ? `Ukuran file lebih dari ${maxMb} MB, akan ditolak.`
                        : `Lebih kecil dari ukuran disarankan (${recommend?.w} × ${recommend?.h} px), bisa tampak buram.`}
                </p>
            )}

            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className="h-11"
                    disabled={disabled}
                    asChild
                >
                    <label htmlFor={id} className="cursor-pointer">
                        <ImagePlus />
                        {shown ? 'Ganti foto' : 'Pilih foto'}
                    </label>
                </Button>
                {file && (
                    <Button
                        type="button"
                        variant="ghost"
                        className="h-11"
                        onClick={() => onChange(null)}
                    >
                        Batal pilih
                    </Button>
                )}
            </div>
            <input
                id={id}
                type="file"
                accept="image/*"
                className="sr-only"
                disabled={disabled}
                onChange={(e) => {
                    onChange(e.target.files?.[0] ?? null);
                    e.target.value = '';
                }}
            />
        </div>
    );
}
