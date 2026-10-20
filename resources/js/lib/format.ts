export function rupiah(amount: number | null | undefined): string {
    const n = Math.trunc(amount ?? 0);
    const sign = n < 0 ? '-' : '';

    return `${sign}Rp${Math.abs(n).toLocaleString('id-ID')}`;
}

const MONTHS = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'Mei',
    'Jun',
    'Jul',
    'Agu',
    'Sep',
    'Okt',
    'Nov',
    'Des',
];

/** 20 Okt 2026 */
export function dateId(value: string | Date | null | undefined): string {
    if (!value) {
        return '-';
    }

    const d =
        typeof value === 'string'
            ? new Date(value.length === 10 ? `${value}T00:00:00` : value)
            : value;

    return `${d.getDate()} ${MONTHS[d.getMonth()]} ${d.getFullYear()}`;
}

export function timeId(value: string | Date | null | undefined): string {
    if (!value) {
        return '-';
    }

    const d = typeof value === 'string' ? new Date(value) : value;

    return d.toLocaleTimeString('id-ID', {
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function ageMinutes(iso: string, now: number = Date.now()): number {
    return Math.max(0, Math.floor((now - new Date(iso).getTime()) / 60000));
}

export function uuid(): string {
    return crypto.randomUUID
        ? crypto.randomUUID()
        : `${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
