const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

/**
 * The Vendor Documents panel on /vendors reads the accreditation files a vendor
 * uploaded through the portal (/vendor/documents). Read-only in every sense:
 * this spec opens, previews and downloads, and writes nothing.
 */
const VENDOR_NAME = process.env.E2E_DOC_VENDOR || 'ABC Supplies Inc';

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

test.describe('Vendor documents panel', () => {
    test('an approver sees the portal uploads, previews and downloads them', async ({ browser }) => {
        const context = await browser.newContext({ storageState: AUTH('manager') });
        const page = await context.newPage();

        await page.goto(`/vendors?search=${encodeURIComponent(VENDOR_NAME)}`);
        await awaitVendorsPage(page);

        const row = page.locator('tbody tr', { hasText: VENDOR_NAME }).first();
        await row.getByTitle('Edit Vendor').click();

        // The portal's Company Profile fields are mirrored beside the documents,
        // with whatever the vendor has staged for approval called out.
        await expect(page.getByRole('heading', { name: 'Company Profile' })).toBeVisible({ timeout: 30_000 });
        for (const label of ['Legal Name', 'Trade Name', 'TIN', 'RDO Code', 'Business Type', 'VAT Type', 'Default Payment Terms', 'Currency']) {
            await expect(page.getByText(label, { exact: true }).first()).toBeVisible();
        }

        // The panel fetches on open, so the rows arrive after the modal.
        await expect(page.getByText(/QA SAMPLE - BIR Form 2303/i)).toBeVisible({ timeout: 30_000 });
        await expect(page.getByText(/QA SAMPLE - Mayor's Business Permit/i)).toBeVisible();

        // Fields the portal captures on upload must reach the panel.
        await expect(page.getByText('BIR Certificate of Registration (Form 2303)')).toBeVisible();
        await expect(page.getByText(/Valid until Dec 31, 2026/i)).toBeVisible();
        await expect(page.getByText(/Issued/i).first()).toBeVisible();
        // Status comes from the stored review, so it is read from the payload
        // rather than pinned to a label — documents get decided on over time.
        const feed = await (await context.request.get('/vendors/10021/documents')).json();
        const pdfDoc = feed.documents.find((d) => /BIR Form 2303/i.test(d.name));
        const imageDoc = feed.documents.find((d) => /Mayor's Business Permit/i.test(d.name));

        const pdfCard = page.locator('div.rounded-xl', { hasText: /QA SAMPLE - BIR Form 2303/i }).last();
        const imageCard = page.locator('div.rounded-xl', { hasText: /QA SAMPLE - Mayor's Business Permit/i }).last();
        // Scoped to the card: the vendor's OWN status badge reads "Approved" too.
        await expect(pdfCard.getByText(pdfDoc.status, { exact: true })).toBeVisible();
        await expect(imageCard.getByText(imageDoc.status, { exact: true })).toBeVisible();
        await shot(page, 'vendor-documents-01-panel');

        // Type filters count the real rows.
        await page.getByRole('button', { name: /^PDFs \d+$/ }).click();
        await expect(page.getByText(/QA SAMPLE - BIR Form 2303/i)).toBeVisible();
        await expect(page.getByText(/QA SAMPLE - Mayor's Business Permit/i)).toHaveCount(0);

        // Search narrows on title, filename and document type.
        // /^All \d+$/, not /^All/: the department strip has its own "All" button.
        await page.getByRole('button', { name: /^All \d+$/ }).click();
        await page.getByPlaceholder('Search documents by name or type...').fill("Mayor's");
        await expect(page.getByText(/QA SAMPLE - BIR Form 2303/i)).toHaveCount(0);
        await expect(page.getByText(/QA SAMPLE - Mayor's Business Permit/i)).toBeVisible();

        // Preview opens the image viewer on the streamed file, not a placeholder.
        await page.getByRole('button', { name: 'Preview' }).first().click();
        const viewerImage = page.locator('img[src*="/documents/"][src*="/file"]').first();
        await expect(viewerImage).toBeVisible();

        const imageUrl = await viewerImage.getAttribute('src');
        const imageResponse = await context.request.get(imageUrl);
        expect(imageResponse.status()).toBe(200);
        expect(imageResponse.headers()['content-type']).toContain('image/png');
        await shot(page, 'vendor-documents-02-preview');

        // The PDF's own routes: inline for the tab, attachment for the download.
        const inline = await context.request.get(`/vendors/10021/documents/1/file`);
        expect(inline.status()).toBe(200);
        expect(inline.headers()['content-type']).toContain('application/pdf');
        expect(inline.headers()['content-disposition']).toContain('inline');

        const download = await context.request.get(`/vendors/10021/documents/1/file?download=1`);
        expect(download.status()).toBe(200);
        expect(download.headers()['content-disposition']).toContain('attachment');
        expect((await download.body()).toString('utf8', 0, 8)).toContain('%PDF');

        // The zoom viewer is still open over the modal from the Preview step.
        await page.keyboard.press('Escape');
        // The card thumbnail uses the same src, so assert on the viewer's own
        // chrome rather than the image.
        await expect(page.getByText(/drag image to pan/i)).toBeHidden();

        await page.getByPlaceholder('Search documents by name or type...').fill('');

        // The accreditation decision is offered on a PENDING document only, and
        // the reason guard holds. Nothing is submitted here: recording a decision
        // changes a real document, and that path is covered by
        // tests/Feature/VendorDocumentPanelTest.
        const stillPending = feed.documents.find((d) => d.status.toLowerCase() === 'pending');

        if (stillPending) {
            const pendingCard = page.locator('div.rounded-xl', { hasText: stillPending.name }).last();
            await expect(pendingCard.getByText(/awaiting your review/i)).toBeVisible();
            await expect(pendingCard.getByRole('button', { name: 'Approve' })).toBeVisible();

            await pendingCard.getByRole('button', { name: 'Reject' }).click();
            await expect(pendingCard.getByPlaceholder(/what to correct/i)).toBeVisible();
            await pendingCard.getByRole('button', { name: /confirm rejection/i }).click();
            // Still open: an empty reason submits nothing.
            await expect(pendingCard.getByPlaceholder(/what to correct/i)).toBeVisible();
            await pendingCard.getByRole('button', { name: 'Cancel' }).click();
        }
        await shot(page, 'vendor-documents-03-review');

        // A document already decided on offers no second decision.
        const decided = feed.documents.find((d) => d.status.toLowerCase() !== 'pending');
        expect(decided, 'a decided document is needed to prove the decision closes').toBeTruthy();
        const decidedCard = page.locator('div.rounded-xl', { hasText: decided.name }).last();
        await expect(decidedCard.getByRole('button', { name: 'Approve' })).toHaveCount(0);
        await expect(decidedCard.getByText(/reviewed by/i)).toBeVisible();

        // An account with no vendors.view must not reach either endpoint.
        const outsiderContext = await browser.newContext({ storageState: AUTH('tester') });
        for (const url of ['/vendors/10021/documents', '/vendors/10021/documents/1/file']) {
            const forbidden = await outsiderContext.request.get(url, { failOnStatusCode: false });
            expect(forbidden.status(), `${url} must be gated`).toBe(403);
        }

        // And the review endpoint refuses them too (per-app CSRF cookie name).
        const outsiderPage = await outsiderContext.newPage();
        await outsiderPage.goto('/dashboard');
        const cookieName = await outsiderPage.getAttribute('meta[name="csrf-cookie"]', 'content') || 'XSRF-TOKEN';
        const xsrf = decodeURIComponent(
            (await outsiderContext.cookies()).find((c) => c.name === cookieName)?.value || ''
        );
        const refusedReview = await outsiderContext.request.put('/vendors/10021/documents/2/review', {
            headers: { 'X-XSRF-TOKEN': xsrf },
            form: { action: 'approved' },
            failOnStatusCode: false,
        });
        expect(refusedReview.status(), 'the review endpoint must be gated').toBe(403);

        await context.close();
        await outsiderContext.close();
    });
});
