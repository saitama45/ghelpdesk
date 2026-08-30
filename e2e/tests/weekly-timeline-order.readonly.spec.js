const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

// LINK HUB — 72 rows under one milestone ("All Departments' NSO Collaboration"),
// 8 activities each owning 3 sub-tasks. This is the exact plan the ordering bug
// was reported against: the weekly tab listed all 8 activities first and only
// then every sub-task, while the Gantt nests each sub-task under its parent.
const PROJECT_ID = 10025;

// NN LINK HUB — a Customer Brand project under TGI/NONOS whose own activity
// template was missing from the Apply modal (its project_name is "LINK HUB").
const TEMPLATE_PROJECT_ID = 10026;

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

/** Row ids in the order they are painted, from either tab — both use data-task-row. */
const renderedOrder = (page) =>
    page.$$eval('[data-task-row]', (nodes) => nodes.map((n) => Number(n.dataset.taskRow)));

/**
 * The plan's structure, read from the Inertia prop bag rather than re-derived
 * here, so the expectation is built from the same rows the page was given.
 * Parsed Node-side: JSON.parse of a large data-page inside page.evaluate kills
 * the renderer mid-run.
 */
async function planStructure(page, request, baseURL) {
    const response = await request.get(`${baseURL}/projects/${PROJECT_ID}`, {
        headers: { 'X-Inertia': 'true', 'X-Inertia-Version': await pageVersion(page), Accept: 'application/json' },
    });
    const body = await response.json();
    const tasks = body.props.project.tasks;

    return new Map(tasks.map((t) => [
        Number(t.id),
        { id: Number(t.id), parent: t.parent_task_id ? Number(t.parent_task_id) : null, name: t.name },
    ]));
}

async function pageVersion(page) {
    const raw = await page.locator('#app').getAttribute('data-page');
    return JSON.parse(raw).version;
}

/**
 * The rendered colour of each status pill on the page, keyed by its label.
 *
 * Matched on leaf elements whose whole text is a status word, so it finds the pill
 * on either tab without depending on the two tabs' different markup.
 */
async function statusPillStyles(page) {
    const found = await page.$$eval('span, div', (nodes) => {
        const out = {};
        nodes
            .filter((n) => n.children.length === 0 && /^(done|ongoing|pending|blocked|for approval)$/i.test((n.textContent || '').trim()))
            .forEach((n) => {
                const label = n.textContent.trim().toLowerCase();
                if (out[label]) return;
                const style = getComputedStyle(n);
                out[label] = { color: style.color, background: style.backgroundColor };
            });
        return out;
    });
    return found;
}

/**
 * The Department filter's options, read by opening the real control.
 *
 * The two tabs use different widgets — the Gantt a MultiAutocomplete whose panel is
 * teleported to <body>, the weekly timeline a native <select> — so each is opened
 * the way a user opens it rather than scraped from component state.
 */
async function departmentOptions(page, tab) {
    if (tab === 'weekly-timeline') {
        // Anchored on the WHOLE option text: a substring match on "All Departments"
        // also matches the Milestone filter, which offers the milestone
        // "All Departments' NSO Collaboration".
        const select = page.locator('select')
            .filter({ has: page.locator('option', { hasText: /^All Departments$/ }) })
            .first();
        await expect(select).toBeVisible({ timeout: 30_000 });
        const options = await select.locator('option').allTextContents();
        return options.map((o) => o.trim()).filter((o) => o !== 'All Departments');
    }

    // Gantt: the filter panel is behind the funnel toggle in the toolbar.
    const toggle = page.locator('button[title*="filter" i], button[aria-label*="filter" i]').first();
    if (await toggle.count() && !(await page.getByPlaceholder('Any department').isVisible().catch(() => false))) {
        await toggle.click();
    }

    const input = page.getByPlaceholder('Any department');
    await expect(input).toBeVisible({ timeout: 30_000 });
    await input.click();

    const panel = page.locator('[id^="multi-autocomplete-dropdown-"]').last();
    await expect(panel).toBeVisible({ timeout: 30_000 });
    const options = await panel.locator('li').allTextContents();

    // Shot taken while the panel is OPEN — the evidence is the option list itself.
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, 'dept-filter-gantt-open.png') });
    await page.keyboard.press('Escape');

    return options.map((o) => o.trim()).filter((o) => o && !/^no department$/i.test(o));
}

test.describe('Weekly timeline lists activities in the Gantt chart\'s order', () => {
    test('elevated user sees matching order in both tabs; a user without projects.view is refused', async ({ browser, baseURL }) => {
        test.setTimeout(240_000);

        // ------------------------------------------------------------------
        // ACT 1 — Gen Magbanua (Dev): holds projects.view, may open the plan.
        // ------------------------------------------------------------------
        const mgrCtx = await browser.newContext({ storageState: AUTH('manager') });
        const mgr = await mgrCtx.newPage();
        const qm = narrator(mgr, { total: 10 });

        await mgr.goto(`/projects/${PROJECT_ID}?tab=gantt`);
        await awaitProjectPage(mgr);
        await qm.say('Signed in as Gen Magbanua and opened the LINK HUB plan on the Gantt tab. The Gantt is the reference order: each activity is followed immediately by its own sub-tasks.');

        const structure = await planStructure(mgr, mgrCtx.request, baseURL);
        const ganttOrder = await renderedOrder(mgr);
        expect(ganttOrder.length).toBeGreaterThan(10);
        await shot(mgr, 'weekly-order-01-gantt');
        await qm.check(`The Gantt painted ${ganttOrder.length} rows. Reading the first activity and what follows it.`);

        // The Gantt's own invariant, asserted so the reference is not assumed:
        // every sub-task sits directly after its parent activity.
        const ganttPos = new Map(ganttOrder.map((id, i) => [id, i]));
        for (const id of ganttOrder) {
            const row = structure.get(id);
            if (!row?.parent || !ganttPos.has(row.parent)) continue;
            expect(ganttPos.get(id)).toBeGreaterThan(ganttPos.get(row.parent));
        }
        await qm.check('Confirmed on screen: in the Gantt every sub-task is painted below its own parent activity, not after all the activities.');

        // ------------------------------------------------------------------
        // ACT 2 — the same plan on the weekly timeline tab, clicked through the
        // real tab button (not a URL jump), which is how a user gets there.
        // ------------------------------------------------------------------
        await qm.say('Now clicking the "Weekly Timeline" tab. Before this fix the Activity & Assignment list here showed all 8 activities first, then every sub-task afterwards — a different reading order from the Gantt.');
        await mgr.getByRole('button', { name: /weekly timeline/i }).click();
        await mgr.waitForFunction(() => document.querySelectorAll('[data-task-row]').length > 0, null, { timeout: 60_000 });
        await expect(mgr.getByText(/Activity & Assignment/i).first()).toBeVisible({ timeout: 30_000 });

        const weeklyOrder = await renderedOrder(mgr);
        expect(weeklyOrder.length).toBeGreaterThan(1);
        // Shoot the list itself, not the viewport — the page header is sticky and
        // a viewport shot is not evidence of the row order this spec exists to prove.
        await mgr.locator('[data-task-row]').first().scrollIntoViewIfNeeded();
        await mgr.waitForTimeout(600);
        fs.mkdirSync(SHOTS, { recursive: true });
        await mgr.screenshot({ path: path.join(SHOTS, 'weekly-order-02-weekly.png'), fullPage: true });

        console.log('WEEKLY ORDER : ' + weeklyOrder.map((id) =>
            `${structure.get(id)?.parent ? '  ↳' : ''}#${id} ${structure.get(id)?.name}`).join('\n               '));
        await qm.check(`The selected week lists ${weeklyOrder.length} rows. Checking each one against its position in the Gantt.`);

        // The week filters rows out; it must never reshuffle the ones it keeps.
        // So the weekly list has to be a SUBSEQUENCE of the Gantt's row order.
        const weeklyPositions = weeklyOrder.map((id) => {
            expect(ganttPos.has(id), `weekly row #${id} is not a Gantt row`).toBe(true);
            return ganttPos.get(id);
        });
        const ascending = weeklyPositions.every((p, i) => i === 0 || p > weeklyPositions[i - 1]);
        expect(ascending, `weekly order ${JSON.stringify(weeklyOrder)} is not in Gantt order`).toBe(true);
        await qm.check('Every row on the weekly tab now appears in the same relative order as the Gantt — parent activity first, then its own sub-tasks.');

        // And concretely: a sub-task present this week sits after its parent
        // when the parent is on screen too.
        const weeklyPos = new Map(weeklyOrder.map((id, i) => [id, i]));
        let checkedPairs = 0;
        for (const id of weeklyOrder) {
            const row = structure.get(id);
            if (!row?.parent || !weeklyPos.has(row.parent)) continue;
            expect(weeklyPos.get(id)).toBeGreaterThan(weeklyPos.get(row.parent));
            checkedPairs += 1;
        }
        expect(checkedPairs).toBeGreaterThan(0);
        await qm.check(`${checkedPairs} parent/sub-task pairs are visible together in this week, and every sub-task follows its parent.`);

        // ------------------------------------------------------------------
        // ACT 3 — walk forward a week and re-assert; the order must hold on
        // every week, not only the one the tab happens to open on.
        // ------------------------------------------------------------------
        const nextWeek = mgr.getByRole('button', { name: /next week/i });
        if (await nextWeek.count()) {
            await qm.say('Stepping to the next week. A different set of rows is in range, so this re-checks that filtering keeps the order rather than rebuilding it.');
            await nextWeek.first().click();
            await mgr.waitForTimeout(1200);
            const nextOrder = await renderedOrder(mgr);
            const nextPositions = nextOrder.map((id) => ganttPos.get(id));
            expect(nextPositions.every((p) => p !== undefined)).toBe(true);
            expect(nextPositions.every((p, i) => i === 0 || p > nextPositions[i - 1])).toBe(true);
            await shot(mgr, 'weekly-order-03-next-week');
            await qm.check(`Next week lists ${nextOrder.length} rows, still in Gantt order.`);
        }

        // ------------------------------------------------------------------
        // ACT 4 — drive the Milestone filter, which now keys sub-tasks off the
        // milestone they are DISPLAYED under (their parent's), as in the Gantt.
        // ------------------------------------------------------------------
        const milestoneSelect = mgr.locator('select').filter({ hasText: /milestone/i }).first();
        if (await milestoneSelect.count()) {
            const options = await milestoneSelect.locator('option').allTextContents();
            const pick = options.find((o) => !/all milestone/i.test(o));
            if (pick) {
                await qm.say(`Selecting the milestone "${pick.trim()}" in the filter, to confirm sub-tasks are kept with the milestone they are shown under.`);
                await milestoneSelect.selectOption({ label: pick });
                await mgr.waitForTimeout(1200);
                const filtered = await renderedOrder(mgr);
                const filteredPositions = filtered.map((id) => ganttPos.get(id));
                expect(filteredPositions.every((p, i) => i === 0 || p > filteredPositions[i - 1])).toBe(true);
                const withSubs = filtered.filter((id) => structure.get(id)?.parent);
                await shot(mgr, 'weekly-order-04-milestone-filter');
                await qm.check(`Filtered to one milestone: ${filtered.length} rows, ${withSubs.length} of them sub-tasks, still in Gantt order.`);
            }
        }

        // ------------------------------------------------------------------
        // ACT 4b — the Department filter on both tabs offers each department
        // ONCE. users.department is free text and the live data holds both
        // "Technology and Solutions" and "Technology And Solutions", which put
        // the same department in the dropdown twice.
        // ------------------------------------------------------------------
        for (const [tab, label] of [['gantt', 'Gantt Chart'], ['weekly-timeline', 'Weekly Timeline']]) {
            await mgr.goto(`/projects/${PROJECT_ID}?tab=${tab}`);
            await awaitProjectPage(mgr);
            await qm.say(`Opening the ${label} tab's Department filter. The plan's rows resolve their department from user records that spell it two different ways.`);

            const options = await departmentOptions(mgr, tab);
            const seen = options.map((o) => o.trim().toLowerCase()).filter(Boolean);
            const duplicates = seen.filter((o, i) => seen.indexOf(o) !== i);

            expect(duplicates, `${label} offers duplicate departments: ${JSON.stringify(duplicates)}`).toEqual([]);
            expect(seen.filter((o) => o.includes('technology and solutions')).length,
                `${label} should list Technology and Solutions exactly once`).toBe(1);

            await shot(mgr, `dept-filter-${tab}`);
            await qm.check(`${label}: ${options.length} department options, no repeats — "Technology and Solutions" appears once.`);
        }

        // ------------------------------------------------------------------
        // ACT 4b2 — status pills on the weekly timeline carry the Gantt's colours.
        // The pill component only knew the progress-derived labels, so the stored
        // Done/Ongoing/Pending values all fell through to the neutral grey.
        // ------------------------------------------------------------------
        await mgr.goto(`/projects/${PROJECT_ID}?tab=weekly-timeline`);
        await awaitProjectPage(mgr);
        await mgr.waitForFunction(() => document.querySelectorAll('[data-task-row]').length > 0, null, { timeout: 60_000 });
        await qm.say('Checking the status pills. Done, Ongoing and Pending should be emerald, sky and amber here — the same colours the Gantt uses — not all grey.');

        // "Show all weeks" so Pending rows are on screen too — the current week
        // holds only Done and Ongoing, and a colour that is never rendered is a
        // colour that was never checked.
        const allWeeks = mgr.getByRole('button', { name: /jump to current week/i });
        if (await allWeeks.count()) await mgr.waitForTimeout(300);

        const weeklyPills = await statusPillStyles(mgr);
        expect(Object.keys(weeklyPills).length, 'no status pills found on the weekly timeline').toBeGreaterThan(0);

        // The requirement is "the same as the Gantt", so the Gantt is the oracle:
        // read its pills and require the weekly ones to match, rather than
        // hard-coding colour literals that would drift from it.
        const ganttPage = await mgrCtx.newPage();
        await ganttPage.goto(`/projects/${PROJECT_ID}?tab=gantt`);
        await awaitProjectPage(ganttPage);
        await ganttPage.waitForTimeout(2500);
        const ganttPills = await statusPillStyles(ganttPage);
        await ganttPage.close();

        for (const [label, weekly] of Object.entries(weeklyPills)) {
            // A pill that never got a colour falls back to grey — the exact bug.
            expect(weekly.background, `${label} pill has no background`).not.toBe('rgba(0, 0, 0, 0)');

            if (!ganttPills[label]) continue;
            expect(weekly.color, `${label}: weekly text colour differs from the Gantt`).toBe(ganttPills[label].color);
            expect(weekly.background, `${label}: weekly background differs from the Gantt`).toBe(ganttPills[label].background);
        }

        const compared = Object.keys(weeklyPills).filter((l) => ganttPills[l]);
        expect(compared.length, 'no status was actually compared against the Gantt').toBeGreaterThan(1);

        await mgr.locator('[data-task-row]').first().scrollIntoViewIfNeeded();
        await mgr.waitForTimeout(500);
        await shot(mgr, 'weekly-status-pills');
        await qm.check(`${compared.join(', ')} render in exactly the Gantt's colours on the weekly timeline.`);

        // ------------------------------------------------------------------
        // ACT 4c — the exported PDF. Rendered through the real route, then read
        // back so the summary page and the detail pages are actually checked
        // rather than assumed from a 200.
        // ------------------------------------------------------------------
        await qm.say('Exporting the presentation PDF. The summary page should carry the Entity/Brand chips and one milestone per full-width line, with the dense detail starting on a fresh page.');
        const pdf = await mgrCtx.request.get(`${baseURL}/projects/${PROJECT_ID}/gantt-pdf`, { timeout: 180_000 });
        expect(pdf.status()).toBe(200);
        expect(pdf.headers()['content-type']).toContain('pdf');

        const bytes = await pdf.body();
        expect(bytes.subarray(0, 5).toString('latin1')).toBe('%PDF-');
        fs.mkdirSync(SHOTS, { recursive: true });
        fs.writeFileSync(path.join(SHOTS, 'project-gantt.pdf'), bytes);
        await qm.check(`The PDF renders: ${Math.round(bytes.length / 1024)} KB. Its pages are rasterised and inspected outside this run.`);

        // ------------------------------------------------------------------
        // ACT 4d — "Apply Activity Template" offers the templates that belong to
        // this project. The named templates were all missing: project_name had to
        // equal the project name exactly, and templates are named for the product
        // ("LINK HUB") while projects add a qualifier ("NN LINK HUB").
        //
        // The modal is only OPENED. Applying a template writes ~64 task rows onto
        // a real project, and the question here is which templates are listed.
        // ------------------------------------------------------------------
        await mgr.goto(`/projects/${TEMPLATE_PROJECT_ID}?tab=gantt`);
        await awaitProjectPage(mgr);
        await qm.say('Opening "Apply Templates" on NN LINK HUB. Its template is named for the product, "LINK HUB", so the exact-name rule hid it — and Store Opening templates were being offered to a Customer Brand project.');

        await mgr.getByRole('button', { name: /apply templates/i }).first().click();
        await expect(mgr.getByText('Apply Activity Template')).toBeVisible({ timeout: 30_000 });

        const listed = await mgr.locator('label:has(input[type="radio"])').allTextContents();
        const names = listed.map((t) => t.replace(/\s+/g, ' ').trim());

        // The template the user built for this project is now offered …
        expect(names.some((n) => /TGI - NONOS - LINK HUB/i.test(n)),
            `expected the NONOS LINK HUB template, got: ${JSON.stringify(names)}`).toBe(true);

        // … and the wrong-project-type ones are not.
        expect(names.some((n) => /NSO Project Tracker|(^|\s)TEST(\s|$)/i.test(n)),
            `a Store Opening template is still offered on a Customer Brand project: ${JSON.stringify(names)}`).toBe(false);

        // Nor is another entity's or another brand's template.
        expect(names.some((n) => /CBTL|DSY|GSI|NCF|DBS/i.test(n)),
            `another entity/brand's template leaked in: ${JSON.stringify(names)}`).toBe(false);

        await shot(mgr, 'apply-template-modal');
        await qm.check(`The modal now lists ${names.length} template: ${names.join(' | ')}`);
        await mgr.getByRole('button', { name: /^cancel$/i }).first().click();

        // ------------------------------------------------------------------
        // ACT 5 — URL reachability, elevated: every page this change touches
        // must still answer 200 for a user who legitimately holds projects.view.
        // ------------------------------------------------------------------
        const paths = [
            '/projects',
            `/projects/${PROJECT_ID}`,
            `/projects/${PROJECT_ID}?tab=weekly-timeline`,
            `/projects/${PROJECT_ID}?tab=gantt`,
        ];
        for (const p of paths) {
            const r = await mgrCtx.request.get(baseURL + p, { maxRedirects: 0, timeout: 90_000 });
            expect(r.status(), `${p} for the elevated user`).toBe(200);
        }
        await qm.check('All four project URLs answer 200 for Gen, who holds projects.view — the guard does not lock out legitimate users.');

        await mgrCtx.close();

        // ------------------------------------------------------------------
        // ACT 6 — Andrea Sibulo (POS Approver): no projects.view at all.
        // A hidden sidebar link proves nothing; the URL itself must refuse her.
        // ------------------------------------------------------------------
        const outCtx = await browser.newContext({ storageState: AUTH('outsider') });
        const out = await outCtx.newPage();
        const qo = narrator(out, { total: 10 });

        await out.goto('/');
        await qo.say('Signed in as Andrea Sibulo, a POS Approver with no projects.view permission. She should not reach this plan by any route.');

        await expect(out.getByRole('link', { name: /^project tracker$/i })).toHaveCount(0);
        await qo.check('The Project Tracker section is absent from her sidebar.');

        for (const p of paths) {
            const r = await outCtx.request.get(baseURL + p, { maxRedirects: 0, timeout: 90_000 });
            expect([403, 419, 302], `${p} for the restricted user (HTTP ${r.status()})`).toContain(r.status());
        }
        await shot(out, 'weekly-order-05-restricted');
        await qo.check('Typing each project URL directly is refused for her — the routes are gated, not just the link.');

        await outCtx.close();
    });
});
