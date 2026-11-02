import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { useSound } from '@/hooks/use-sound';
import { api } from '@/lib/api';
import { MIN_FACE_SIZE, useFaceCamera } from '@/lib/face';
import { timeId } from '@/lib/format';

export type Action = 'in' | 'out';
type Result = {
    status:
        | 'in'
        | 'out'
        | 'repeat'
        | 'already_in'
        | 'not_in'
        | 'unknown'
        | 'need_reason'
        | 'expired'
        | 'wait'
        | 'mismatch'
        | 'ambiguous'
        | 'not_enrolled'
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
    stations?: string[];
    retry_after?: number | null;
    flag?: string | null;
    text?: string;
    ticket?: string | null;
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

const QUICK: Record<string, string[]> = {
    unscheduled: [
        'Diminta MOD',
        'Menggantikan teman',
        'Lembur atau acara khusus',
    ],
    late: ['Macet', 'Sakit atau berobat', 'Urusan keluarga'],
    early: ['Diminta MOD', 'Persiapan buka'],
    overstay: [
        'Menyelesaikan pekerjaan',
        'Diminta MOD',
        'Menunggu serah terima',
    ],
    early_out: ['Diizinkan MOD', 'Sakit', 'Urusan keluarga'],
};

/** The clock-in or clock-out does not fit the schedule: a reason is needed before it is written down. */
function ReasonForm({
    result,
    busy,
    onSend,
    onCancel,
}: {
    result: Result;
    busy: boolean;
    onSend: (reason: string) => void;
    onCancel: () => void;
}) {
    const [reason, setReason] = useState('');
    const quick = QUICK[result.flag ?? ''] ?? [];

    return (
        <div className="grid gap-3 text-left">
            <p className="text-center text-xl font-semibold">{result.name}</p>
            <p className="rounded-xl border border-amber-600/40 p-3 text-sm">
                {result.text}
            </p>
            <p className="text-sm text-muted-foreground">
                Tulis alasannya. Alasan dibaca MOD dan dicatat bersama absen
                Anda.
            </p>
            {quick.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {quick.map((q) => (
                        <Button
                            key={q}
                            type="button"
                            size="sm"
                            variant={reason === q ? 'default' : 'outline'}
                            className="h-10"
                            onClick={() => setReason(q)}
                        >
                            {q}
                        </Button>
                    ))}
                </div>
            )}
            <textarea
                className="min-h-24 w-full rounded-xl border border-input bg-card p-3 text-base outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                value={reason}
                maxLength={200}
                placeholder="Alasan (minimal 5 huruf)"
                onChange={(e) => setReason(e.target.value)}
            />
            <div className="flex gap-2">
                <Button
                    className="h-12 flex-1"
                    disabled={busy || reason.trim().length < 5}
                    onClick={() => onSend(reason.trim())}
                >
                    Kirim dan absen
                </Button>
                <Button variant="outline" className="h-12" onClick={onCancel}>
                    Batal
                </Button>
            </div>
        </div>
    );
}

/**
 * The camera step of a clock-in or clock-out: looks for a steady face, checks where the device is (when the cafe radius is on),
 * sends both to the server, and shows the answer. The same screen serves a person who is signed in with their own account and
 * the shared kiosk (which also sends who picked their name).
 */
export function FaceClock({
    action,
    title,
    scanUrl,
    reasonUrl,
    extra,
    undoUrl,
    undoSeconds,
    geofence,
    onDone,
}: {
    action: Action;
    title: string;
    scanUrl: string;
    reasonUrl: string;
    extra?: Record<string, unknown>;
    undoUrl?: string | null;
    undoSeconds?: number;
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
    const startedAt = useRef(Date.now());

    useEffect(() => {
        if (state !== 'ready') {
            return;
        }
        let alive = true;
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
                        const r = await api<Result>('POST', scanUrl, {
                            action,
                            descriptor: seen.descriptor,
                            ...extra,
                            ...where,
                        });
                        beep.current(
                            r.status === 'in' || r.status === 'out'
                                ? 'ready'
                                : 'alert',
                        );
                        stop.current = true;
                        setResult(r);
                        busy.current = false;
                    }
                } catch {
                    busy.current = false;
                    setHint('Gagal terhubung. Coba lagi.');
                }
            }
            if (!stop.current && Date.now() - startedAt.current > IDLE_MS) {
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
        if (result.status === 'need_reason') {
            return;
        }
        const t = setTimeout(onDone, SHOW_MS);

        return () => clearTimeout(t);
    }, [result, onDone]);

    const [sending, setSending] = useState(false);
    const sendReason = async (reason: string) => {
        if (!result?.ticket) {
            return;
        }
        setSending(true);
        try {
            const r = await api<Result>('POST', reasonUrl, {
                ticket: result.ticket,
                reason,
            });
            beep.current(
                r.status === 'in' || r.status === 'out' ? 'ready' : 'alert',
            );
            setResult(r);
        } catch {
            setResult({ status: 'expired' });
        } finally {
            setSending(false);
        }
    };

    const retry = () => {
        setResult(null);
        stop.current = false;
        steady.current = 0;
        startedAt.current = Date.now();
        setHint('Menghadap kamera');
    };

    const undo = async () => {
        if (
            undoUrl &&
            result?.attendance_id &&
            (result.status === 'in' || result.status === 'out')
        ) {
            await api('POST', undoUrl, {
                attendance_id: result.attendance_id,
                action: result.status,
            }).catch(() => undefined);
        }
        onDone();
    };

    return (
        <div className="grid gap-4">
            <div className="flex items-center justify-between">
                <h2 className="text-lg font-semibold">{title}</h2>
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

            {result?.status === 'need_reason' ? (
                <div className="rounded-2xl border bg-card p-5">
                    <ReasonForm
                        result={result}
                        busy={sending}
                        onSend={sendReason}
                        onCancel={onDone}
                    />
                </div>
            ) : result ? (
                <div
                    role="status"
                    className={`grid gap-2 rounded-2xl border bg-card p-5 text-center ${result.status === 'in' || result.status === 'out' ? 'border-emerald-600/40' : 'border-amber-600/40'}`}
                >
                    <p className="text-2xl font-semibold">
                        {result.name ?? 'Absensi'}
                    </p>
                    {!!result.stations?.length && (
                        <p className="text-sm text-muted-foreground">
                            Station: {result.stations.join(' + ')}
                        </p>
                    )}
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
                            {undoUrl && (
                                <>
                                    <Button
                                        variant="outline"
                                        className="mx-auto h-11"
                                        onClick={undo}
                                    >
                                        Bukan saya
                                    </Button>
                                    <p className="text-xs text-muted-foreground">
                                        Tombol ini membatalkan catatan selama{' '}
                                        {Math.round((undoSeconds ?? 120) / 60)}{' '}
                                        menit.
                                    </p>
                                </>
                            )}
                            {!undoUrl && (
                                <Button
                                    className="mx-auto h-11"
                                    onClick={onDone}
                                >
                                    Selesai
                                </Button>
                            )}
                        </>
                    ) : result.status === 'mismatch' ||
                      result.status === 'ambiguous' ? (
                        <>
                            <p className="text-lg">
                                Wajah tidak cocok dengan {result.name}. Tidak
                                ada absen yang dicatat.
                            </p>
                            <p className="text-sm text-muted-foreground">
                                Pastikan Anda memilih nama sendiri, hadap kamera
                                lurus dengan cahaya cukup.
                            </p>
                            <Button className="mx-auto h-11" onClick={retry}>
                                Coba lagi
                            </Button>
                        </>
                    ) : result.status === 'wait' ? (
                        <>
                            <p className="text-lg">
                                Terlalu cepat. Tunggu {result.retry_after ?? 10}{' '}
                                detik lalu coba lagi.
                            </p>
                            <Button className="mx-auto h-11" onClick={retry}>
                                Coba lagi
                            </Button>
                        </>
                    ) : result.status === 'not_enrolled' ? (
                        <p className="text-lg">
                            Wajah {result.name} belum terdaftar. Minta manager
                            mendaftarkannya.
                        </p>
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
                    ) : result.status === 'expired' ||
                      result.status === 'unknown' ? (
                        <p className="text-lg">
                            Waktu habis atau data tidak dikenal. Ulangi dari
                            awal.
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
