import { rupiah } from '@/lib/format';
import type { Receipt } from '@/types/pos';

/** On-screen receipt, also used by the public tokenised receipt page and browser print fallback. */
export function ReceiptView({ r }: { r: Receipt }) {
    return (
        <div className="mx-auto w-full max-w-[380px] bg-white p-4 font-mono text-[13px] leading-snug text-black">
            <div className="text-center">
                <p className="text-base font-bold">{r.outlet.name}</p>
                {r.outlet.address && <p>{r.outlet.address}</p>}
                {r.outlet.npwp && <p>NPWP {r.outlet.npwp}</p>}
                {r.header && <p>{r.header}</p>}
                {r.copy && <p className="mt-1 font-bold">*** COPY ***</p>}
            </div>
            <hr className="my-2 border-dashed border-black" />
            <Line l="No" r={r.order_number} />
            <Line l="Tanggal" r={r.date} />
            {r.cashier && <Line l="Kasir" r={r.cashier} />}
            {r.table && <Line l="Meja" r={r.table} />}
            {r.call_number && <Line l="Panggil" r={r.call_number} />}
            {r.label && <Line l="Tagihan" r={r.label} />}
            <hr className="my-2 border-dashed border-black" />
            {r.items.map((i, idx) => (
                <div key={idx} className="mb-1">
                    <p>{i.name}</p>
                    {i.modifiers.map((m) => (
                        <p key={m} className="pl-3">
                            + {m}
                        </p>
                    ))}
                    <Line
                        l={`  ${i.qty} x ${rupiah(i.unit_price)}`}
                        r={rupiah(i.line_total)}
                    />
                </div>
            ))}
            <hr className="my-2 border-dashed border-black" />
            <Line l="Subtotal" r={rupiah(r.subtotal)} />
            {r.discount_total > 0 && (
                <Line l="Diskon" r={`-${rupiah(r.discount_total)}`} />
            )}
            {r.charges.map((c) => (
                <Line
                    key={c.name}
                    l={c.is_inclusive ? `${c.name} (incl.)` : c.name}
                    r={rupiah(c.amount)}
                />
            ))}
            {r.rounding !== 0 && <Line l="Pembulatan" r={rupiah(r.rounding)} />}
            <p className="mt-1 flex justify-between text-base font-bold">
                <span>TOTAL</span>
                <span>{rupiah(r.grand_total)}</span>
            </p>
            {r.payments.map((p, i) => (
                <Line key={i} l={p.method} r={rupiah(p.tendered)} />
            ))}
            {r.change > 0 && <Line l="Kembali" r={rupiah(r.change)} />}
            {r.footer && <p className="mt-3 text-center">{r.footer}</p>}
        </div>
    );
}

function Line({ l, r }: { l: string; r: string }) {
    return (
        <p className="flex justify-between gap-2">
            <span className="whitespace-pre">{l}</span>
            <span>{r}</span>
        </p>
    );
}
