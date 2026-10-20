export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public errors?: Record<string, string[]>,
    ) {
        super(message);
    }
}

function xsrf(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return m ? decodeURIComponent(m[1]) : '';
}

/**
 * JSON call to the Laravel backend. Business-rule failures come back as
 * 422 { message } and surface as ApiError(message) for the UI.
 */
export async function api<T = unknown>(
    method: 'GET' | 'POST' | 'PUT' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<T> {
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrf(),
        },
    };

    if (body !== undefined) {
        init.body = JSON.stringify(body);
    }

    const res = await fetch(url, init);

    if (res.status === 401 || res.status === 419) {
        window.location.href = '/login';
        throw new ApiError('Sesi berakhir. Silakan login lagi.', res.status);
    }

    const text = await res.text();
    const data = text ? JSON.parse(text) : {};

    if (!res.ok) {
        const first = data.errors
            ? (Object.values(data.errors)[0] as string[])[0]
            : null;

        throw new ApiError(
            first ?? data.message ?? 'Terjadi kesalahan.',
            res.status,
            data.errors,
        );
    }

    return data as T;
}
