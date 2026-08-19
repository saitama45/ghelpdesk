const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');
const { seedUatCycle } = require('../support/seed');
const { sign } = require('../support/sign');
const { verifyInlinePdf } = require('../support/pdf');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
};

const dlg = (page) => page.locator('dialog[open]');



test.describe('UAT digital signing', () => {
    test.setTimeout(300_000);

    test('a client signs the acceptance on the no-login portal, then the cycle is signed off and printed', async ({ browser }) => {
        const fixture = seedUatCycle({ approverId: 3 });

        // ------------------------------------------------------------------
        // ACT 1 — the client, on the tokenised portal, with NO account.
        // ------------------------------------------------------------------
        // A completely fresh context: no storageState, no cookies, nothing. If any
        // of this worked because a session was lying around, the test would be
        // proving the wrong thing.
        // storageState: undefined is load-bearing. browser.newContext() INHERITS
        // the project's storageState from playwright.config, so a plain
        // newContext() here was silently signed in as the tester — and the whole
        // "no-login portal" claim was false while the test still passed.
        const guestCtx = await browser.newContext({ storageState: undefined });
        await guestCtx.clearCookies();
        const guest = await guestCtx.newPage();
        const qc = narrator(guest, { total: 12 });

        // Prove this browser is genuinely unauthenticated before using the link.
        // Not by hunting for a session cookie — Laravel issues one to every
        // visitor, signed in or not — but by showing the app bounces it to login.
        await guest.goto('/dashboard');
        await expect(guest).toHaveURL(new RegExp("/login"));
        await qc.say('Starting from a browser with no account at all: asking for the dashboard bounces it straight to the login page.');

        await guest.goto(fixture.portal_url);
        await qc.say('Yet this private link opens. This is the client-facing portal, and it is where a UAT acceptance is actually signed.');
        await expect(guest.getByText(fixture.title)).toBeVisible();
        await qc.check('Same browser, still not logged in — the signed token in the URL is the whole credential.');
        await shot(guest, '20-portal-opened');

        await guest.getByRole('button', { name: /Acceptance & Sign-off/i }).click();
        await qc.say('The client opens their acceptance. They see plain language, and only their own column — never a colleague\'s answers.');
        await expect(guest.getByRole('heading', { name: /Acceptance & Sign-off/i })).toBeVisible();

        await guest.getByText('Passed', { exact: true }).first().click();
        await guest.locator('input[type=text]').last().fill('Maria Santos');
        await qc.say('They pick a result and type their name, exactly as on the paper pack this replaced.');

        await sign(guest, guest);
        await qc.check('And now they sign it by hand — mouse here, a finger on a phone, the same pointer code path.');
        await shot(guest, '21-portal-signature-drawn');

        await guest.getByRole('button', { name: /Submit My Acceptance/i }).click();
        await guest.waitForTimeout(3500);

        await expect(guest.getByText(/acceptance has been recorded|Thank you/i).first()).toBeVisible({ timeout: 30_000 });
        await qc.check('Acceptance recorded from the portal, signature and all — no account was ever involved.');
        await shot(guest, '22-portal-accepted');

        await guestCtx.close();

        // ------------------------------------------------------------------
        // ACT 2 — internally, the signature the client drew is on the record.
        // ------------------------------------------------------------------
        const ctx = await browser.newContext({ storageState: AUTH('manager') });
        const page = await ctx.newPage();
        const qa = narrator(page, { total: 12 });

        await page.goto(`/uat/${fixture.cycle_id}?tab=signoff`);
        await qa.say('Back inside the app. The point of the portal is that what the client signed lands here.');

        const clientSignature = page.locator('img[alt="Signature"]').first();
        await expect(clientSignature).toBeVisible({ timeout: 30_000 });
        await qa.check('The client\'s hand-drawn signature is on the acceptance roster, next to the name they typed.');
        await shot(page, '23-uat-client-signature-inside');

        const src = await clientSignature.getAttribute('src');
        expect(src).toMatch(/^\/storage\//);
        const imgResponse = await ctx.request.get(src);
        expect(imgResponse.status()).toBe(200);
        expect(imgResponse.headers()['content-type']).toContain('image');
        await qa.check(`Served from ${src} — an origin-relative path, not an absolute URL built from APP_URL, which is why it survives a non-default port.`);

        // ------------------------------------------------------------------
        // ACT 3 — the ADMIN-side acceptance, which the portal does not replace.
        // ------------------------------------------------------------------
        await qa.say('The portal is the priority path, but the in-app roster is not going anywhere: an internal approver still signs from here, and can now sign by hand too.');

        // Exact string: the client's row already reads "Re-record acceptance",
        // so a loose match would reopen theirs and supersede what they signed.
        await page.getByTitle('Record acceptance', { exact: true }).first().click();
        await expect(dlg(page).getByRole('heading', { name: /Record Acceptance/i })).toBeVisible();

        await dlg(page).getByText('Passed', { exact: true }).first().click();
        await sign(page, dlg(page));
        await qa.check('The internal approver signs on the same pad, in the same admin screen as before.');
        await dlg(page).getByRole('button', { name: /Record Acceptance/i }).click();
        await page.waitForTimeout(3500);
        await shot(page, '23b-uat-admin-acceptance');

        // Two approvers, both accepted — and signoff_requires_all means the final
        // sign-off was locked until this second one landed.
        await expect(page.locator('img[alt="Signature"]')).toHaveCount(2, { timeout: 30_000 });
        await qa.check('Two acceptances now on the roster, each carrying its own signature — one signed by the client on the portal, one signed internally.');

        // ------------------------------------------------------------------
        // The internal final sign-off closes the cycle.
        // ------------------------------------------------------------------
        await page.getByRole('button', { name: /Record Final Sign-off/i }).click();
        await expect(dlg(page).getByRole('heading', { name: /Final Sign-off/i })).toBeVisible();
        await qa.say('The client having accepted, management can close the cycle — gated until every nominated approver has.');

        await dlg(page).getByText('Passed', { exact: true }).first().click();
        await sign(page, dlg(page));
        await dlg(page).getByRole('button', { name: /Record Sign-off/i }).click();
        await page.waitForTimeout(4000);

        await expect(page.getByText(/Final Sign-off/i).first()).toBeVisible({ timeout: 30_000 });
        await qa.check('Signed off, with a second signature against the management decision.');
        await shot(page, '24-uat-signed-off');

        // ------------------------------------------------------------------
        // The printable certificate.
        // ------------------------------------------------------------------
        await qa.say('And the certificate, which is what gets filed or sent on.');
        const uatPdf = await verifyInlinePdf(ctx, `/uat/${fixture.cycle_id}/signoff/pdf`, {
            shotsDir: SHOTS,
            shotName: '25-uat-certificate-pdf',
        });
        await qa.check(`Certificate served inline (${uatPdf.bytes.toLocaleString()} bytes) so the button just opens a tab — a download would defeat the point.`);

        // UAT has no waiver mechanism, so that section must not appear at all. It
        // rendered as an empty table until the template stopped testing an empty
        // Collection with empty(), which is false for any object. Only assertable
        // when the PDF actually rendered, which headless Chromium cannot do.
        if (uatPdf.text !== null) {
            expect(uatPdf.text).not.toMatch(/accepted under waiver/i);
            await qa.check('And no empty “accepted under waiver” section — UAT has no waiver, so the block is absent rather than blank.');
        }

        await ctx.close();
    });
});
