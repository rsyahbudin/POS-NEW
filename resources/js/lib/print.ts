import qz from 'qz-tray';
import { api } from '@/lib/api';
import { kitchenEscPos, receiptEscPos } from '@/lib/escpos';
import type { PaperWidth, TicketData } from '@/lib/escpos';
import type { Receipt } from '@/types/pos';

export type PrinterPrefs = {
    printer: string;
    paper: PaperWidth;
    enabled: boolean;
};

const KEY = 'pos.printer';

/** Printer + paper width are chosen per device (PRD 4.13) and kept in this browser only. */
export function getPrinterPrefs(): PrinterPrefs {
    try {
        return {
            printer: '',
            paper: 80,
            enabled: false,
            ...JSON.parse(localStorage.getItem(KEY) ?? '{}'),
        };
    } catch {
        return { printer: '', paper: 80, enabled: false };
    }
}

export function savePrinterPrefs(p: PrinterPrefs): void {
    try {
        localStorage.setItem(KEY, JSON.stringify(p));
    } catch {
        // ignore
    }
}

let configured = false;

async function connect(): Promise<void> {
    if (!configured) {
        qz.security.setCertificatePromise(
            (resolve: (c: string) => void, reject: (e: unknown) => void) => {
                fetch('/pos/qz/certificate', { credentials: 'same-origin' })
                    .then((r) =>
                        r.ok
                            ? r.text()
                            : Promise.reject(
                                  new Error('Sertifikat QZ belum dibuat.'),
                              ),
                    )
                    .then(resolve, reject);
            },
        );
        qz.security.setSignatureAlgorithm('SHA512');
        qz.security.setSignaturePromise(
            (toSign: string) =>
                (
                    resolve: (s: string) => void,
                    reject: (e: unknown) => void,
                ) => {
                    api<string>('POST', '/pos/qz/sign', {
                        request: toSign,
                    }).then(resolve, reject);
                },
        );
        configured = true;
    }

    if (!qz.websocket.isActive()) {
        await qz.websocket.connect({ retries: 1, delay: 1 });
    }
}

export async function listPrinters(): Promise<string[]> {
    await connect();

    return (await qz.printers.find()) as string[];
}

async function rawPrint(data: string[]): Promise<void> {
    const prefs = getPrinterPrefs();

    if (!prefs.enabled || !prefs.printer) {
        throw new Error('Printer belum diatur di perangkat ini.');
    }

    await connect();
    const config = qz.configs.create(prefs.printer, { encoding: 'CP437' });
    await qz.print(
        config,
        data.map((d) => ({
            type: 'raw',
            format: 'command',
            flavor: 'plain',
            data: d,
        })),
    );
}

/** Browser print fallback: opens the public receipt page and prints it. */
function browserPrint(path: string): void {
    window.open(
        `${path}${path.includes('?') ? '&' : '?'}print=1`,
        '_blank',
        'width=420,height=700',
    );
}

/**
 * Thermal print through QZ Tray; falls back to the browser dialog if the
 * printer or QZ is unavailable (PRD 4.13). Returns which path was used.
 */
export async function printReceipt(
    receipt: Receipt,
): Promise<'qz' | 'browser'> {
    try {
        await rawPrint(receiptEscPos(receipt, getPrinterPrefs().paper));

        return 'qz';
    } catch {
        browserPrint(`${receipt.public_url}${receipt.copy ? '?copy=1' : ''}`);

        return 'browser';
    }
}

export async function printKitchenTicket(ticket: TicketData): Promise<boolean> {
    try {
        await rawPrint(kitchenEscPos(ticket, getPrinterPrefs().paper));

        return true;
    } catch {
        return false;
    }
}
