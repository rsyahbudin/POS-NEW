import { expect, test } from '@playwright/test';
import {
    expectNoHorizontalScroll,
    login,
    resetDatabase,
    seedCashierOrder,
} from './support';

test('QR: quantity can be changed on the menu card without opening the cart', async ({
    page,
}) => {
    const { token } = resetDatabase();
    await page.goto(`/m/${token}`);

    await page.getByRole('button', { name: 'Tambah Croissant' }).click();
    await page.getByRole('button', { name: 'Tambah Croissant' }).click();
    await expect(
        page.getByRole('button', { name: 'Kurangi Croissant' }),
    ).toBeVisible();
    await expect(page.getByText('2 item')).toBeVisible();

    await page.getByRole('button', { name: 'Kurangi Croissant' }).click();
    await expect(page.getByText('1 item')).toBeVisible();
    await expectNoHorizontalScroll(page);
});

test('walk-in: order and pay in one go', async ({
    page,
    context,
}, testInfo) => {
    resetDatabase();
    const narrow = (testInfo.project.use.viewport?.width ?? 1280) < 768;

    await login(page, 'kasir@pos.test');
    await page.goto('/shift');
    await page.getByRole('textbox').first().fill('0');
    await page.getByRole('button', { name: 'Buka shift' }).click();
    await expect(page.getByText('Penerimaan dari penjualan')).toBeVisible();

    await page.goto('/pos');
    await page.getByRole('button', { name: /Takeaway/ }).click();
    await page.getByRole('button', { name: /Croissant/ }).click();
    if (narrow) {
        await page.getByRole('button', { name: /item baru/ }).click();
    }
    await page.getByRole('button', { name: 'Kirim & bayar langsung' }).click();
    await expect(
        page.getByRole('heading', { name: 'Pembayaran' }),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Uang pas' }).click();

    const popup = context.waitForEvent('page', { timeout: 30_000 });
    await page.getByRole('button', { name: /^Bayar Rp25\.000$/ }).click();
    await expect((await popup).getByText('Croissant')).toBeVisible();
});

test('pay only the selected items, then the rest', async ({
    page,
    context,
}, testInfo) => {
    resetDatabase();
    seedCashierOrder();
    const narrow = (testInfo.project.use.viewport?.width ?? 1280) < 768;

    await login(page, 'kasir@pos.test');
    await page.goto('/pos');
    await page.getByRole('button', { name: /^I-1/ }).click();
    if (narrow) {
        await page.getByRole('button', { name: /ORD-/ }).click();
    }
    await page.getByRole('button', { name: 'Split bill' }).click();
    await page.getByRole('button', { name: /Bayar sebagian dulu/ }).click();
    await page
        .getByRole('group', { name: 'Bayar 2× Americano' })
        .or(page.getByRole('group', { name: /Americano/ }))
        .getByRole('button', { name: 'Tambah' })
        .click();

    // total is priced by the server and shown before paying
    await expect(
        page.getByRole('button', { name: /^Bayar Rp22\.000$/ }),
    ).toBeVisible();
    const popup = context.waitForEvent('page', { timeout: 30_000 });
    await page.getByRole('button', { name: /^Bayar Rp22\.000$/ }).click();
    const dialog = page.getByRole('dialog');
    await dialog.getByRole('button', { name: 'Uang pas' }).click();
    await dialog.getByRole('button', { name: /^Bayar Rp22\.000$/ }).click();
    await expect(
        (await popup).getByText('TOTAL', { exact: true }),
    ).toBeVisible();

    // close the receipt; the rest is still payable
    await page.keyboard.press('Escape');
    if (narrow) {
        await page.getByRole('button', { name: /ORD-/ }).click(); // reopen the order sheet
    }
    await expect(
        page.getByRole('button', { name: /Bayar Sisa .*Rp60\.000/ }),
    ).toBeVisible();
});
