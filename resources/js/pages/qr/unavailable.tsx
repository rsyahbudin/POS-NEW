import { Head } from '@inertiajs/react';

export default function Unavailable() {
    return (
        <>
            <Head title="QR tidak aktif" />
            <main className="grid min-h-dvh place-items-center bg-background p-8 text-center">
                <div className="max-w-xs">
                    <div
                        aria-hidden
                        className="mx-auto mb-5 grid size-16 place-items-center rounded-2xl bg-muted text-3xl"
                    >
                        🔔
                    </div>
                    <h1 className="text-xl font-bold tracking-tight">
                        QR meja ini belum aktif
                    </h1>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                        Silakan panggil staf kami, kami dengan senang hati
                        membantu mencatat pesananmu.
                    </p>
                </div>
            </main>
        </>
    );
}
