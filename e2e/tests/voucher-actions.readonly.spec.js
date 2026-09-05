const { test, expect } = require('@playwright/test');
const path = require('node:path');

/**
 * The Vouchers tab's row actions.
 *
 * "Rebuild Smaller PDF" and "Edit Claim Period" were removed; the actions that
 * share the row with them must survive, which is the half a plain "it's gone"
 * assertion would miss.
 */
const HUB = process.env.E2E_BASE_URL || 'http://127.0.0.1:8010';

test.use({ storageState: path.resolve(__dirname, '..', '.auth', 'manager.json') });

test('the voucher batch row keeps its other actions and drops the two removed ones', async ({ page }) => {
    await page.goto(`${HUB}/stamps`);

    // Voucher batches are entity-scoped and every one of them belongs to CBTL,
    // so the default entity shows an empty table — against which "the button is
    // gone" would pass for the wrong reason.
    await page.getByRole('button', { name: /Entity/i }).first().click();
    await page.getByRole('button', { name: /CBTL/i }).first().click();
    await page.waitForLoadState('networkidle');

    await page.getByRole('button', { name: 'Vouchers', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Campaign Voucher Batches' })).toBeVisible();

    // There is real data to judge against — otherwise "no buttons" proves nothing.
    await expect(page.getByText('No voucher batches yet.')).toHaveCount(0);
    expect(await page.locator('table tbody tr').count()).toBeGreaterThan(0);

    await expect(page.getByRole('button', { name: /rebuild smaller pdf/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /rebuilding/i })).toHaveCount(0);
    await expect(page.getByRole('button', { name: /edit claim period/i })).toHaveCount(0);

    // Everything else in that action group is untouched.
    await expect(page.getByRole('link', { name: /open \/ print vouchers/i }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /^cancel$/i }).first()).toBeVisible();
    await expect(page.getByRole('button', { name: /verify \/ use voucher/i })).toBeVisible();
});
