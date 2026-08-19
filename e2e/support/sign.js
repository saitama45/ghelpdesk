const { expect } = require('@playwright/test');

/**
 * Draws a signature on a SignaturePad and waits for it to commit.
 *
 * Two things here are load-bearing and were both learned the hard way:
 *
 *  - scrollIntoViewIfNeeded() first. page.mouse works in VIEWPORT coordinates
 *    while boundingBox() reports frame coordinates, so a pad below the fold gets
 *    "signed" at whatever happens to sit at those viewport pixels instead. It
 *    passed headed and failed headless, which is the worst kind of green.
 *
 *  - the wait afterwards. The canvas commits its PNG on pointerup and Vue
 *    propagates it on the next tick, so submitting in the same beat posts a null
 *    signature. That is a test-timing artefact, not something a human could hit.
 *
 * Playwright's mouse API drives the real pointer pipeline, which is exactly what
 * the pad listens for — the same path a client's finger on a phone takes.
 *
 * @param {import('@playwright/test').Page} page
 * @param {object} scope  Page or Locator containing exactly one pad.
 */
async function sign(page, scope) {
    const pad = scope.locator('canvas').first();

    await expect(pad).toBeVisible();
    await pad.scrollIntoViewIfNeeded();
    await page.waitForTimeout(250);

    const box = await pad.boundingBox();
    if (!box) throw new Error('Signature pad has no layout box — it cannot be signed.');

    const baseY = box.y + box.height * 0.62;
    const points = [
        [0.10, 0.0], [0.16, -0.24], [0.23, 0.18], [0.30, -0.26], [0.38, 0.14],
        [0.46, -0.22], [0.54, 0.16], [0.62, -0.24], [0.70, 0.10], [0.79, -0.16],
        [0.88, 0.12],
    ];

    await page.mouse.move(box.x + box.width * 0.06, baseY);
    await page.mouse.down();
    for (const [fx, fy] of points) {
        await page.mouse.move(box.x + box.width * fx, baseY + box.height * fy, { steps: 6 });
    }
    await page.mouse.up();
    await page.waitForTimeout(700);

    // The pad only offers "Clear" once it holds ink, so this proves the strokes
    // registered before anything goes on to assert they were stored.
    await expect(scope.getByRole('button', { name: /^Clear$/ })).toBeVisible();
}

module.exports = { sign };
