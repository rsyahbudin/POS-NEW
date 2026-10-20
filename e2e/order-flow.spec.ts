import { expect, test } from '@playwright/test';
import { expectNoHorizontalScroll, login, resetDatabase } from './support';
import type { Browser, Page } from '@playwright/test';

/**
 * Alur utama (PRD bagian 10): customer pesan lewat QR -> kasir konfirmasi ->
 * dapur menandai siap -> kasir menerima pembayaran tunai -> struk.
 * Realtime (Reverb) sengaja mati di sini: layar memuat ulang state, seperti fallback polling.
 */
test('QR order -> KDS -> bayar -> struk', async ({
    browser,
    page,
    baseURL,
}, testInfo) => {
    const { token } = resetDatabase();
    const viewport = testInfo.project.use.viewport ?? {
        width: 1280,
        height: 800,
    };
    const narrow = viewport.width < 768;

    // --- kasir membuka shift
    await login(page, 'kasir@pos.test');
    await page.goto('/shift');
    await page.getByRole('textbox').first().fill('100000');
    await page.getByRole('button', { name: 'Buka shift' }).click();
    await expect(page.getByText('Penerimaan dari penjualan')).toBeVisible();
    await expectNoHorizontalScroll(page);

    // --- customer: scan QR, pesan 1 Americano
    const guest = await newPage(
        browser,
        baseURL!,
        viewport,
        testInfo.project.use.isMobile ?? false,
    );
    await guest.goto(`/m/${token}`);
    await expect(guest.getByText('Pembayaran di kasir.')).toBeVisible();
    await expectNoHorizontalScroll(guest);
    await guest.getByRole('button', { name: /Americano/ }).click();
    await guest.getByRole('button', { name: /^Tambah ·/ }).click();
    await guest.getByRole('button', { name: /Lihat pesanan/ }).click();
    await guest.getByPlaceholder('Contoh: Rani').fill('Rani');
    await guest.getByRole('button', { name: /Kirim Pesanan/ }).click();
    await expect(guest.getByText('Menunggu konfirmasi kasir')).toBeVisible();
    await expect(guest.getByText('Menunggu Konfirmasi').first()).toBeVisible();

    // --- kasir: pesanan QR muncul, konfirmasi
    await page.goto('/pos');
    await expectNoHorizontalScroll(page);
    await page.getByRole('button', { name: /Pesanan QR: 1/ }).click();
    await expect(page.getByText('I-1 · Rani')).toBeVisible();
    await page.getByRole('button', { name: 'Konfirmasi', exact: true }).click();
    await expect(page.getByText('Dikirim ke dapur.')).toBeVisible();
    await page.keyboard.press('Escape');

    // --- dapur: tiket muncul, Baru -> Dibuat -> Siap
    const kitchen = await newPage(
        browser,
        baseURL!,
        viewport,
        testInfo.project.use.isMobile ?? false,
    );
    await login(kitchen, 'dapur@pos.test');
    await kitchen.goto('/kds');
    await expectNoHorizontalScroll(kitchen);
    const item = kitchen.getByRole('button', { name: /1 Americano/ });
    await expect(item).toBeVisible();
    await item.click();
    await expect(
        kitchen.getByRole('button', { name: /1 Americano: Tandai siap/ }),
    ).toBeVisible();
    await kitchen
        .getByRole('button', { name: /1 Americano: Tandai siap/ })
        .click();
    await expect(
        kitchen.getByRole('button', { name: /1 Americano: Siap/ }),
    ).toBeDisabled();

    // --- kasir: buka meja I-1, bayar tunai pas
    await page.goto('/pos');
    await page.getByRole('button', { name: /^I-1/ }).click();
    if (narrow) {
        await page.getByRole('button', { name: /ORD-/ }).click();
    }
    await page.getByRole('button', { name: /^Bayar .*Rp22\.000/ }).click();
    await page.getByRole('button', { name: 'Uang pas' }).click();

    // struk terbuka di jendela baru (QZ Tray tidak ada -> fallback cetak browser)
    const popup = page.context().waitForEvent('page');
    await page.getByRole('button', { name: /^Bayar Rp22\.000$/ }).click();
    const receipt = await popup;
    await receipt.waitForLoadState('domcontentloaded');
    await expect(receipt.getByText('TOTAL', { exact: true })).toBeVisible();
    await expect(receipt.getByText('Rp22.000').first()).toBeVisible();
    await expect(receipt.getByText('Tunai', { exact: true })).toBeVisible();

    // order lunas, meja kembali kosong
    await expect(page.getByText('Struk').first()).toBeVisible();
});

async function newPage(
    browser: Browser,
    baseURL: string,
    viewport: { width: number; height: number },
    isMobile: boolean,
): Promise<Page> {
    const context = await browser.newContext({
        baseURL,
        viewport,
        isMobile,
        hasTouch: isMobile,
        locale: 'id-ID',
    });

    return context.newPage();
}
