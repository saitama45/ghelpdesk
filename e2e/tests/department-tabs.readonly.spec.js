const { test, expect } = require('@playwright/test');
const path = require('node:path');

/**
 * The global department strip.
 *
 * The "All" pill (enterprise / Executive mode) is hidden for now. What matters
 * is that hiding it took nothing else with it: the department tabs beside it
 * still switch the viewed department, and Executive is still reachable through
 * the "I belong to" selector on the right of the same strip.
 */
const HUB = process.env.E2E_BASE_URL || 'http://127.0.0.1:8010';

// The strip only renders for an account that has departments, and only an
// account with canSwitchHome ever saw the pill — so this is the profile that
// would still be showing it if the flag had not taken.
test.use({ storageState: path.resolve(__dirname, '..', '.auth', 'manager.json') });

test('the All pill is gone but the rest of the strip still works', async ({ page }) => {
    await page.goto(`${HUB}/dashboard`);

    // The tab row is the strip's own scroll container — a stable hook, unlike
    // filtering every <div> by its text.
    const strip = page.locator('div.no-scrollbar').first();
    await expect(strip).toBeVisible();

    // Scoped to the strip: "All" is a common word elsewhere on a dashboard.
    await expect(strip.getByRole('button', { name: 'All', exact: true })).toHaveCount(0);

    // The department tabs it sat beside are untouched and still switch.
    const deptTab = strip.getByRole('button', { name: 'FM', exact: true });
    await expect(deptTab).toBeVisible();
    await deptTab.click();
    await page.waitForLoadState('networkidle');
    await expect(page.locator('div.no-scrollbar').first()
        .getByRole('button', { name: 'FM', exact: true })).toBeVisible();

    // Executive is still reachable — the pill was a shortcut, not the only door.
    const belong = page.getByRole('combobox', { name: /department you belong to/i });
    await expect(belong).toBeVisible();
    await expect(belong.locator('option', { hasText: 'Executive' })).toHaveCount(1);
});
