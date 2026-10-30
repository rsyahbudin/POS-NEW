import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

const WEEKDAYS = ['Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab', 'Min'];
const pad = (n: number) => String(n).padStart(2, '0');

/** A Monday-first month grid. `render` draws the content of one day; `selected` and `onSelect` make days tappable. */
export function MonthCalendar({
    month,
    today,
    render,
    selected,
    onSelect,
}: {
    month: string;
    today: string;
    render: (date: string) => ReactNode;
    selected?: string | null;
    onSelect?: (date: string) => void;
}) {
    const [y, m] = month.split('-').map(Number);
    const first = new Date(y, m - 1, 1);
    const lead = (first.getDay() + 6) % 7;
    const count = new Date(y, m, 0).getDate();
    const cells: (string | null)[] = [
        ...Array<null>(lead).fill(null),
        ...Array.from({ length: count }, (_, i) => `${month}-${pad(i + 1)}`),
    ];
    while (cells.length % 7 !== 0) {
        cells.push(null);
    }

    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <div className="grid grid-cols-7 border-b bg-muted/40 text-center text-xs font-medium text-muted-foreground">
                {WEEKDAYS.map((d) => (
                    <div key={d} className="py-2">
                        {d}
                    </div>
                ))}
            </div>
            <div className="grid grid-cols-7">
                {cells.map((date, i) =>
                    date === null ? (
                        <div
                            key={`e${i}`}
                            className="min-h-20 border-r border-b bg-muted/20 last:border-r-0 sm:min-h-24"
                        />
                    ) : (
                        <button
                            key={date}
                            type="button"
                            disabled={!onSelect}
                            onClick={() => onSelect?.(date)}
                            aria-label={date}
                            aria-pressed={selected === date}
                            className={cn(
                                'grid min-h-20 content-start gap-0.5 border-r border-b p-1 text-left align-top sm:min-h-24 sm:p-1.5',
                                (i + 1) % 7 === 0 && 'border-r-0',
                                selected === date && 'bg-accent',
                                onSelect && 'enabled:hover:bg-accent/60',
                            )}
                        >
                            <span
                                className={cn(
                                    'inline-grid size-6 place-items-center rounded-full text-xs',
                                    date === today
                                        ? 'bg-primary font-semibold text-primary-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {Number(date.slice(8))}
                            </span>
                            {render(date)}
                        </button>
                    ),
                )}
            </div>
        </div>
    );
}
