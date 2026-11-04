import { cn } from '@/lib/utils';

/** An on/off toggle with a proper switch role, big enough for a thumb. */
export function Switch({
    checked,
    onChange,
    label,
}: {
    checked: boolean;
    onChange: (on: boolean) => void;
    label: string;
}) {
    return (
        <button
            type="button"
            role="switch"
            aria-checked={checked}
            aria-label={label}
            onClick={() => onChange(!checked)}
            className={cn(
                'relative h-7 w-12 shrink-0 rounded-full border transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                checked ? 'border-primary bg-primary' : 'bg-muted',
            )}
        >
            <span
                className={cn(
                    'absolute top-0.5 left-0.5 size-5 rounded-full bg-white shadow transition-transform',
                    checked && 'translate-x-5',
                )}
            />
        </button>
    );
}
