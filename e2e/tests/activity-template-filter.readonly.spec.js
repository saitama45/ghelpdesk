const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

/**
 * /activity-templates — Project Type filter + infinite-scroll pagination.
 *
 * Read-only: this spec never creates, edits or deletes a template. It drives the
 * list with `?per_page=5` so the real template list spans three pages and
 * the "load more" sentinel actually has something to fetch.
 */

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`) });
};

const awaitIndex = (page) => page.waitForFunction(
    () => JSON.parse(document.querySelector('#app')?.dataset.page || '{}').component === 'ActivityTemplates/Index',
    null,
    { timeout: 90_000 }
);

/** The list table is the first table on the page; the modals live in closed <dialog>s. */
const rows = (page) => page.locator('table').first().locator('tbody tr');

/** Second column is the Project Type pill. */
const typesOnScreen = async (page) =>
    (await rows(page).locator('td:nth-child(2)').allTextContents()).map((t) => t.trim());

/** The page scrolls inside AppLayout's <main scroll-region>, not the window. */
const scrollToBottom = (page) => page.evaluate(() => {
    const el = document.querySelector('main[scroll-region]') || document.scrollingElement;
    el.scrollTop = el.scrollHeight;
});

const pickProjectType = async (page, label) => {
    await page.getByTestId('project-type-filter').click();
    const option = page.locator('.z-\\[200\\] li').filter({ hasText: label }).first();
    await expect(option).toBeVisible({ timeout: 15_000 });
    await option.click();
};

test.describe('Activity templates: project type filter and infinite scroll', () => {
    test('elevated user filters by project type and scrolls the list; a user without activity_templates.view is refused', async ({ browser, baseURL }) => {
        test.setTimeout(240_000);

        // ------------------------------------------------------------------
        // ACT 1 - Gen Magbanua (Dev): holds activity_templates.view.
        // ------------------------------------------------------------------
        const mgrCtx = await browser.newContext({ storageState: AUTH('manager') });
        const mgr = await mgrCtx.newPage();
        const qm = narrator(mgr, { total: 9 });

        await mgr.goto('/activity-templates?per_page=5');
        await awaitIndex(mgr);
        await qm.say('Signed in as Gen Magbanua and opened Project Templates with 5 rows per page, so the full template list spans several pages.');

        // --- Infinite scroll replaces the numbered pager --------------------
        await expect(rows(mgr)).toHaveCount(5, { timeout: 30_000 });
        await expect(mgr.getByRole('button', { name: 'Next' })).toHaveCount(0);
        await expect(mgr.getByRole('button', { name: 'Previous' })).toHaveCount(0);
        await expect(mgr.getByText(/Showing 5 of \d+ records/)).toBeVisible();
        await qm.say('The numbered pager and the per-page selector are gone. The footer now reports how many of the total rows are loaded.');
        await shot(mgr, 'activity-templates-infinite-first-page');

        const totalText = await mgr.locator('text=/Showing \\d+ of \\d+ records/').first().textContent();
        const total = Number(totalText.match(/of (\d+) records/)[1]);
        expect(total).toBeGreaterThan(5);

        await scrollToBottom(mgr);
        await expect(rows(mgr)).toHaveCount(10, { timeout: 30_000 });
        await qm.say('Scrolling to the bottom pulled the second page in automatically - 10 rows now, with no click on a pager.');

        // Every page must come from the SAME result set: the row count has to
        // land exactly on the reported total, with nothing dropped or repeated.
        await scrollToBottom(mgr);
        await expect(rows(mgr)).toHaveCount(total, { timeout: 30_000 });
        await expect(mgr.getByText(new RegExp(`Showing ${total} of ${total} records`))).toBeVisible();
        await qm.say(`Scrolling again loaded the last page. All ${total} templates are on screen, none dropped or repeated.`);

        const loadedNames = await rows(mgr).locator('td:nth-child(1)').allTextContents();
        expect(new Set(loadedNames.map((n) => n.trim())).size).toBe(total);

        await scrollToBottom(mgr);
        await expect(mgr.getByText('reached the end')).toBeVisible({ timeout: 15_000 });
        await shot(mgr, 'activity-templates-infinite-end');

        // --- The new Project Type filter ------------------------------------
        await pickProjectType(mgr, 'Store Opening');
        await awaitIndex(mgr);
        await expect(mgr).toHaveURL(/project_type=Store(\+|%20)Opening/);
        await expect(rows(mgr)).toHaveCount(1, { timeout: 30_000 });
        expect(await typesOnScreen(mgr)).toEqual(['Store Opening']);
        await qm.say('Picked "Store Opening" in the new Project Type filter. The list narrowed to the single Regular-class Store Opening blueprint and the choice is recorded in the URL.');
        await shot(mgr, 'activity-templates-filter-store-opening');

        // --- The filter must survive a "load more" ---------------------------
        await pickProjectType(mgr, 'Full Service Group: Customer Brand');
        await awaitIndex(mgr);
        await expect(rows(mgr)).toHaveCount(5, { timeout: 30_000 });
        expect(new Set(await typesOnScreen(mgr))).toEqual(new Set(['Full Service Group: Customer Brand']));
        await qm.say('Switched the filter to "Full Service Group: Customer Brand" - the first page of that type, still 5 rows at a time.');

        await scrollToBottom(mgr);
        await expect(rows(mgr)).toHaveCount(10, { timeout: 30_000 });
        expect(new Set(await typesOnScreen(mgr))).toEqual(new Set(['Full Service Group: Customer Brand']));
        await qm.say('Scrolling loaded the rest of that type only: the "load more" request carries the filter, so no other project type leaks into the list.');
        await shot(mgr, 'activity-templates-filter-load-more');

        // --- The filter must also survive the search box ---------------------
        const search = mgr.getByPlaceholder('Search templates by name...');
        await search.fill('link');
        await awaitIndex(mgr);
        await expect(mgr).toHaveURL(/project_type=/, { timeout: 30_000 });
        await expect.poll(async () => Array.from(new Set(await typesOnScreen(mgr))), { timeout: 30_000 })
            .toEqual(['Full Service Group: Customer Brand']);
        await qm.say('Typing in the search box keeps the Project Type filter applied - search and filter compose instead of cancelling each other.');
        await shot(mgr, 'activity-templates-filter-plus-search');

        await mgrCtx.close();

        // ------------------------------------------------------------------
        // ACT 2 - Andrea Sibulo (POS Approver): no activity_templates.view.
        // ------------------------------------------------------------------
        const outCtx = await browser.newContext({ storageState: AUTH('outsider') });
        const out = await outCtx.newPage();
        const qo = narrator(out, { total: 2 });

        await out.goto('/dashboard');
        await qo.say('Now signed in as Andrea Sibulo, a POS Approver with no activity_templates permissions.');

        const direct = await outCtx.request.get(`${baseURL}/activity-templates?project_type=Store+Opening`, {
            failOnStatusCode: false,
        });
        expect(direct.status()).toBe(403);
        await qo.say('Requesting the filtered URL directly returns 403 - the filter is not a way around the route permission.');

        await outCtx.close();
    });
});
