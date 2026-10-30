import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useSound } from '@/hooks/use-sound';
import { api } from '@/lib/api';
import { MIN_FACE_SIZE, useFaceCamera } from '@/lib/face';
import { timeId } from '@/lib/format';

type Result = {
    status: 'in' | 'out' | 'repeat' | 'no_match';
    name?: string;
    at?: string;
    attendance_id?: number;
    late?: number | null;
};

const STEADY = 3; // consecutive sightings before a scan is sent
const SHOW_MS = 7000;

export default function Kiosk({
    enrolled,
    undo_seconds,
}: {
    enrolled: number;
    undo_seconds: number;
}) {
    const { video, state, error, look } = useFaceCamera();
    const sound = useSound();
    const beep = useRef(sound.beep);
    beep.current = sound.beep;
    const [result, setResult] = useState<Result | null>(null);
    const [hint, setHint] = useState('Menghadap kamera');
    const busy = useRef(false);
    const steady = useRef(0);
    const pause = useRef(0);

    useEffect(() => {
        if (state !== 'ready') {
            return;
        }
        let alive = true;
        const tick = async () => {
            if (!alive) {
                return;
            }
            if (!busy.current && Date.now() > pause.current) {
                try {
                    const seen = await look();
                    if (!seen) {
                        steady.current = 0;
                        setHint('Menghadap kamera');
                    } else if (seen.size < MIN_FACE_SIZE) {
                        steady.current = 0;
                        setHint('Dekatkan wajah ke kamera');
                    } else if (++steady.current >= STEADY) {
                        busy.current = true;
                        steady.current = 0;
                        setHint('Memeriksa…');
                        const r = await api<Result>(
                            'POST',
                            '/attendance/kiosk/scan',
                            { descriptor: seen.descriptor },
                        );
                        setResult(r);
                        beep.current(
                            r.status === 'no_match' ? 'alert' : 'ready',
                        );
                        pause.current =
                            Date.now() +
                            (r.status === 'no_match' ? 3000 : SHOW_MS);
                        busy.current = false;
                    }
                } catch {
                    busy.current = false;
                    setHint('Gagal terhubung. Coba lagi.');
                    pause.current = Date.now() + 3000;
                }
            }
            if (alive) {
                setTimeout(tick, 350);
            }
        };
        void tick();

        return () => {
            alive = false;
        };
    }, [state, look]);

    useEffect(() => {
        if (!result) {
            return;
        }
        const t = setTimeout(
            () => setResult(null),
            result.status === 'no_match' ? 3000 : SHOW_MS,
        );

        return () => clearTimeout(t);
    }, [result]);

    const undo = async () => {
        if (
            result?.attendance_id &&
            (result.status === 'in' || result.status === 'out')
        ) {
            await api('POST', '/attendance/kiosk/undo', {
                attendance_id: result.attendance_id,
                action: result.status,
            }).catch(() => undefined);
        }
        setResult(null);
        pause.current = Date.now() + 1500;
    };

    return (
        <>
            <Head title="Kiosk Absensi" />
            <div className="mx-auto grid min-h-svh w-full max-w-3xl content-start gap-4 p-4">
                <h1 className="text-xl font-semibold">Absensi</h1>
                <div className="relative overflow-hidden rounded-2xl border bg-black">
                    <video
                        ref={video}
                        className="aspect-[4/3] w-full -scale-x-100 object-cover"
                        playsInline
                        muted
                    />
                    {state === 'loading' && (
                        <p className="absolute inset-0 grid place-items-center text-sm text-white">
                            Menyiapkan kamera dan model wajah…
                        </p>
                    )}
                    {state === 'error' && (
                        <p className="absolute inset-0 grid place-items-center p-6 text-center text-sm text-white">
                            {error}
                        </p>
                    )}
                </div>

                {result?.status === 'in' ||
                result?.status === 'out' ||
                result?.status === 'repeat' ? (
                    <div
                        role="status"
                        className="grid gap-2 rounded-2xl border border-emerald-600/40 bg-card p-5 text-center"
                    >
                        <p className="text-2xl font-semibold">{result.name}</p>
                        {result.status === 'repeat' ? (
                            <p className="text-muted-foreground">
                                Sudah tercatat barusan.
                            </p>
                        ) : (
                            <>
                                <p className="text-lg">
                                    {result.status === 'in'
                                        ? 'Masuk'
                                        : 'Pulang'}{' '}
                                    · {timeId(result.at)}
                                </p>
                                {result.status === 'in' && !!result.late && (
                                    <p className="text-amber-700 dark:text-amber-400">
                                        Terlambat {result.late} menit
                                    </p>
                                )}
                                <Button
                                    variant="outline"
                                    className="mx-auto h-11"
                                    onClick={undo}
                                >
                                    Bukan saya
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    Tombol ini membatalkan catatan selama{' '}
                                    {Math.round(undo_seconds / 60)} menit.
                                </p>
                            </>
                        )}
                    </div>
                ) : result?.status === 'no_match' ? (
                    <p
                        role="status"
                        className="rounded-2xl border border-red-600/40 bg-card p-5 text-center text-lg"
                    >
                        Wajah belum dikenali. Coba lagi, atau minta manager
                        mendaftarkan wajah Anda.
                    </p>
                ) : (
                    <p className="rounded-2xl border bg-card p-5 text-center text-lg text-muted-foreground">
                        {enrolled === 0
                            ? 'Belum ada wajah terdaftar. Daftarkan dulu di menu Wajah Karyawan.'
                            : hint}
                    </p>
                )}
            </div>
        </>
    );
}
