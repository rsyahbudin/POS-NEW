import { Head } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useSound } from '@/hooks/use-sound';
import { api } from '@/lib/api';
import { MIN_FACE_SIZE, useFaceCamera } from '@/lib/face';
import { timeId } from '@/lib/format';

type Action = 'in' | 'out';
type Result = {
    status:
        | 'in'
        | 'out'
        | 'repeat'
        | 'already_in'
        | 'not_in'
        | 'no_match'
        | 'geo_not_set'
        | 'geo_missing'
        | 'geo_weak'
        | 'out_of_area';
    distance?: number | null;
    radius?: number;
    name?: string;
    at?: string;
    attendance_id?: number;
    late?: number | null;
};

const STEADY = 3; // consecutive sightings before a scan is sent
const SHOW_MS = 6000;
const IDLE_MS = 30000; // back to the two buttons when nobody shows a face

type Where = { lat: number; lng: number; accuracy: number };

/** The device's own GPS fix (a recent one is fine), or null when it is refused or unavailable. */
function whereAmI(): Promise<Where | null> {
    return new Promise((resolve) => {
        if (!navigator.geolocation) {
            resolve(null);

            return;
        }
        navigator.geolocation.getCurrentPosition(
            (p) =>
                resolve({
                    lat: p.coords.latitude,
                    lng: p.coords.longitude,
                    accuracy: p.coords.accuracy,
                }),
            () => resolve(null),
            { enableHighAccuracy: true, maximumAge: 20000, timeout: 10000 },
        );
    });
}

function Scanner({
    action,
    undoSeconds,
    geofence,
    onDone,
}: {
    action: Action;
    undoSeconds: number;
    geofence: boolean;
    onDone: () => void;
}) {
    const { video, state, error, look } = useFaceCamera();
    const sound = useSound();
    const beep = useRef(sound.beep);
    beep.current = sound.beep;
    const [result, setResult] = useState<Result | null>(null);
    const [hint, setHint] = useState('Menghadap kamera');
    const busy = useRef(false);
    const steady = useRef(0);
    const stop = useRef(false);

    useEffect(() => {
        if (state !== 'ready') {
            return;
        }
        let alive = true;
        const started = Date.now();
        const tick = async () => {
            if (!alive) {
                return;
            }
            if (!busy.current && !stop.current) {
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
                        const where = geofence ? await whereAmI() : null;
                        if (geofence && where === null) {
                            setHint(
                                'Lokasi perangkat tidak terbaca. Izinkan lokasi di browser, lalu coba lagi.',
                            );
                            busy.current = false;

                            return;
                        }
                        const r = await api<Result>(
                            'POST',
                            '/attendance/kiosk/scan',
                            {
                                action,
                                descriptor: seen.descriptor,
                                ...where,
                            },
                        );
                        beep.current(
                            r.status === 'in' || r.status === 'out'
                                ? 'ready'
                                : 'alert',
                        );
                        if (r.status === 'no_match') {
                            setHint('Wajah belum dikenali. Coba lagi.');
                            busy.current = false;
                        } else {
                            stop.current = true;
                            setResult(r);
                            busy.current = false;
                        }
                    }
                } catch {
                    busy.current = false;
                    setHint('Gagal terhubung. Coba lagi.');
                }
            }
            if (!stop.current && Date.now() - started > IDLE_MS) {
                onDone();

                return;
            }
            if (alive) {
                setTimeout(tick, 350);
            }
        };
        void tick();

        return () => {
            alive = false;
        };
    }, [state, look, action, onDone]);

    useEffect(() => {
        if (!result) {
            return;
        }
        const t = setTimeout(onDone, SHOW_MS);

        return () => clearTimeout(t);
    }, [result, onDone]);

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
        onDone();
    };

    const verb = action === 'in' ? 'Masuk' : 'Pulang';

    return (
        <div className="grid gap-4">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">Absen {verb}</h2>
                <Button variant="ghost" className="h-11" onClick={onDone}>
                    Batal
                </Button>
            </div>
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

            {result ? (
                <div
                    role="status"
                    className={`grid gap-2 rounded-2xl border bg-card p-5 text-center ${result.status === 'in' || result.status === 'out' ? 'border-emerald-600/40' : 'border-amber-600/40'}`}
                >
                    <p className="text-2xl font-semibold">
                        {result.name ?? 'Absensi'}
                    </p>
                    {result.status === 'in' || result.status === 'out' ? (
                        <>
                            <p className="text-lg">
                                {result.status === 'in' ? 'Masuk' : 'Pulang'} ·{' '}
                                {timeId(result.at)}
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
                                {Math.round(undoSeconds / 60)} menit.
                            </p>
                        </>
                    ) : result.status === 'out_of_area' ? (
                        <p className="text-lg">
                            Perangkat ini{' '}
                            {result.distance !== null &&
                            result.distance !== undefined
                                ? `sekitar ${result.distance} m`
                                : 'terlalu jauh'}{' '}
                            dari kafe. Absen hanya sah dalam radius{' '}
                            {result.radius} m.
                        </p>
                    ) : result.status === 'geo_weak' ? (
                        <p className="text-lg">
                            Sinyal lokasi kurang akurat. Pindah ke dekat jendela
                            atau tunggu sebentar, lalu coba lagi.
                        </p>
                    ) : result.status === 'geo_not_set' ? (
                        <p className="text-lg">
                            Titik lokasi kafe belum diatur. Minta owner
                            mengisinya di Pengaturan › Profil outlet.
                        </p>
                    ) : result.status === 'geo_missing' ? (
                        <p className="text-lg">
                            Lokasi perangkat tidak terbaca. Izinkan lokasi di
                            browser.
                        </p>
                    ) : result.status === 'already_in' ? (
                        <p className="text-lg">
                            Sudah masuk pukul {timeId(result.at)}. Pilih Pulang
                            untuk absen pulang.
                        </p>
                    ) : result.status === 'not_in' ? (
                        <p className="text-lg">
                            Belum tercatat masuk. Pilih Masuk dulu. Kalau
                            kemarin lupa absen pulang, minta manager mengoreksi.
                        </p>
                    ) : (
                        <p className="text-lg text-muted-foreground">
                            Sudah tercatat barusan.
                        </p>
                    )}
                </div>
            ) : (
                <p className="rounded-2xl border bg-card p-5 text-center text-lg text-muted-foreground">
                    {hint}
                </p>
            )}
        </div>
    );
}

export default function Kiosk({
    enrolled,
    undo_seconds,
    geofence,
}: {
    enrolled: number;
    undo_seconds: number;
    geofence: boolean;
}) {
    const [action, setAction] = useState<Action | null>(null);
    const done = useRef(() => setAction(null));

    return (
        <>
            <Head title="Kiosk Absensi" />
            <div className="mx-auto grid min-h-svh w-full max-w-3xl content-start gap-4 p-4">
                <h1 className="text-xl font-semibold">Absensi</h1>
                {action ? (
                    <Scanner
                        action={action}
                        undoSeconds={undo_seconds}
                        geofence={geofence}
                        onDone={done.current}
                    />
                ) : (
                    <div className="grid gap-4 pt-4">
                        <p className="text-center text-muted-foreground">
                            {enrolled === 0
                                ? 'Belum ada wajah terdaftar. Daftarkan dulu di menu User atau Wajah Karyawan.'
                                : 'Pilih dulu, lalu hadapkan wajah ke kamera.'}
                        </p>
                        <div className="grid gap-4 sm:grid-cols-2">
                            <Button
                                className="h-40 flex-col gap-2 text-2xl"
                                onClick={() => setAction('in')}
                            >
                                <LogIn className="size-10" /> Masuk
                            </Button>
                            <Button
                                className="h-40 flex-col gap-2 text-2xl"
                                variant="outline"
                                onClick={() => setAction('out')}
                            >
                                <LogOut className="size-10" /> Pulang
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}
