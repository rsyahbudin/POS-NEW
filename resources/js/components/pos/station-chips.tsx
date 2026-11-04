import { Check } from 'lucide-react';
import { cn } from '@/lib/utils';

/** Pick any number of stations as tap-to-toggle chips. */
export function StationChips({
    stations,
    value,
    onChange,
}: {
    stations: { id: number; name: string }[];
    value: number[];
    onChange: (ids: number[]) => void;
}) {
    if (stations.length === 0) {
        return (
            <p className="text-sm text-muted-foreground">
                Belum ada station. Buat di Menu &gt; Station.
            </p>
        );
    }

    return (
        <div className="flex flex-wrap gap-2">
            {stations.map((s) => {
                const on = value.includes(s.id);

                return (
                    <button
                        key={s.id}
                        type="button"
                        aria-pressed={on}
                        onClick={() =>
                            onChange(
                                on
                                    ? value.filter((x) => x !== s.id)
                                    : [...value, s.id],
                            )
                        }
                        className={cn(
                            'inline-flex h-10 items-center gap-1.5 rounded-full border px-3 text-sm transition-colors',
                            on
                                ? 'border-primary bg-primary text-primary-foreground'
                                : 'bg-card hover:bg-muted',
                        )}
                    >
                        {on && <Check className="size-4" />}
                        {s.name}
                    </button>
                );
            })}
        </div>
    );
}
