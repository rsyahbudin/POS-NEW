import { createInertiaApp } from '@inertiajs/react';

/*
 * Lean entry for public pages (QR menu, shared receipts): no sidebar layout,
 * no toasts, no Radix. Keeps the QR bundle inside the 150 KB gzip budget.
 */
void createInertiaApp({
    title: (title) => (title ? `${title} - POS` : 'POS'),
    progress: false,
});
