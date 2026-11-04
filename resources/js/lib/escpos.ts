import { rupiah } from '@/lib/format';
import type { Receipt } from '@/types/pos';

const ESC = '\x1b';
const GS = '\x1d';

export type PaperWidth = 58 | 80;

export const columns = (w: PaperWidth): number => (w === 58 ? 32 : 48);

function pad2(left: string, right: string, width: number): string {
    const space = Math.max(1, width - left.length - right.length);

    return (
        left.slice(0, Math.max(0, width - right.length - 1)) +
        ' '.repeat(space) +
        right
    );
}

/** Plain ESC/POS payload for a customer receipt. */
export function receiptEscPos(r: Receipt, paper: PaperWidth): string[] {
    const w = columns(paper);
    const line = '-'.repeat(w);
    const out: string[] = [
        ESC + '@',
        ESC + 'a' + '\x01',
        ESC + 'E' + '\x01',
        `${r.outlet.name ?? ''}\n`,
        ESC + 'E' + '\x00',
    ];

    if (r.outlet.address) {
        out.push(`${r.outlet.address}\n`);
    }

    if (r.outlet.npwp) {
        out.push(`NPWP ${r.outlet.npwp}\n`);
    }

    if (r.header) {
        out.push(`${r.header}\n`);
    }

    if (r.title) {
        out.push(
            ESC + 'E' + '\x01',
            `*** ${r.title} ***\n`,
            ESC + 'E' + '\x00',
        );
    }

    if (r.copy) {
        out.push(ESC + 'E' + '\x01', '*** COPY ***\n', ESC + 'E' + '\x00');
    }

    out.push(ESC + 'a' + '\x00', `${line}\n`);
    out.push(pad2('No', r.order_number, w) + '\n');
    out.push(pad2('Tanggal', r.date, w) + '\n');

    if (r.cashier) {
        out.push(pad2('Kasir', r.cashier, w) + '\n');
    }

    if (r.table) {
        out.push(pad2('Meja', r.table, w) + '\n');
    }

    if (r.call_number) {
        out.push(pad2('Panggil', r.call_number, w) + '\n');
    }

    if (r.label) {
        out.push(pad2('Tagihan', r.label, w) + '\n');
    }

    out.push(`${line}\n`);

    for (const i of r.items) {
        out.push(`${i.name}\n`);

        for (const m of i.modifiers) {
            out.push(`  + ${m}\n`);
        }

        out.push(
            pad2(
                `  ${i.qty} x ${rupiah(i.unit_price)}`,
                rupiah(i.line_total),
                w,
            ) + '\n',
        );
    }

    out.push(`${line}\n`, pad2('Subtotal', rupiah(r.subtotal), w) + '\n');

    if (r.discount_total > 0) {
        out.push(pad2('Diskon', `-${rupiah(r.discount_total)}`, w) + '\n');
    }

    for (const c of r.charges) {
        out.push(
            pad2(
                c.is_inclusive ? `${c.name} (incl.)` : c.name,
                rupiah(c.amount),
                w,
            ) + '\n',
        );
    }

    if (r.rounding !== 0) {
        out.push(pad2('Pembulatan', rupiah(r.rounding), w) + '\n');
    }

    out.push(
        ESC + 'E' + '\x01',
        pad2('TOTAL', rupiah(r.grand_total), w) + '\n',
        ESC + 'E' + '\x00',
    );

    for (const p of r.payments) {
        out.push(pad2(p.method, rupiah(p.tendered), w) + '\n');
    }

    if (r.change > 0) {
        out.push(pad2('Kembali', rupiah(r.change), w) + '\n');
    }

    if (r.footer) {
        out.push(ESC + 'a' + '\x01', `\n${r.footer}\n`);
    }

    if (r.show_link) {
        out.push(ESC + 'a' + '\x01', `\n${r.public_url}\n`);
    }

    out.push('\n\n\n', GS + 'V' + '\x00');

    return out;
}

export type TicketData = {
    label: string;
    number: string;
    items: {
        qty: number;
        name: string;
        modifiers: string[];
        note: string | null;
        /** Name of the bundle this line belongs to, when it was ordered as part of one. */
        bundle?: string | null;
    }[];
    time: string;
    /** Dine-in, take away or online. */
    type?: string | null;
    /** Guest name, when one was given or the guest typed it in the QR menu. */
    customer?: string | null;
    /** Where the order came from: the guest's phone (QR) or a staff member (POS). */
    source?: string | null;
    /** Staff member who entered the order; empty for QR. */
    by?: string | null;
    /** Which batch of the open order this is; the second one onward prints as an addition. */
    batch?: number | null;
    /** The ticket is sent again by hand, so the kitchen does not cook it twice. */
    reprint?: boolean;
};

export function kitchenEscPos(t: TicketData, paper: PaperWidth): string[] {
    const w = columns(paper);
    const out: string[] = [
        ESC + '@',
        ESC + 'a' + '\x01',
        ESC + '!' + '\x30',
        `${t.label}\n`,
        ESC + '!' + '\x00',
        `${t.number}  ${t.time}\n`,
    ];

    if (t.reprint) {
        out.push(
            ESC + '!' + '\x08',
            '** CETAK ULANG: JANGAN DIBUAT DOBEL **\n',
            ESC + '!' + '\x00',
        );
    }

    if (t.type) {
        out.push(ESC + '!' + '\x08', `${t.type}\n`, ESC + '!' + '\x00');
    }

    if (t.batch && t.batch > 1) {
        out.push(
            ESC + '!' + '\x08',
            `** TAMBAHAN #${t.batch} **\n`,
            ESC + '!' + '\x00',
        );
    }

    out.push(ESC + 'a' + '\x00');

    if (t.customer) {
        out.push(
            ESC + '!' + '\x10',
            `Nama: ${t.customer}\n`,
            ESC + '!' + '\x00',
        );
    }

    if (t.source) {
        out.push(
            `Dari: ${t.source === 'QR' ? 'QR pelanggan' : (t.by ?? 'Kasir')}\n`,
        );
    }

    out.push('-'.repeat(w) + '\n');

    for (const i of t.items) {
        out.push(
            ESC + '!' + '\x10',
            `${i.qty} x ${i.name}\n`,
            ESC + '!' + '\x00',
        );

        if (i.bundle) {
            out.push(`   Paket: ${i.bundle}\n`);
        }

        for (const m of i.modifiers) {
            out.push(`   + ${m}\n`);
        }

        if (i.note) {
            out.push(
                ESC + '!' + '\x08',
                `   CATATAN: ${i.note}\n`,
                ESC + '!' + '\x00',
            );
        }
    }

    out.push(
        '-'.repeat(w) + '\n',
        `Total ${t.items.reduce((n, i) => n + i.qty, 0)} item\n`,
    );
    out.push('\n\n\n', GS + 'V' + '\x00');

    return out;
}
