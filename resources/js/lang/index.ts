import { en } from '@/lang/en';

/**
 * Indonesian is the source language: the key IS the Indonesian text.
 * Add a language by adding a dictionary (see en.ts) and a case below,
 * no component changes needed.
 */
const dictionaries: Record<string, Record<string, string>> = { en };

function locale(): string {
    try {
        return localStorage.getItem('pos.locale') ?? 'id';
    } catch {
        return 'id';
    }
}

export function setLocale(code: string): void {
    try {
        localStorage.setItem('pos.locale', code);
    } catch {
        // ignore: private mode
    }
}

export function t(
    text: string,
    vars?: Record<string, string | number>,
): string {
    const dict = dictionaries[locale()];
    let out = dict?.[text] ?? text;

    if (vars) {
        for (const [k, v] of Object.entries(vars)) {
            out = out.replaceAll(`{${k}}`, String(v));
        }
    }

    return out;
}
