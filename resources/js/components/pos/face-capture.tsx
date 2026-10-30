import { useEffect, useRef, useState } from 'react';
import { Modal } from '@/components/pos/modal';
import { Button } from '@/components/ui/button';
import { MIN_FACE_SIZE, useFaceCamera } from '@/lib/face';

const STEPS = [
    'Hadap lurus ke kamera',
    'Tengok sedikit ke kiri',
    'Tengok sedikit ke kanan',
    'Hadap lurus lagi',
];

export function FaceCapture({
    person,
    onDone,
    onClose,
}: {
    person: { id: number; name: string };
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
