const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

// NN LINK HUB — created by Gen Magbanua (#3), so he manages it. Arwin Lazarraga
// (#34) is on the team but is NOT the creator and holds neither the Admin nor
// the Solutions Admin role; he owns exactly one of its milestones.
const PROJECT_ID = 10027;
const GANTT = `/projects/${PROJECT_ID}?tab=gantt`;
const OWNED_MILESTONE = 'FM Ticketing';
const FOREIGN_MILESTONE = "All Departments' Process Implementation in LINK HUB";

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: false });
};

async function awaitGantt(page) {
    await page.waitForFunction(
        () => JSON.parse(document.querySelector('#app')?.dataset.page || '{}').component === 'Projects/Show',
        null,
        { timeout: 60_000 }
    );
    await expect(page.getByText('Project Timeline').first()).toBeVisible({ timeout: 60_000 });
}

/** What the server actually told this account — not a scrape of the DOM. */
async function planProps(page) {
    const raw = await page.locator('#app').getAttribute('data-page');
    const props = JSON.parse(raw).props;

    return {
        canManageProject: props.canManageProject,
        canAddMilestone: props.canAddMilestone,
        milestones: props.milestones || [],
    };
}

/**
 * The sticky milestone header row carrying `category`. Scoped to the row so the
 * buttons found below belong to that milestone and not to a neighbouring one.
 */
const milestoneHeader = (page, category) =>
    page.locator('div.sticky.left-0').filter({ hasText: category }).first();

/** CSRF token for the request-context probes below. */
async function xsrf(context) {
    return decodeURIComponent(
        (await context.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || ''
    );
}

/**
 * Who may change a project plan, verified in the browser with two contrasting
 * accounts plus one with no access at all.
 *
 * NOTHING here deletes: project_tasks soft-deletes and the soft-delete path is
 * never executed from QA (global database-safety rule). The delete probes below
 * are the FORBIDDEN direction only — they abort before any row is touched — and
 * the permitted add/edit/delete paths are covered by the isolated SQLite suite
 * in tests/Feature/ProjectMilestoneOwnershipTest.php.
 */
test.describe('Project plan access follows milestone / activity ownership', () => {
    test('the project manager, a milestone owner, and an account with no access', async ({ browser }) => {
        test.setTimeout(180_000);

        // ------------------------------------------------------------------
        // ACT 1 — Gen Magbanua (#3): created this project, so he manages it all.
        // ------------------------------------------------------------------
        const mgrCtx = await browser.newContext({ storageState: AUTH('manager') });
        const mgr = await mgrCtx.newPage();
        const qm = narrator(mgr, { total: 10 });

        await mgr.goto(GANTT);
        await awaitGantt(mgr);
        await qm.say('Gen Magbanua created this project, so he manages the whole plan.');

        const mgrProps = await planProps(mgr);
        expect(mgrProps.canManageProject).toBe(true);
        await qm.say('The server confirms canManageProject = true for him.');

        // Every milestone header offers him "+ Activity" and the owner chip.
        await expect(milestoneHeader(mgr, OWNED_MILESTONE).getByRole('button', { name: /^Activity$/ }))
            .toBeVisible({ timeout: 30_000 });
        await expect(milestoneHeader(mgr, FOREIGN_MILESTONE).getByRole('button', { name: /^Activity$/ }))
            .toBeVisible();
        await qm.say('A manager can add an activity to EVERY milestone.');

        await expect(mgr.getByRole('button', { name: /Add Milestone/i })).toBeVisible();
        await qm.say('And he alone-plus-owners get the "Add Milestone" button.');

        // The owner he set is shown on the milestone header.
        await expect(milestoneHeader(mgr, OWNED_MILESTONE).getByRole('button', { name: /Arwin/i }))
            .toBeVisible();
        await qm.say(`"${OWNED_MILESTONE}" is owned by Arwin Lazarraga — shown right on the header.`);
        await shot(mgr, 'milestone-ownership-manager');

        // The picker opens and is closed again without saving — this spec writes
        // nothing.
        await milestoneHeader(mgr, OWNED_MILESTONE).getByRole('button', { name: /Arwin/i }).click();
        await expect(mgr.getByRole('heading', { name: /Milestone Owner/i })).toBeVisible({ timeout: 15_000 });
        await expect(mgr.getByText(/Only project team members can own a milestone/i)).toBeVisible();
        // The picker is pre-filled with the current owner: a closed Autocomplete
        // renders its selected label as text, and only swaps in a search input
        // once it is opened.
        await expect(mgr.locator('dialog[open]').getByText('Arwin Lazarraga')).toBeVisible();
        await qm.say('Clicking the chip opens the owner picker, pre-filled with him.');
        await shot(mgr, 'milestone-ownership-picker');
        await mgr.getByRole('button', { name: /^Cancel$/ }).click();
        await expect(mgr.getByRole('heading', { name: /Milestone Owner/i })).toHaveCount(0);

        // ------------------------------------------------------------------
        // ACT 2 — Arwin Lazarraga (#34): owns ONE milestone, manages no project.
        // ------------------------------------------------------------------
        const ownerCtx = await browser.newContext({ storageState: AUTH('tester') });
        const owner = await ownerCtx.newPage();
        const qo = narrator(owner, { total: 10 });

        await owner.goto(GANTT);
        await awaitGantt(owner);
        await qo.say('Arwin Lazarraga is on the team but did NOT create this project.');

        const ownerProps = await planProps(owner);
        expect(ownerProps.canManageProject).toBe(false);
        expect(ownerProps.canAddMilestone).toBe(true);
        await qo.say('canManageProject = false, yet canAddMilestone = true: he owns a milestone.');

        const owned = ownerProps.milestones.find((m) => m.category === OWNED_MILESTONE);
        expect(owned).toBeTruthy();
        expect(Number(owned.assigned_to)).toBe(34);
        await qo.say(`The plan tells him he owns "${OWNED_MILESTONE}".`);

        await expect(owner.getByText(/You run 1 milestone/i)).toBeVisible({ timeout: 30_000 });
        await qo.say('The toolbar says so too, instead of the old read-only warning.');

        // His own milestone: full structural control.
        await expect(milestoneHeader(owner, OWNED_MILESTONE).getByRole('button', { name: /^Activity$/ }))
            .toBeVisible({ timeout: 30_000 });
        await qo.say(`Inside "${OWNED_MILESTONE}" he gets "+ Activity" — he runs it.`);

        // Somebody else's milestone: nothing structural at all.
        await expect(milestoneHeader(owner, FOREIGN_MILESTONE).getByRole('button', { name: /^Activity$/ }))
            .toHaveCount(0);
        await qo.say(`Inside "${FOREIGN_MILESTONE}" that button is gone — not his milestone.`);
        await shot(owner, 'milestone-ownership-owner');

        // -- 403 probes. A hidden button proves nothing; the URL must refuse too.
        const token = await xsrf(ownerCtx);

        const addForeignActivity = await ownerCtx.request.post('/projects-tasks', {
            headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            form: {
                project_id: PROJECT_ID,
                name: 'E2E-should-never-exist',
                category: FOREIGN_MILESTONE,
                status: 'Pending',
                progress: 0,
            },
            failOnStatusCode: false,
        });
        expect(addForeignActivity.status()).toBe(403);
        await qo.say('Posting an activity into that milestone by URL: 403 Forbidden.');

        const takeOverForeign = await ownerCtx.request.put(`/projects/${PROJECT_ID}/milestone-owner`, {
            headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            form: { category: FOREIGN_MILESTONE, assigned_to: 34 },
            failOnStatusCode: false,
        });
        expect(takeOverForeign.status()).toBe(403);
        await qo.say('Handing himself that milestone by URL: 403 as well.');

        // The forbidden direction only — this aborts before anything is deleted.
        const deleteForeign = await ownerCtx.request.delete(`/projects/${PROJECT_ID}/milestone-tasks`, {
            headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            form: { category: FOREIGN_MILESTONE },
            failOnStatusCode: false,
        });
        expect(deleteForeign.status()).toBe(403);
        await qo.say('Deleting it by URL: 403. Nothing was touched.');

        // ------------------------------------------------------------------
        // ACT 3 — Andrea Sibulo (#55): holds no projects.view at all.
        // ------------------------------------------------------------------
        const outCtx = await browser.newContext({ storageState: AUTH('outsider') });
        const out = await outCtx.newPage();

        const denied = await out.goto(GANTT);
        expect(denied.status()).toBe(403);
        await shot(out, 'milestone-ownership-outsider');

        // Nothing was created by any of the probes above.
        const stillGone = await mgr.evaluate(async () => {
            const response = await fetch(window.location.href, { headers: { Accept: 'text/html' } });
            return (await response.text()).includes('E2E-should-never-exist');
        });
        expect(stillGone).toBe(false);

        await mgrCtx.close();
        await ownerCtx.close();
        await outCtx.close();
    });
});
