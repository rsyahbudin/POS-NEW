import { execFileSync } from 'node:child_process';
import { e2eEnv } from '../playwright.config';
import type { Page } from '@playwright/test';

const env = { ...process.env, ...e2eEnv };

function artisan(args: string[]): string {
    return execFileSync('php', ['artisan', ...args], {
        env,
        encoding: 'utf8',
        cwd: process.cwd(),
    });
}

/** Fresh seeded database; returns the QR token of table I-1. */
export function resetDatabase(): { token: string } {
    artisan(['migrate:fresh', '--seed', '--force', '--no-interaction']);
    const token = artisan([
        'tinker',
        '--execute=echo App\\Domain\\Floor\\Models\\Table::where("name","I-1")->value("qr_token");',
    ]).trim();

    return { token };
}

export async function login(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByPlaceholder('email@example.com').fill(email);
    await page.getByPlaceholder('Password').fill('password');
    await page.getByRole('button', { name: 'Log in' }).click();
    await page.waitForURL((url) => !url.pathname.startsWith('/login'));
}

/** PRD 6.1: no horizontal scroll on any page, checked at the project's viewport. */
export async function expectNoHorizontalScroll(page: Page): Promise<void> {
    const overflow = await page.evaluate(
        () => document.documentElement.scrollWidth - window.innerWidth,
    );
    if (overflow > 1) {
        throw new Error(
            `Horizontal scroll: ${overflow}px wider than viewport on ${page.url()}`,
        );
    }
}
