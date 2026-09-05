const { test, expect } = require('@playwright/test');
const path = require('node:path');

/**
 * Covers the two halves of the Campaigns work:
 *
 *   1. LINK HUB /vendors — the vendor type field is now inline-manageable
 *      (add / edit / delete, like project types on /activity-templates) and
 *      carries "Cashier", which reveals an Assigned Store picker.
 *   2. LINK PORTAL /vendor/campaigns — a Cashier sees the module and its five
 *      tabs; a Supplier is refused. The refusal is the point: hiding the nav
 *      item proves nothing, so the URL is typed directly.
 *
 * Read-only: it opens modals and reads pages, and creates no records.
 */

const HUB = process.env.E2E_BASE_URL || 'http://127.0.0.1:8010';
const PORTAL = process.env.E2E_PORTAL_URL || 'http://127.0.0.1:8002';

/** Portal sign-in through APIRequestContext, for the same reason auth.setup.js does. */
async function portalLogin(context, email, password) {
    const page = await context.request.get(`${PORTAL}/login`);
    const cookieName = (await page.text())
        .match(/name="csrf-cookie"\s+content="([^"]+)"/)?.[1] || 'XSRF-TOKEN';
    const xsrf = decodeURIComponent(
        (await context.cookies()).find((c) => c.name === cookieName)?.value || ''
    );

    const response = await context.request.post(`${PORTAL}/login`, {
        headers: { 'X-XSRF-TOKEN': xsrf },
        form: { email, password },
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    expect(response.status(), `portal login for ${email}`).toBe(302);
}

test.describe('LINK HUB — manageable vendor type', () => {
    // The default `readonly` role is the restricted tester, who has no
    // vendors.view (and correctly gets a 403 here). /vendors needs the elevated
    // profile.
    test.use({ storageState: path.resolve(__dirname, '..', '.auth', 'manager.json') });

    test('the vendor modal offers Cashier plus inline add/edit/delete', async ({ page }) => {
        await page.goto(`${HUB}/vendors`);
        await expect(page.getByRole('heading', { name: 'Vendor Management' })).toBeVisible();

        await page.getByRole('button', { name: 'Create Vendor' }).click();
        await expect(page.getByText('Vendor Type', { exact: false }).first()).toBeVisible();

        // The trigger is a button showing the current value; the form opens on
        // "Supplier", so that is what is clicked to reveal the managed list.
        await page.getByRole('button', { name: 'Supplier', exact: true }).click();
        const dropdown = page.locator('#mau-dropdown-vendor_type');
        await expect(dropdown).toBeVisible();
        await expect(dropdown.getByText('Cashier', { exact: true })).toBeVisible();
        await expect(dropdown.getByText(/add new/i)).toBeVisible();

        // Picking Cashier reveals the store picker; picking a supplier hides it.
        await dropdown.getByText('Cashier', { exact: true }).click();
        await expect(page.getByText(/assigned store/i)).toBeVisible();
    });
});

test.describe('LINK PORTAL — Campaigns', () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    test('a cashier gets the module, its tabs, and store-scoped data', async ({ page, context }) => {
        await portalLogin(context, 'e2e.cashier@example.test', 'Password123');

        await page.goto(`${PORTAL}/vendor/campaigns`);
        await expect(page.getByRole('heading', { name: 'Campaigns' })).toBeVisible();

        // The nav advertises it too.
        await expect(page.getByRole('link', { name: 'Campaigns' }).first()).toBeVisible();

        for (const tab of ['Cards', 'Redemptions', 'Customers', 'Programs', 'Vouchers']) {
            await expect(page.getByRole('button', { name: tab, exact: true })).toBeVisible();
        }

        // Cards tab: the scan actions are there, "New Card" deliberately is not.
        await expect(page.getByRole('button', { name: /scan customer qr/i })).toBeVisible();
        await expect(page.getByRole('button', { name: /scan redeem qr/i })).toBeVisible();
        await expect(page.getByRole('button', { name: /new card/i })).toHaveCount(0);

        // Customers / Programs are read-only.
        await page.getByRole('button', { name: 'Customers', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Customers' })).toBeVisible();
        await expect(page.getByRole('button', { name: /new customer/i })).toHaveCount(0);

        await page.getByRole('button', { name: 'Programs', exact: true }).click();
        await expect(page.getByRole('heading', { name: 'Stamp Programs' })).toBeVisible();
        await expect(page.getByRole('button', { name: /new program/i })).toHaveCount(0);

        // Vouchers: only Verify / Use Voucher survives.
        await page.getByRole('button', { name: 'Vouchers', exact: true }).click();
        await expect(page.getByRole('button', { name: /verify \/ use voucher/i })).toBeVisible();
        await expect(page.getByRole('button', { name: /new batch/i })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^activate$/i })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /prepare print pdf/i })).toHaveCount(0);
        await expect(page.getByRole('button', { name: /^cancel$/i })).toHaveCount(0);
    });

    test('a supplier is refused, even typing the URL', async ({ page, context }) => {
        await portalLogin(context, 'e2e.supplier@example.test', 'Password123');

        await page.goto(`${PORTAL}/vendor/dashboard`);
        await expect(page.getByRole('link', { name: 'Campaigns' })).toHaveCount(0);

        await page.goto(`${PORTAL}/vendor/campaigns`);
        // The middleware bounces to the dashboard rather than rendering anything.
        await expect(page).toHaveURL(/\/vendor\/dashboard/);
        await expect(page.getByRole('heading', { name: 'Campaigns' })).toHaveCount(0);

        // The JSON endpoints are gated too, not just the page.
        const probe = await context.request.get(`${PORTAL}/vendor/campaigns/assets-at-location`, {
            headers: { Accept: 'application/json' },
            failOnStatusCode: false,
        });
        expect(probe.status()).toBe(403);
    });
});
