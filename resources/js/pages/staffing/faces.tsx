import { Head, router, usePage } from '@inertiajs/react';
import { ScanFace } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { PageHeader } from '@/components/pos/ui';
import { Button } from '@/components/ui/button';
import { MIN_FACE_SIZE, useFaceCamera } from '@/lib/face';

type Person = {
    id: number;
    name: string;
    role: string | null;
    samples: number;
};

const STEPS = [
    'Hadap lurus ke kamera',
    'Tengok sedikit ke kiri',
    'Tengok sedikit ke kanan',
    'Hadap lurus lagi',
];

function Capture({
    person,
    onDone,
    onClose,
}: {
    person: Person;
    onDone: (samples: number[][]) => void;
    onClose: () => void;
}) {
    const { video, state, error, look } = useFaceCamera();
    const [consent, setConsent] = useState(false);
    const [running, setRunning] = useState(false);
    const [taken, setTaken] = useState<number[][]>([]);
    const [hint, setHint] = useState('');
    const samples = useRef<number[][]>([]);

    useEffect(() => {
        if (!running) {
            return;
        }
        let alive = true;
        let last = 0;
        const tick = async () => {
            if (!alive) {
                return;
            }
            const seen = await look().catch(() => null);
            if (!seen) {
                setHint('Wajah tidak terlihat');
            } else if (seen.size < MIN_FACE_SIZE) {
                setHint('Dekatkan wajah ke kamera');
            } else if (Date.now() - last > 1800) {
                last = Date.now();
                samples.current = [...samples.current, seen.descriptor];
                setTaken(samples.current);
                setHint('');
                if (samples.current.length >= STEPS.length) {
                    alive = false;
                    setRunning(false);
                    onDone(samples.current);

                    return;
                }
            }
            setTimeout(tick, 400);
        };
        void tick();

        return () => {
            alive = false;
        };
    }, [running, look, onDone]);

    return (
        <Modal open onClose={onClose} title={`Daftarkan wajah ${person.name}`}>
            <div className="relative overflow-hidden rounded-xl border bg-black">
                <video
                    ref={video}
                    className="aspect-[4/3] w-full -scale-x-100 object-cover"
                    playsInline
                    muted
                />
                {state === 'loading' && (
                    <p className="absolute inset-0 grid place-items-center text-sm text-white">
                        Menyiapkan kamera…
                    </p>
                )}
                {state === 'error' && (
                    <p className="absolute inset-0 grid place-items-center p-4 text-center text-sm text-white">
                        {error}
                    </p>
                )}
            </div>
            {!running && taken.length === 0 && (
                <>
                    <label className="flex items-start gap-2 text-sm">
                        <input
                            type="checkbox"
                            className="mt-1"
                            checked={consent}
                            onChange={(e) => setConsent(e.target.checked)}
                        />
                        <span>
                            {person.name} setuju wajahnya dipakai untuk absensi.
                            Yang disimpan hanya deretan angka dari wajah, bukan
                            foto, dan bisa dihapus kapan saja.
                        </span>
                    </label>
                    <Button
                        className="h-12"
                        disabled={!consent || state !== 'ready'}
                        onClick={() => {
                            samples.current = [];
                            setRunning(true);
                        }}
                    >
                        Mulai
                    </Button>
                </>
            )}
            {(running || taken.length > 0) && (
                <div className="grid gap-1 text-center">
                    <p className="text-lg font-medium">
                        {STEPS[Math.min(taken.length, STEPS.length - 1)]}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {taken.length} dari {STEPS.length}
                        {hint && ` · ${hint}`}
                    </p>
                </div>
            )}
        </Modal>
    );
}

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
                <Capture
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
