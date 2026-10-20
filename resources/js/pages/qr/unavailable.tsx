import { Head } from '@inertiajs/react';

export default function Unavailable() {
    return (
        <>
            <Head title="QR tidak aktif" />
            <main className="grid min-h-dvh place-items-center p-6 text-center">
                <div>
                    <h1 className="text-xl font-semibold">
                        QR meja ini tidak aktif
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        Silakan panggil staf kami untuk memesan.
                    </p>
                </div>
            </main>
        </>
    );
}
