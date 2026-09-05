const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

/**
 * The vendor approval matrix on /vendors, verified with two contrasting accounts.
 *
 * Deliberately writes NOTHING. Recording a decision changes a real registration
 * on the developer database — and which vendors are pending drifts as people use
 * the app — so the decision itself is covered by tests/Feature/VendorApprovalTest
 * against an isolated database. What can only be checked in a browser is here:
 * that the matrix appears for a portal account with the right options, that the
 * reason guard fires, and that an account without the permission is refused.
 */

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: false });
};

async function awaitVendorsPage(page) {
    await page.waitForFunction(
        () => JSON.parse(document.querySelector('#app')?.dataset.page || '{}').component === 'Vendors/Index',
        null,
        { timeout: 60_000 }
    );
}

/** What the server actually sent this account, rather than a scrape of the DOM. */
async function vendorProps(page) {
    const raw = await page.locator('#app').getAttribute('data-page');

    return JSON.parse(raw).props;
}

test.describe('Vendor portal approval matrix', () => {
    test('an approver decides, and an account without the permission is refused', async ({ browser }) => {
        // --- Elevated account: holds vendors.view/edit/approve/reset_password ---
        const approverContext = await browser.newContext({ storageState: AUTH('manager') });
        const page = await approverContext.newPage();

        await page.goto('/vendors?status=pending');
        await awaitVendorsPage(page);

        // Whichever registration is waiting today — the queue drifts as people
        // use the app, so nothing here is pinned to one vendor id.
        const props = await vendorProps(page);
        const before = (props.vendors?.data || [])[0];
        expect(before, 'no portal vendor is awaiting approval to exercise the matrix with').toBeTruthy();
        expect(before.has_portal_access).toBe(true);
        expect(before.status).toBe('pending');

        // The registration queue is announced, not hidden behind a filter.
        await expect(page.getByText(/awaiting approval from the vendor portal/i)).toBeVisible();
        await expect(page.getByText(/awaiting approval/i).first()).toBeVisible();
        await shot(page, 'vendor-approval-01-queue');

        // Reference vendors have no portal row actions; this one does.
        const row = page.locator('tbody tr', { hasText: before.name }).first();
        await row.getByTitle('Review portal account').click();

        await expect(page.getByRole('heading', { name: /vendor portal approval/i })).toBeVisible();
        await shot(page, 'vendor-approval-02-matrix');

        // The accreditation documents are the basis for the account decision,
        // so they are in this modal — with their own review actions.
        const modal = page.locator('div.rounded-xl', { hasText: /vendor portal approval/i }).first();

        // What the vendor keeps in the portal: the profile the back office
        // mirrors, and the accreditation files.
        await expect(modal.getByRole('heading', { name: 'Company Profile' })).toBeVisible({ timeout: 30_000 });
        await expect(modal.getByText('Accreditation documents', { exact: true })).toBeVisible();

        // Which of them still need a decision drifts as they get reviewed, so
        // the payload decides what this asserts.
        const docs = await (await approverContext.request.get(`/vendors/${before.id}/documents`)).json();
        const awaiting = docs.documents.filter((d) => d.status.toLowerCase() === 'pending').length;

        if (awaiting) {
            await expect(modal.getByText(`${awaiting} awaiting review`)).toBeVisible();
            await expect(modal.getByText(/review the outstanding documents before deciding/i)).toBeVisible();
            // The document's own Approve/Reject sit inside the account modal.
            await expect(modal.getByText(/awaiting your review/i).first()).toBeVisible();
        } else if (docs.documents.length) {
            await expect(modal.getByText(/awaiting your review/i)).toHaveCount(0);
            await expect(modal.getByText(/reviewed by/i).first()).toBeVisible();
        }
        await shot(page, 'vendor-approval-05-documents');

        // Contacts and bank accounts come from the same portal page. Contacts
        // are directory information; a bank account is verified before payments
        // are released against it. Nothing is submitted here — that path is
        // covered by tests/Feature/VendorProfileReviewTest.
        const portal = await (await approverContext.request.get(`/vendors/${before.id}/profile`)).json();

        if (portal.contacts.length) {
            await expect(modal.getByText('Contacts', { exact: true })).toBeVisible();
            await expect(modal.getByText(portal.contacts[0].name).first()).toBeVisible();
        }

        if (portal.bank_accounts.length) {
            const account = portal.bank_accounts[0];
            await expect(modal.getByText('Bank Accounts', { exact: true })).toBeVisible();
            await expect(modal.getByText(account.bank_name).first()).toBeVisible();
            // Only the last four digits reach an account without verify rights.
            await expect(modal.getByText(account.account_number || account.masked_account_number).first()).toBeVisible();

            if (account.is_pending && portal.can_verify_bank) {
                await expect(modal.getByText(/awaiting verification/i).first()).toBeVisible();
                await expect(modal.getByRole('button', { name: 'Verify' })).toBeVisible();
            }
        }
        await shot(page, 'vendor-approval-08-contacts-and-bank');

        // A pending account offers exactly the two decisions that make sense.
        await expect(page.getByRole('button', { name: 'Approve Account', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Reject Account', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Suspend Account', exact: true })).toHaveCount(0);

        // A rejection must carry a reason — the vendor is shown it. The guard
        // fires client-side, so nothing is submitted.
        await page.getByRole('button', { name: 'Reject Account', exact: true }).click();
        await page.getByRole('button', { name: /record decision/i }).click();
        await expect(page.getByText(/please give a reason/i)).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).first().click();

        // Audit cards, the /users pattern the request asked for.
        await page.goto('/vendors?status=portal');
        await awaitVendorsPage(page);

        const portalVendor = (await vendorProps(page)).vendors.data
            .find((v) => v.has_portal_access);
        const approvedRow = page.locator('tbody tr', { hasText: portalVendor.name }).first();
        await approvedRow.getByTitle('Edit Vendor').click();
        await expect(page.getByText('Created By')).toBeVisible();
        await expect(page.getByText('Updated By')).toBeVisible();
        await expect(page.getByText('Created At')).toBeVisible();
        await expect(page.getByText('Updated At')).toBeVisible();
        // A portal account is governed by the matrix, so the free "Active
        // Vendor" checkbox must not be offered for it.
        await expect(page.locator('#vendor_is_active')).toHaveCount(0);
        await shot(page, 'vendor-approval-04-audit-cards');
        await page.getByRole('button', { name: 'Cancel' }).first().click();

        // The password reset exists and is reachable (no password is submitted:
        // this is a real vendor account on the developer database).
        await approvedRow.getByTitle('Reset portal password').click();
        await expect(page.getByRole('heading', { name: /reset portal password/i })).toBeVisible();
        await shot(page, 'vendor-approval-05-password-reset');

        // --- Restricted account: holds no vendors.* permission at all ---
        const outsiderContext = await browser.newContext({ storageState: AUTH('tester') });
        const outsider = await outsiderContext.newPage();

        const indexResponse = await outsider.goto('/vendors');
        expect(indexResponse.status(), 'the page itself must be gated, not just the sidebar link').toBe(403);

        await outsider.goto('/dashboard');

        // This app names its CSRF cookie per-app (the vendor portal shares the
        // host), and the page announces the name. Reading "XSRF-TOKEN" blindly
        // gets a 419, which would hide whether the permission gate even ran.
        const cookieName = await outsider.getAttribute('meta[name="csrf-cookie"]', 'content')
            || 'XSRF-TOKEN';
        const xsrf = decodeURIComponent(
            (await outsiderContext.cookies()).find((c) => c.name === cookieName)?.value || ''
        );

        for (const url of [
            `/vendors/${before.id}/approval`,
            `/vendors/${before.id}/password`,
        ]) {
            const forbidden = await outsiderContext.request.put(url, {
                headers: { 'X-XSRF-TOKEN': xsrf },
                form: { action: 'approve', password: 'sh0uldNotWork', password_confirmation: 'sh0uldNotWork' },
                failOnStatusCode: false,
            });

            expect(forbidden.status(), `${url} must refuse an account without the permission`).toBe(403);
        }

        await approverContext.close();
        await outsiderContext.close();
    });
});
