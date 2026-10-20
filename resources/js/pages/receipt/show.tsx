import { Head } from '@inertiajs/react';
import { useEffect } from 'react';
import { ReceiptView } from '@/components/pos/receipt-view';
import type { Receipt } from '@/types/pos';

/** Public tokenised receipt (shareable by WhatsApp). `?print=1` triggers the browser print dialog, `?copy=1` labels it COPY. */
export default function ReceiptPage({ receipt }: { receipt: Receipt }) {
    const params = new URLSearchParams(
        typeof window === 'undefined' ? '' : window.location.search,
    );
    const shown = { ...receipt, copy: params.get('copy') === '1' };

    useEffect(() => {
        if (params.get('print') === '1') {
            window.setTimeout(() => window.print(), 300);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return (
        <>
            <Head title={`Struk ${receipt.order_number}`} />
            <main className="min-h-dvh bg-slate-100 p-3 print:bg-white print:p-0">
                <ReceiptView r={shown} />
            </main>
        </>
    );
}
