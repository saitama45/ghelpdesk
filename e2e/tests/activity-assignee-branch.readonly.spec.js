const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

// Same plan as milestone-ownership.readonly.spec.js, but run with NO milestone
// owner set. Arwin Lazarraga (#34) is then only an ACTIVITY ASSIGNEE, which is
// the middle tier: his own activity and its sub-tasks, and nothing else.
const PROJECT_ID = 10027;
const GANTT = `/projects/${PROJECT_ID}?tab=gantt`;
const MILESTONE = 'FM Ticketing';
const HIS_ACTIVITY = 'Solution Design and Release Planning';
const SOMEONE_ELSES_ACTIVITY = 'Discovery and Backlog';

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

/** The Gantt row whose activity/sub-task column carries `name`. */
const taskRow = (page, name) =>
    page.locator('[data-task-row]').filter({ hasText: name }).first();

/**
 * The middle tier of the plan's access rule, isolated.
 *
 * Being handed an activity earns exactly one branch: that activity, and adding /
 * editing / deleting sub-tasks under it. It must NOT earn a sibling activity, and
 * it must NOT earn the milestone. Requires FM Ticketing to have no owner — see
 * milestone-ownership.readonly.spec.js for the tier above this one.
 *
 * Read-only: no row is added, edited or deleted here.
 */
test.describe('An activity assignee gets their branch and nothing wider', () => {
    test('Arwin Lazarraga, assigned one activity and owning no milestone', async ({ browser }) => {
        test.setTimeout(180_000);

        const ctx = await browser.newContext({ storageState: AUTH('tester') });
        const page = await ctx.newPage();
        const q = narrator(page, { total: 7 });

        await page.goto(GANTT);
        await awaitGantt(page);

        const props = JSON.parse(await page.locator('#app').getAttribute('data-page')).props;
        expect(props.canManageProject).toBe(false);
        expect(props.canAddMilestone).toBe(false);
        expect((props.milestones || []).find((m) => Number(m.assigned_to) === 34)).toBeUndefined();
        await q.say('Arwin owns no milestone here — he is only assigned an activity.');

        await expect(page.getByText(/You can edit only your assigned rows/i)).toBeVisible({ timeout: 30_000 });
        await q.say('So the plan tells him plainly what he can touch.');

        await expect(page.getByRole('button', { name: /Add Milestone/i })).toHaveCount(0);
        await q.say('No "Add Milestone" — that tier is not his.');

        // The milestone header offers him nothing: not his milestone.
        await expect(
            page.locator('div.sticky.left-0').filter({ hasText: MILESTONE }).first()
                .getByRole('button', { name: /^Activity$/ })
        ).toHaveCount(0);
        await q.say(`No "+ Activity" on "${MILESTONE}" either — he cannot add a sibling.`);

        // His own activity: the sub-task "+" and the row delete are both his.
        const mine = taskRow(page, HIS_ACTIVITY);
        await mine.scrollIntoViewIfNeeded();
        await mine.hover();
        await expect(mine.getByTitle('Add Sub-task')).toBeVisible({ timeout: 15_000 });
        await expect(mine.getByTitle('Delete Activity')).toBeVisible();
        await q.say(`On "${HIS_ACTIVITY}" — his row — he gets Add Sub-task and Delete.`);
        await shot(page, 'activity-assignee-own-row');

        // A sibling activity assigned to someone else: neither button.
        const theirs = taskRow(page, SOMEONE_ELSES_ACTIVITY);
        await theirs.scrollIntoViewIfNeeded();
        await theirs.hover();
        await expect(theirs.getByTitle('Add Sub-task')).toHaveCount(0);
        await expect(theirs.getByTitle('Delete Activity')).toHaveCount(0);
        await q.say(`On "${SOMEONE_ELSES_ACTIVITY}" — someone else's row — neither appears.`);
        await shot(page, 'activity-assignee-foreign-row');

        // The URL refuses it too. Forbidden direction only: this aborts before
        // anything is created.
        const token = decodeURIComponent(
            (await ctx.cookies()).find((c) => c.name === 'XSRF-TOKEN')?.value || ''
        );
        const foreignId = await page.evaluate((name) => {
            const props = JSON.parse(document.querySelector('#app').dataset.page).props;
            return (props.project.tasks || []).find((t) => t.name === name)?.id ?? null;
        }, SOMEONE_ELSES_ACTIVITY);
        expect(foreignId).toBeTruthy();

        const response = await ctx.request.post('/projects-tasks', {
            headers: { 'X-XSRF-TOKEN': token, Accept: 'application/json' },
            form: {
                project_id: PROJECT_ID,
                parent_task_id: foreignId,
                name: 'E2E-should-never-exist',
                status: 'Pending',
                progress: 0,
            },
            failOnStatusCode: false,
        });
        expect(response.status()).toBe(403);
        await q.say('Posting a sub-task under that row by URL: 403 Forbidden.');

        await ctx.close();
    });
});
