const { expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

/**
 * Verifies a streamed PDF endpoint, and captures it visually when it can.
 *
 * The response is the proof: status, an actual PDF content type, an INLINE
 * disposition (the button opens a tab — a download would defeat the point), and
 * real PDF bytes.
 *
 * Rendering it in a tab is best-effort on purpose. Headless Chromium ships no PDF
 * viewer, so navigating to one raises "Download is starting" instead of drawing a
 * page. That is a property of the test browser, not of the application, and it
 * must not fail a run — the headed run is where the screenshot comes from.
 *
 * @returns {{bytes:number, text:string|null}} text is null when not rendered.
 */
async function verifyInlinePdf(ctx, url, { shotsDir, shotName } = {}) {
    const response = await ctx.request.get(url);

    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toContain('pdf');
    expect(response.headers()['content-disposition'] || '').toContain('inline');

    const body = await response.body();
    expect(body.subarray(0, 5).toString('latin1')).toBe('%PDF-');

    let text = null;

    try {
        const tab = await ctx.newPage();
        await tab.goto(url);
        await tab.waitForTimeout(3500);

        if (shotsDir && shotName) {
            fs.mkdirSync(shotsDir, { recursive: true });
            await tab.screenshot({ path: path.join(shotsDir, `${shotName}.png`) });
        }

        text = await tab.locator('body').innerText().catch(() => null);
        await tab.close();
    } catch {
        // Headless: no inline viewer. The response assertions above already hold.
    }

    return { bytes: body.length, text };
}

module.exports = { verifyInlinePdf };
