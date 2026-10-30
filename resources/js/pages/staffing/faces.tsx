import { Head, router, usePage } from '@inertiajs/react';
import { ScanFace } from 'lucide-react';
import { useState } from 'react';
import { FaceCapture } from '@/components/pos/face-capture';
import { PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { StaffingTabs } from '@/components/pos/section-tabs';

type Person = {
    id: number;
    name: string;
    role: string | null;
    samples: number;
};

export default function Faces({
    people,
    max_samples,
}: {
    people: Person[];
    max_samples: number;
}) {
    const errors = usePage().props.errors as Record<string, string>;
    const [who, setWho] = useState<Person | null>(null);
    const firstError = Object.values(errors ?? {})[0];

    return (
        <>
            <Head title="Wajah Karyawan" />
            <div className="mx-auto grid w-full max-w-[900px] gap-5 p-4 md:p-6">
                <StaffingTabs />
                <PageHeader
                    title="Wajah karyawan"
                    subtitle={`Daftarkan wajah tiap karyawan sekali (sampai ${max_samples} sampel). Yang disimpan hanya angka dari wajah, bukan foto.`}
                />
                {firstError && (
                    <p role="alert" className="text-sm text-destructive">
                        {firstError}
                    </p>
                )}
                <div className="grid gap-2">
                    {people.map((p) => (
                        <div
                            key={p.id}
                            className="flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-card p-3"
                        >
                            <p className="font-medium">
                                {p.name}{' '}
                                {p.role && (
                                    <span className="text-xs text-muted-foreground">
                                        · {p.role}
                                    </span>
                                )}
                                <span
                                    className={`ml-2 rounded-full border px-2 py-0.5 text-xs ${p.samples > 0 ? 'border-emerald-600/40 text-emerald-700 dark:text-emerald-400' : 'text-muted-foreground'}`}
                                >
                                    {p.samples > 0
                                        ? 'Terdaftar'
                                        : 'Belum terdaftar'}
                                </span>
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    className="h-11"
                                    variant={
                                        p.samples > 0 ? 'outline' : 'default'
                                    }
                                    onClick={() => setWho(p)}
                                >
                                    <ScanFace />{' '}
                                    {p.samples > 0
                                        ? 'Daftar ulang'
                                        : 'Daftarkan'}
                                </Button>
                                {p.samples > 0 && (
                                    <Button
                                        className="h-11"
                                        variant="ghost"
                                        onClick={() =>
                                            confirm(
                                                `Hapus data wajah ${p.name}?`,
                                            ) &&
                                            router.delete(
                                                `/attendance/faces/${p.id}`,
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Hapus
                                    </Button>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>
            {who && (
                <FaceCapture
                    person={who}
                    onClose={() => setWho(null)}
                    onDone={(samples) =>
                        router.post(
                            `/attendance/faces/${who.id}`,
                            { consent: true, samples },
                            {
                                preserveScroll: true,
                                onFinish: () => setWho(null),
                            },
                        )
                    }
                />
            )}
        </>
    );
}
