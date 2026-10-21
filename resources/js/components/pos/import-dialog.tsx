import { FileSpreadsheet, Upload } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Modal } from '@/components/pos/modal';
import { Field } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { api, ApiError, xsrf } from '@/lib/api';
import { cn } from '@/lib/utils';

type Info = {
    title: string;
    notes: string[];
    extra: { key: string; label: string; type: string; default?: string }[];
};
type Row = {
    line: number;
    status: 'ok' | 'new' | 'update' | 'error';
    message: string | null;
    cells: Record<string, string | null>;
};
type Preview = {
    summary: {
        total: number;
        ok: number;
        new: number;
        update: number;
        error: number;
    };
    columns: { key: string; label: string }[];
    rows: Row[];
    payload: { line: number; cells: Record<string, string | null> }[];
};

const BADGE: Record<string, { label: string; cls: string }> = {
    ok: { label: 'Siap', cls: 'bg-emerald-100 text-emerald-900' },
    new: { label: 'Baru', cls: 'bg-sky-100 text-sky-900' },
    update: { label: 'Diperbarui', cls: 'bg-amber-100 text-amber-900' },
    error: { label: 'Salah', cls: 'bg-red-100 text-red-900' },
};

/**
 * Import from Excel in three steps: download the template (already filled with what exists), upload the
 * filled file, check the preview (new / updated / wrong rows), then confirm.
 */
export function ImportDialog({
    type,
    onClose,
    onDone,
}: {
    type: string;
    onClose: () => void;
    onDone: () => void;
}) {
    const [info, setInfo] = useState<Info | null>(null);
    const [extra, setExtra] = useState<Record<string, string>>({});
    const [preview, setPreview] = useState<Preview | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [showNotes, setShowNotes] = useState(false);

    useEffect(() => {
        api<Info>('GET', `/import/${type}`)
            .then((i) => {
                setInfo(i);
                setExtra(
                    Object.fromEntries(
                        i.extra.map((f) => [f.key, f.default ?? '']),
                    ),
                );
            })
            .catch((e) =>
                setError(e instanceof ApiError ? e.message : 'Gagal memuat.'),
            );
    }, [type]);

    const upload = async (file: File) => {
        setBusy(true);
        setError('');
        setPreview(null);

        try {
            const body = new FormData();
            body.append('file', file);
            const res = await fetch(`/import/${type}/preview`, {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': xsrf(),
                },
            });
            const data = await res.json().catch(() => ({}));

            if (!res.ok) {
                throw new Error(
                    data.message ??
                        Object.values(data.errors ?? {}).flat()[0] ??
                        'File tidak bisa dibaca.',
                );
            }

            setPreview(data as Preview);
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Gagal membaca file.');
        } finally {
            setBusy(false);
        }
    };

    const apply = async () => {
        if (!preview) {
            return;
        }

        setBusy(true);

        try {
            const res = await api<{ message: string }>(
                'POST',
                `/import/${type}/apply`,
                { rows: preview.payload, extra },
            );
            toast.success(res.message);
            onDone();
            onClose();
        } catch (e) {
            setError(e instanceof ApiError ? e.message : 'Gagal mengimpor.');
        } finally {
            setBusy(false);
        }
    };

    const good = preview ? preview.summary.total - preview.summary.error : 0;
    const shown = preview
        ? [
              ...preview.rows.filter((r) => r.status === 'error'),
              ...preview.rows.filter((r) => r.status !== 'error'),
          ].slice(0, 150)
        : [];
    const cols = preview?.columns.slice(0, 4) ?? [];

    return (
        <Modal
            open
            onClose={onClose}
            title={`Impor ${info?.title ?? ''}`}
            description="Unduh template, isi di Excel, lalu unggah kembali."
            className="sm:max-w-2xl"
        >
            <div className="flex flex-wrap items-center gap-2">
                <Button asChild variant="outline" className="h-11">
                    <a href={`/import/${type}/template`}>
                        <FileSpreadsheet /> Unduh template
                    </a>
                </Button>
                <button
                    type="button"
                    className="text-sm text-muted-foreground underline"
                    onClick={() => setShowNotes((v) => !v)}
                >
                    {showNotes ? 'Tutup petunjuk' : 'Lihat petunjuk'}
                </button>
            </div>
            {showNotes && info && (
                <ul className="grid gap-1 rounded-lg bg-muted p-3 text-sm">
                    {info.notes.map((n) => (
                        <li key={n}>{n}</li>
                    ))}
                </ul>
            )}

            {info?.extra.map((f) => (
                <Field key={f.key} label={f.label}>
                    <Input
                        className="h-11"
                        type={f.type}
                        value={extra[f.key] ?? ''}
                        onChange={(e) =>
                            setExtra({ ...extra, [f.key]: e.target.value })
                        }
                    />
                </Field>
            ))}

            <Field label="File Excel yang sudah diisi">
                <input
                    type="file"
                    accept=".xlsx,.xls,.csv"
                    disabled={busy}
                    onChange={(e) => {
                        const f = e.target.files?.[0];

                        if (f) {
                            void upload(f);
                        }
                    }}
                />
            </Field>

            {error && (
                <p
                    role="alert"
                    className="rounded-lg bg-red-50 p-3 text-sm text-red-900"
                >
                    {error}
                </p>
            )}
            {busy && !preview && (
                <p className="text-sm text-muted-foreground">Membaca file…</p>
            )}

            {preview && (
                <>
                    <div
                        className="flex flex-wrap gap-2 text-sm"
                        aria-live="polite"
                    >
                        <span className="rounded bg-muted px-2 py-1">
                            {preview.summary.total} baris
                        </span>
                        {(['new', 'update', 'ok', 'error'] as const).map(
                            (s) =>
                                preview.summary[s] > 0 && (
                                    <span
                                        key={s}
                                        className={cn(
                                            'rounded px-2 py-1',
                                            BADGE[s].cls,
                                        )}
                                    >
                                        {preview.summary[s]}{' '}
                                        {BADGE[s].label.toLowerCase()}
                                    </span>
                                ),
                        )}
                    </div>
                    <div className="max-h-72 overflow-auto rounded-lg border">
                        <table className="w-full text-sm">
                            <thead className="sticky top-0 bg-muted text-left">
                                <tr>
                                    <th className="px-2 py-1.5">Baris</th>
                                    <th className="px-2 py-1.5">Status</th>
                                    {cols.map((c) => (
                                        <th key={c.key} className="px-2 py-1.5">
                                            {c.label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {shown.map((r) => (
                                    <tr
                                        key={r.line}
                                        className="border-t align-top"
                                    >
                                        <td className="px-2 py-1.5 tabular-nums">
                                            {r.line}
                                        </td>
                                        <td className="px-2 py-1.5">
                                            <span
                                                className={cn(
                                                    'rounded px-1.5 py-0.5 text-xs font-medium',
                                                    BADGE[r.status].cls,
                                                )}
                                            >
                                                {BADGE[r.status].label}
                                            </span>
                                            {r.message && (
                                                <span className="mt-1 block text-xs text-muted-foreground">
                                                    {r.message}
                                                </span>
                                            )}
                                        </td>
                                        {cols.map((c) => (
                                            <td
                                                key={c.key}
                                                className="px-2 py-1.5"
                                            >
                                                {r.cells[c.key] ?? ''}
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {preview.summary.error > 0 && (
                        <p className="text-sm text-amber-800">
                            Baris yang salah dilewati. Perbaiki di Excel lalu
                            unggah lagi, atau lanjutkan dengan baris yang benar.
                        </p>
                    )}
                    <Button
                        className="h-12"
                        disabled={busy || good === 0}
                        onClick={apply}
                    >
                        <Upload /> Impor {good} baris
                    </Button>
                </>
            )}
        </Modal>
    );
}
