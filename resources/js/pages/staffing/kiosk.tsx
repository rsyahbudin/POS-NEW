import { Head, Link } from '@inertiajs/react';
import { LogIn, LogOut } from 'lucide-react';
import { useRef, useState } from 'react';
import { AccountMenu } from '@/components/pos/account-menu';
import { FaceClock } from '@/components/pos/face-clock';
import type { Action } from '@/components/pos/face-clock';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Person = { id: number; name: string };

function NamePicker({
    action,
    people,
    onPick,
    onCancel,
}: {
    action: Action;
    people: Person[];
    onPick: (p: Person) => void;
    onCancel: () => void;
}) {
    const [q, setQ] = useState('');
    const shown = people.filter((p) =>
        p.name.toLowerCase().includes(q.trim().toLowerCase()),
    );

    return (
        <div className="grid gap-4">
            <div className="flex items-center justify-between gap-3">
                <h2 className="text-lg font-semibold">
                    Siapa Anda? · Absen {action === 'in' ? 'Masuk' : 'Pulang'}
                </h2>
                <Button variant="ghost" className="h-11" onClick={onCancel}>
                    Batal
                </Button>
            </div>
            <Input
                className="h-12"
                value={q}
                placeholder="Cari nama…"
                onChange={(e) => setQ(e.target.value)}
                autoFocus
            />
            {shown.length === 0 ? (
                <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                    {people.length === 0
                        ? 'Belum ada wajah terdaftar. Daftarkan dulu di menu User atau Wajah.'
                        : 'Nama tidak ditemukan. Hanya karyawan yang wajahnya sudah terdaftar yang muncul.'}
                </p>
            ) : (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
                    {shown.map((p) => (
                        <Button
                            key={p.id}
                            variant="outline"
                            className="h-16 text-base"
                            onClick={() => onPick(p)}
                        >
                            {p.name}
                        </Button>
                    ))}
                </div>
            )}
            <p className="text-center text-xs text-muted-foreground">
                Pilih nama Anda sendiri. Kamera akan memeriksa apakah wajahnya
                benar milik orang itu.
            </p>
        </div>
    );
}

export default function Kiosk({
    enrolled,
    people,
    undo_seconds,
    geofence,
}: {
    enrolled: number;
    people: Person[];
    undo_seconds: number;
    geofence: boolean;
}) {
    const [action, setAction] = useState<Action | null>(null);
    const [person, setPerson] = useState<Person | null>(null);
    const done = useRef(() => {
        setAction(null);
        setPerson(null);
    });

    return (
        <>
            <Head title="Kiosk Absensi" />
            <div className="mx-auto grid min-h-svh w-full max-w-3xl grid-cols-1 content-start gap-4 p-4">
                <div className="flex items-center justify-between gap-3">
                    <h1 className="text-xl font-semibold">Absensi</h1>
                    <div className="flex items-center gap-2">
                        <Button asChild variant="ghost" className="h-11">
                            <Link href="/attendance/mine">Absensi saya</Link>
                        </Button>
                        <AccountMenu />
                    </div>
                </div>
                {action && person ? (
                    <FaceClock
                        action={action}
                        title={`Absen ${action === 'in' ? 'Masuk' : 'Pulang'} · ${person.name}`}
                        scanUrl="/attendance/kiosk/scan"
                        reasonUrl="/attendance/kiosk/reason"
                        undoUrl="/attendance/kiosk/undo"
                        undoSeconds={undo_seconds}
                        extra={{ user_id: person.id }}
                        geofence={geofence}
                        onDone={done.current}
                    />
                ) : action ? (
                    <NamePicker
                        action={action}
                        people={people}
                        onPick={setPerson}
                        onCancel={done.current}
                    />
                ) : (
                    <div className="grid gap-4 pt-4">
                        <p className="text-center text-muted-foreground">
                            {enrolled === 0
                                ? 'Belum ada wajah terdaftar. Daftarkan dulu di menu User atau Wajah Karyawan.'
                                : 'Pilih Masuk atau Pulang, lalu pilih nama Anda dan hadapkan wajah ke kamera.'}
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
