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
    }[];
    time: string;
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
        ESC + 'a' + '\x00',
        '-'.repeat(w) + '\n',
    ];

    for (const i of t.items) {
        out.push(
            ESC + '!' + '\x10',
            `${i.qty} x ${i.name}\n`,
            ESC + '!' + '\x00',
        );

        for (const m of i.modifiers) {
            out.push(`   + ${m}\n`);
        }

        if (i.note) {
            out.push(`   * ${i.note}\n`);
        }
    }

    out.push('\n\n\n', GS + 'V' + '\x00');

    return out;
}
