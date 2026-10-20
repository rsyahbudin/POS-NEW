import { defineConfig } from '@playwright/test';

/*
 * End-to-end: QR order -> kasir konfirmasi -> KDS -> bayar -> struk,
 * di viewport 360 / 768 / 1280 px (PRD bagian 10).
 *
 * Berjalan terhadap database `pos_testing` (tidak menyentuh data dev) pada
 * server PHP sendiri di port 8123. Memakai Google Chrome yang sudah terpasang
 * (`channel: 'chrome'`), jadi tidak ada unduhan browser.
 *
 *   npm run e2e
 */
export const E2E_PORT = 8123;

export const e2eEnv = {
    APP_ENV: 'local',
    APP_DEBUG: 'false',
    APP_URL: `http://127.0.0.1:${E2E_PORT}`,
    DB_DATABASE: 'pos_testing',
    SESSION_DRIVER: 'file',
    CACHE_STORE: 'file',
    QUEUE_CONNECTION: 'sync',
    BROADCAST_CONNECTION: 'null',
    PHP_CLI_SERVER_WORKERS: '4',
};

export default defineConfig({
    testDir: 'e2e',
    outputDir: 'test-results',
    workers: 1,
    retries: 1,
    fullyParallel: false,
    timeout: 120_000,
    expect: { timeout: 15_000 },
    reporter: [['list']],
    globalSetup: './e2e/global-setup.ts',
    globalTeardown: './e2e/global-teardown.ts',
    use: {
        baseURL: `http://127.0.0.1:${E2E_PORT}`,
        channel: 'chrome',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        locale: 'id-ID',
    },
    projects: [
        {
            name: 'hp-360',
            use: {
                viewport: { width: 360, height: 740 },
                isMobile: true,
                hasTouch: true,
            },
        },
        { name: 'tablet-768', use: { viewport: { width: 768, height: 1024 } } },
        {
            name: 'desktop-1280',
            use: { viewport: { width: 1280, height: 800 } },
        },
    ],
    webServer: {
        command: `php -S 127.0.0.1:${E2E_PORT} ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`,
        cwd: 'public',
        url: `http://127.0.0.1:${E2E_PORT}/up`,
        env: { ...(process.env as Record<string, string>), ...e2eEnv },
        stdout: 'ignore',
        stderr: 'pipe',
        reuseExistingServer: false,
        timeout: 30_000,
    },
});
