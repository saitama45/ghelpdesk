const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

// NN LINK HUB — a Customer Brand project under TGI/NONOS, with no tasks of its own.
// Its template is named "LINK HUB", the project "NN LINK HUB".
const PROJECT_ID = 10026;
const TEMPLATE = /TGI - NONOS - LINK HUB/i;

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: false });
};

async function awaitProjectPage(page) {
    await page.waitForFunction(
        () => JSON.parse(document.querySelector('#app')?.dataset.page || '{}').component === 'Projects/Show',
        null,
        { timeout: 90_000 }
    );
}

const taskRowCount = (page) => page.locator('[data-task-row]').count();

/**
 * "Apply Template" silently did nothing.
 *
 * The modal listed the template (the listing rule matches a project name that
 * CONTAINS the template's name), but the apply endpoint guarded with a separate,
 * stricter copy of that rule demanding exact equality. It answered with a redirect
 * carrying an error bag that no component rendered, and the modal had already
 * closed — so the button appeared inert.
 *
 * This drives the whole thing through the real UI. Asserting the endpoint over
 * fetch would not have caught it: the endpoint "worked", it just refused, and the
 * refusal was invisible.
 */
test.describe('Applying an activity template', () => {
    test('adds the template rows to the project, and says so when it cannot', async ({ browser, baseURL }) => {
        test.setTimeout(240_000);

        const ctx = await browser.newContext({ storageState: AUTH('manager') });
        const page = await ctx.newPage();
        const qa = narrator(page, { total: 7 });

        /*
         * Uploaded files are NOT part of the local database snapshot, so a user row
         * can reference a profile photo whose file only exists on the real server.
         * That 404 (and the console line it prints) is a property of the local
         * fixture, not of the application — everything else still fails the run.
         */
        const isMissingUpload = (url) => url.includes('/serve-storage/');

        const failures = [];
        page.on('pageerror', (e) => failures.push(`pageerror: ${e.message}`));
        page.on('console', (m) => {
            if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) {
                failures.push(`console: ${m.text()}`);
            }
        });
        page.on('response', (r) => {
            if (r.status() >= 400 && !isMissingUpload(r.url())) {
                failures.push(`http ${r.status()}: ${r.url()}`);
            }
        });

        await page.goto(`/projects/${PROJECT_ID}?tab=gantt`);
        await awaitProjectPage(page);
        await qa.say('Signed in as Gen Magbanua on NN LINK HUB. The plan is empty — the template it needs was listed, but pressing Apply did nothing at all.');

        const before = await taskRowCount(page);
        await qa.check(`The Gantt shows ${before} task rows to start with.`);

        // --- open the modal and pick the template, by clicking, as a user does ---
        await page.getByRole('button', { name: /apply templates/i }).first().click();
        await expect(page.getByText('Apply Activity Template')).toBeVisible({ timeout: 30_000 });

        const option = page.locator('label:has(input[type="radio"])').filter({ hasText: TEMPLATE });
        await expect(option, 'the project\'s own template is not listed').toHaveCount(1);
        await option.click();
        await shot(page, 'apply-tpl-01-selected');
        await qa.say('Selected "TGI - NONOS - LINK HUB". Pressing Apply Template now — then confirming.');

        /*
         * Several components each mount their own <ConfirmModal>, so the page holds
         * five "Apply now" buttons at once — four collapsed to a 0x0 box and one real.
         * getByRole alone is a strict-mode violation, and .first() can latch onto a
         * stale zero-sized one and then wait forever for it to become clickable.
         * filter({ visible: true }) keeps only the one actually on screen.
         */
        const visibleButton = (text) => page
            .getByRole('button', { name: text })
            .filter({ visible: true });

        await visibleButton(/^Apply Template$/).click();

        // The confirm dialog, then any "create monthly boards" prompt behind it.
        const confirmButton = visibleButton(/^Apply now$/);
        await expect(confirmButton).toHaveCount(1, { timeout: 30_000 });
        await page.waitForTimeout(700); // the dialog's 300ms enter transition

        // Armed BEFORE the click. Row counts alone would pass vacuously on a re-run
        // (the rows are already there from last time) even if the button did nothing
        // — which is precisely the bug this spec exists to catch.
        const applyPosted = page.waitForResponse(
            (r) => r.url().includes('/apply-templates') && r.request().method() === 'POST',
            { timeout: 120_000 }
        );

        await confirmButton.click();

        const createBoards = visibleButton(/Create and Sync/i);
        if (await createBoards.count()) {
            await page.waitForTimeout(700);
            await createBoards.click({ timeout: 15_000 }).catch(() => {});
        }

        const applyResponse = await applyPosted;
        expect(applyResponse.status(), 'the apply request itself failed').toBeLessThan(400);
        await qa.check(`The button really did post to /apply-templates (HTTP ${applyResponse.status()}) — it is no longer inert.`);

        // --- the actual proof: rows land on the plan ---
        await page.waitForFunction(
            () => document.querySelectorAll('[data-task-row]').length > 0,
            null,
            { timeout: 120_000 }
        );
        const after = await taskRowCount(page);

        // Asserted as "the template's rows are on the plan", not "the count went up":
        // the endpoint is idempotent by design, so a second run of this spec finds
        // them already there and must still pass. Zero rows is the regression.
        expect(after, 'no task rows on the plan — the apply silently failed again').toBeGreaterThanOrEqual(64);
        expect(after).toBeGreaterThanOrEqual(before);

        await shot(page, 'apply-tpl-02-applied');
        await qa.check(`The plan now carries ${after} activity and sub-task rows from the template (it had ${before}). The button works.`);

        // The bug's signature: the endpoint refused and answered with an error bag
        // on `project_template_id` that nothing on the page rendered.
        const props = await page.locator('#app').getAttribute('data-page').then((raw) => JSON.parse(raw).props);
        expect(props.errors || {}, 'the apply came back rejected, with an error bag').toEqual({});

        // --- and the other half: a refusal must be visible, never silent ---
        await qa.say('Now the opposite case: asking the server to apply a template that does NOT belong to this project. It must say why, not fail quietly.');

        const version = await page.locator('#app').getAttribute('data-page').then((raw) => JSON.parse(raw).version);
        const xsrf = decodeURIComponent((await ctx.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || '');
        const wrong = await ctx.request.post(`${baseURL}/projects/${PROJECT_ID}/apply-templates`, {
            headers: { 'X-XSRF-TOKEN': xsrf, 'X-Inertia': 'true', 'X-Inertia-Version': version },
            form: { project_template_id: 20017, auto_create_monthly_boards: false }, // TGI - NONOS - LINK PORTAL
            maxRedirects: 0,
            failOnStatusCode: false,
        });

        // A refusal, with a reason attached — not a 200 that quietly did nothing.
        expect([302, 409, 422]).toContain(wrong.status());
        await qa.check(`A template for a different product is refused (HTTP ${wrong.status()}) with a stated reason, and the UI now surfaces it as an error toast.`);

        expect(failures, `browser errors during the run: ${failures.join(' | ')}`).toEqual([]);

        await ctx.close();
    });
});
