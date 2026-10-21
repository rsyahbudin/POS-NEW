import qz from 'qz-tray';
import { api } from '@/lib/api';
import { kitchenEscPos, receiptEscPos } from '@/lib/escpos';
import type { PaperWidth, TicketData } from '@/lib/escpos';
import type { Receipt } from '@/types/pos';

/** qz: desktop with QZ Tray. bluetooth: Web Bluetooth (BLE) printer. rawbt: Android RawBT app. */
export type PrintMethod = 'qz' | 'bluetooth' | 'rawbt';

export type PrinterPrefs = {
    printer: string;
    paper: PaperWidth;
    enabled: boolean;
    method: PrintMethod;
};

const KEY = 'pos.printer';

/** Printer + paper width are chosen per device (PRD 4.13) and kept in this browser only. */
export function getPrinterPrefs(): PrinterPrefs {
    try {
        return {
            printer: '',
            paper: 80,
            enabled: false,
            method: 'qz',
            ...JSON.parse(localStorage.getItem(KEY) ?? '{}'),
        };
    } catch {
        return { printer: '', paper: 80, enabled: false, method: 'qz' };
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

// ---- Web Bluetooth (BLE thermal printers; Chrome on Android/desktop, Bluefy on iPhone) ----

type BtCharacteristic = {
    properties: { write: boolean; writeWithoutResponse: boolean };
    writeValue: (v: Uint8Array) => Promise<void>;
    writeValueWithoutResponse?: (v: Uint8Array) => Promise<void>;
};
type BtService = { getCharacteristics: () => Promise<BtCharacteristic[]> };
type BtServer = { getPrimaryServices: () => Promise<BtService[]> };
type BtDevice = {
    name?: string;
    gatt?: { connected: boolean; connect: () => Promise<BtServer> };
};
type BluetoothApi = {
    requestDevice: (o: {
        acceptAllDevices: boolean;
        optionalServices: string[];
    }) => Promise<BtDevice>;
};

const PRINTER_SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
    '0000ae30-0000-1000-8000-00805f9b34fb',
];

let btDevice: BtDevice | null = null;
let btChar: BtCharacteristic | null = null;

function bluetoothApi(): BluetoothApi | null {
    return (
        (navigator as unknown as { bluetooth?: BluetoothApi }).bluetooth ?? null
    );
}

export function bluetoothSupported(): boolean {
    return bluetoothApi() !== null;
}

export function bluetoothPrinterName(): string | null {
    return btDevice?.name ?? null;
}

/** Must run from a tap: the browser shows its Bluetooth chooser. */
export async function pairBluetoothPrinter(): Promise<string> {
    const api = bluetoothApi();

    if (!api) {
        throw new Error('Browser ini tidak mendukung Bluetooth.');
    }

    btDevice = await api.requestDevice({
        acceptAllDevices: true,
        optionalServices: PRINTER_SERVICES,
    });
    btChar = null;
    await btCharacteristic();

    return btDevice.name ?? 'Printer';
}

async function btCharacteristic(): Promise<BtCharacteristic> {
    if (btChar && btDevice?.gatt?.connected) {
        return btChar;
    }

    if (!btDevice?.gatt) {
        throw new Error('Printer Bluetooth belum dihubungkan.');
    }

    const server = await btDevice.gatt.connect();

    for (const service of await server.getPrimaryServices()) {
        for (const c of await service.getCharacteristics()) {
            if (c.properties.write || c.properties.writeWithoutResponse) {
                btChar = c;

                return c;
            }
        }
    }

    throw new Error('Printer ini tidak punya jalur cetak yang dikenali.');
}

function toBytes(data: string[]): Uint8Array {
    const text = data.join('');
    const out = new Uint8Array(text.length);

    for (let i = 0; i < text.length; i++) {
        const code = text.charCodeAt(i);
        out[i] = code < 256 ? code : 63; // non-Latin1 becomes "?"
    }

    return out;
}

async function bluetoothPrint(data: string[]): Promise<void> {
    const bytes = toBytes(data);
    const c = await btCharacteristic();
    const chunk = 100;

    for (let i = 0; i < bytes.length; i += chunk) {
        const part = bytes.slice(i, i + chunk);

        if (c.properties.writeWithoutResponse && c.writeValueWithoutResponse) {
            await c.writeValueWithoutResponse(part);
        } else {
            await c.writeValue(part);
        }

        await new Promise((r) => setTimeout(r, 25));
    }
}

/** RawBT (Android): hands the ESC/POS bytes to the app, which prints over classic Bluetooth. */
function rawbtPrint(data: string[]): void {
    const bytes = toBytes(data);
    let binary = '';
    bytes.forEach((b) => (binary += String.fromCharCode(b)));
    window.location.href = `rawbt:base64,${btoa(binary)}`;
}

async function rawPrint(data: string[]): Promise<void> {
    const prefs = getPrinterPrefs();

    if (!prefs.enabled) {
        throw new Error('Printer belum diatur di perangkat ini.');
    }

    if (prefs.method === 'bluetooth') {
        return bluetoothPrint(data);
    }

    if (prefs.method === 'rawbt') {
        return rawbtPrint(data);
    }

    if (!prefs.printer) {
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
 * Thermal print through QZ Tray, Bluetooth or RawBT; falls back to the browser dialog if the
 * printer is unavailable (PRD 4.13). Returns which path was used.
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

/** Short test slip so the cashier can check connection and paper width. */
export async function printTest(prefs: PrinterPrefs): Promise<void> {
    const w = prefs.paper === 58 ? 32 : 48;
    const line = '-'.repeat(w);
    const data = [
        '\x1b@',
        '\x1ba\x01',
        'TES CETAK\n',
        `${prefs.paper} mm - ${prefs.method}\n`,
        `${line}\n`,
        'Rp 12.345  Terima kasih\n',
        `${line}\n\n\n\n`,
    ];
    const saved = getPrinterPrefs();
    savePrinterPrefs({ ...prefs, enabled: true });

    try {
        await rawPrint(data);
    } finally {
        savePrinterPrefs(saved);
    }
}
