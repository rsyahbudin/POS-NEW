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
    // Login is rate limited (5/min per user): clear it so many logins in one run don't lock the tests out.
    artisan(['cache:clear']);
    // KDS is optional (off by default); the e2e flow goes through it.
    artisan([
        'tinker',
        '--execute=app(App\\Domain\\Settings\\SettingsRepository::class)->set("kitchen.enabled", true);',
    ]);
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

/** Opens a shift for the seeded cashier and creates a dine-in order on table I-1: 2 Americano + 1 Nasi Goreng. */
export function seedCashierOrder(): void {
    artisan([
        'tinker',
        '--execute=$u=App\\Models\\User::where("email","kasir@pos.test")->first();app(App\\Domain\\Shift\\Actions\\OpenShiftAction::class)->handle($u,0);app(App\\Domain\\Ordering\\Actions\\PlaceOrderAction::class)->handle($u,["type"=>"dine_in","table_id"=>App\\Domain\\Floor\\Models\\Table::where("name","I-1")->value("id"),"items"=>[["product_id"=>App\\Domain\\Catalog\\Models\\Product::where("name","Americano")->value("id"),"qty"=>2],["product_id"=>App\\Domain\\Catalog\\Models\\Product::where("name","Nasi Goreng")->value("id"),"qty"=>1]]]);',
    ]);
}
