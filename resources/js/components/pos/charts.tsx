import { rupiah } from '@/lib/format';

export type Datum = { label: string; value: number };

type Fmt = (n: number) => string;

const compact: Fmt = (n) => {
    const a = Math.abs(n);

    if (a >= 1_000_000_000) {
        return `${+(n / 1_000_000_000).toFixed(1)}M`;
    }

    if (a >= 1_000_000) {
        return `${+(n / 1_000_000).toFixed(1)}jt`;
    }

    if (a >= 1_000) {
        return `${+(n / 1_000).toFixed(0)}rb`;
    }

    return String(n);
};

function niceMax(max: number): number {
    if (max <= 0) {
        return 1;
    }

    const pow = 10 ** Math.floor(Math.log10(max));
    const n = max / pow;

    return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * pow;
}

function Empty({ title }: { title: string }) {
    return (
        <p
            className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground"
            role="img"
            aria-label={title}
        >
            Belum ada data untuk grafik.
        </p>
    );
}

/** Accessible text alternative: screen readers get the numbers, not the picture. */
function DataTable({
    title,
    rows,
    fmt,
}: {
    title: string;
    rows: Datum[];
    fmt: Fmt;
}) {
    return (
        <table className="sr-only">
            <caption>{title}</caption>
            <tbody>
                {rows.map((r) => (
                    <tr key={r.label}>
                        <th scope="row">{r.label}</th>
                        <td>{fmt(r.value)}</td>
                    </tr>
                ))}
            </tbody>
        </table>
    );
}

/** Vertical bars (time buckets: hours, days). */
export function ColumnChart({
    title,
    data,
    fmt = rupiah,
    color = 'var(--color-primary)',
    height = 190,
}: {
    title: string;
    data: Datum[];
    fmt?: Fmt;
    color?: string;
    height?: number;
}) {
    if (data.length === 0) {
        return <Empty title={title} />;
    }

    const W = 640;
    const pad = { l: 44, r: 8, t: 10, b: 26 };
    const max = niceMax(Math.max(...data.map((d) => d.value), 0));
    const innerW = W - pad.l - pad.r;
    const innerH = height - pad.t - pad.b;
    const step = innerW / data.length;
    const bar = Math.min(40, step * 0.7);
    const every = Math.ceil(data.length / 12);

    return (
        <figure>
            <svg
                viewBox={`0 0 ${W} ${height}`}
                className="w-full"
                role="img"
                aria-label={title}
            >
                {[0, 0.25, 0.5, 0.75, 1].map((f) => {
                    const y = pad.t + innerH * (1 - f);

                    return (
                        <g key={f}>
                            <line
                                x1={pad.l}
                                x2={W - pad.r}
                                y1={y}
                                y2={y}
                                stroke="currentColor"
                                strokeOpacity={0.12}
                            />
                            <text
                                x={pad.l - 6}
                                y={y + 3}
                                textAnchor="end"
                                fontSize={10}
                                fill="currentColor"
                                fillOpacity={0.6}
                            >
                                {compact(max * f)}
                            </text>
                        </g>
                    );
                })}
                {data.map((d, i) => {
                    const h = (Math.max(d.value, 0) / max) * innerH;
                    const x = pad.l + step * i + (step - bar) / 2;

                    return (
                        <g key={d.label}>
                            <rect
                                x={x}
                                y={pad.t + innerH - h}
                                width={bar}
                                height={Math.max(h, d.value > 0 ? 1 : 0)}
                                rx={3}
                                fill={color}
                            >
                                <title>{`${d.label}: ${fmt(d.value)}`}</title>
                            </rect>
                            {i % every === 0 && (
                                <text
                                    x={x + bar / 2}
                                    y={height - 8}
                                    textAnchor="middle"
                                    fontSize={10}
                                    fill="currentColor"
                                    fillOpacity={0.7}
                                >
                                    {d.label.length > 6 &&
                                    /^\d{4}-/.test(d.label)
                                        ? d.label.slice(5)
                                        : d.label}
                                </text>
                            )}
                        </g>
                    );
                })}
            </svg>
            <DataTable title={title} rows={data} fmt={fmt} />
        </figure>
    );
}

/** Line with soft area (trends over days). */
export function LineChart({
    title,
    data,
    fmt = rupiah,
    color = 'var(--color-primary)',
    height = 190,
}: {
    title: string;
    data: Datum[];
    fmt?: Fmt;
    color?: string;
    height?: number;
}) {
    if (data.length === 0) {
        return <Empty title={title} />;
    }

    const W = 640;
    const pad = { l: 44, r: 12, t: 10, b: 26 };
    const max = niceMax(Math.max(...data.map((d) => d.value), 0));
    const innerW = W - pad.l - pad.r;
    const innerH = height - pad.t - pad.b;
    const x = (i: number) =>
        pad.l +
        (data.length === 1 ? innerW / 2 : (innerW * i) / (data.length - 1));
    const y = (v: number) => pad.t + innerH * (1 - Math.max(v, 0) / max);
    const path = data
        .map(
            (d, i) =>
                `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)},${y(d.value).toFixed(1)}`,
        )
        .join(' ');
    const area = `${path} L${x(data.length - 1).toFixed(1)},${pad.t + innerH} L${x(0).toFixed(1)},${pad.t + innerH} Z`;
    const every = Math.ceil(data.length / 8);

    return (
        <figure>
            <svg
                viewBox={`0 0 ${W} ${height}`}
                className="w-full"
                role="img"
                aria-label={title}
            >
                {[0, 0.5, 1].map((f) => (
                    <g key={f}>
                        <line
                            x1={pad.l}
                            x2={W - pad.r}
                            y1={pad.t + innerH * (1 - f)}
                            y2={pad.t + innerH * (1 - f)}
                            stroke="currentColor"
                            strokeOpacity={0.12}
                        />
                        <text
                            x={pad.l - 6}
                            y={pad.t + innerH * (1 - f) + 3}
                            textAnchor="end"
                            fontSize={10}
                            fill="currentColor"
                            fillOpacity={0.6}
                        >
                            {compact(max * f)}
                        </text>
                    </g>
                ))}
                <path d={area} fill={color} fillOpacity={0.12} />
                <path
                    d={path}
                    fill="none"
                    stroke={color}
                    strokeWidth={2}
                    strokeLinejoin="round"
                />
                {data.map((d, i) => (
                    <g key={d.label}>
                        <circle cx={x(i)} cy={y(d.value)} r={3.5} fill={color}>
                            <title>{`${d.label}: ${fmt(d.value)}`}</title>
                        </circle>
                        {i % every === 0 && (
                            <text
                                x={x(i)}
                                y={height - 8}
                                textAnchor="middle"
                                fontSize={10}
                                fill="currentColor"
                                fillOpacity={0.7}
                            >
                                {/^\d{4}-\d{2}-\d{2}$/.test(d.label)
                                    ? d.label.slice(5)
                                    : d.label}
                            </text>
                        )}
                    </g>
                ))}
            </svg>
            <DataTable title={title} rows={data} fmt={fmt} />
        </figure>
    );
}

/** Horizontal bars for rankings (top products, per category, per method...). Several series = grouped. */
export function BarList({
    title,
    data,
    fmt = rupiah,
    color = 'var(--color-primary)',
    max: limit = 10,
}: {
    title: string;
    data: Datum[];
    fmt?: Fmt;
    color?: string;
    max?: number;
}) {
    if (data.length === 0) {
        return <Empty title={title} />;
    }

    const rows = data.slice(0, limit);
    const top = Math.max(...rows.map((r) => Math.abs(r.value)), 1);

    return (
        <figure role="img" aria-label={title} className="grid gap-1.5">
            {rows.map((r) => (
                <div
                    key={r.label}
                    className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-2 text-sm"
                >
                    <span className="truncate" title={r.label}>
                        {r.label}
                    </span>
                    <span className="h-3 overflow-hidden rounded-full bg-muted">
                        <span
                            className="block h-full rounded-full"
                            style={{
                                width: `${Math.max(2, (Math.abs(r.value) / top) * 100)}%`,
                                background: color,
                            }}
                        />
                    </span>
                    <span className="text-right text-xs tabular-nums">
                        {fmt(r.value)}
                    </span>
                </div>
            ))}
            {data.length > limit && (
                <figcaption className="text-xs text-muted-foreground">
                    +{data.length - limit} lainnya di tabel
                </figcaption>
            )}
        </figure>
    );
}

/** Two series side by side per category (e.g. pendapatan vs beban, masuk vs keluar). */
export function PairedBars({
    title,
    labels,
    a,
    b,
    fmt = rupiah,
}: {
    title: string;
    labels: string[];
    a: { name: string; values: number[]; color: string };
    b: { name: string; values: number[]; color: string };
    fmt?: Fmt;
}) {
    if (labels.length === 0) {
        return <Empty title={title} />;
    }

    const max = Math.max(...a.values, ...b.values, 1);

    return (
        <figure role="img" aria-label={title} className="grid gap-3">
            <div className="flex gap-4 text-xs">
                {[a, b].map((s) => (
                    <span key={s.name} className="flex items-center gap-1">
                        <span
                            className="size-2.5 rounded-sm"
                            style={{ background: s.color }}
                        />
                        {s.name}
                    </span>
                ))}
            </div>
            {labels.map((l, i) => (
                <div key={l} className="grid gap-1">
                    <span className="text-sm">{l}</span>
                    {[a, b].map((s) => (
                        <div
                            key={s.name}
                            className="grid grid-cols-[1fr_auto] items-center gap-2"
                        >
                            <span className="h-3 overflow-hidden rounded-full bg-muted">
                                <span
                                    className="block h-full rounded-full"
                                    style={{
                                        width: `${Math.max(1, (s.values[i] / max) * 100)}%`,
                                        background: s.color,
                                    }}
                                />
                            </span>
                            <span className="text-xs tabular-nums">
                                {fmt(s.values[i])}
                            </span>
                        </div>
                    ))}
                </div>
            ))}
        </figure>
    );
}

export function ChartCard({
    title,
    children,
    hint,
}: {
    title: string;
    children: React.ReactNode;
    hint?: string;
}) {
    return (
        <section className="rounded-xl border bg-card p-4">
            <h2 className="font-semibold">{title}</h2>
            {hint && (
                <p className="mb-2 text-xs text-muted-foreground">{hint}</p>
            )}
            <div className="mt-2 text-foreground">{children}</div>
        </section>
    );
}
