const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);

// A day that actually has attendance on it. Defaulting to "today" renders an
// empty table, which would make every "cannot see other people" assertion pass
// for the wrong reason.
const BUSY_DAY = '2026-05-08';
const BUSY_RANGE = `?date_from=${BUSY_DAY}&date_to=${BUSY_DAY}`;
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: false });
};

/**
 * Waits for the Inertia page itself rather than for a heading.
 *
 * The page's title is rendered as plain text, not a heading role, and it is
 * entity-prefixed ("TGI Attendance History") — matching on it would tie this
 * spec to whichever company the account is scoped to.
 */
async function awaitLogsPage(page) {
    await page.waitForFunction(
        () => JSON.parse(document.querySelector('#app')?.dataset.page || '{}').component === 'Attendance/Logs',
        null,
        { timeout: 60_000 }
    );
    await expect(page.getByText(/Attendance History/i).first()).toBeVisible({ timeout: 60_000 });
}

/**
 * Reads the people offered by the "All Sub-Units"/people filter payload that the
 * page was rendered with. Inertia leaves the whole prop bag on the root element,
 * so this is what the server actually decided this account may see — not a
 * best-effort scrape of whichever rows happen to be on screen today.
 */
async function visiblePeople(page) {
    const raw = await page.locator('#app').getAttribute('data-page');
    const props = JSON.parse(raw).props;

    return {
        users: (props.users || []).map((u) => ({ id: Number(u.id), name: u.name })),
        sessionUserIds: [...new Set((props.sessions?.data || [])
            .map((s) => Number(s?.user?.id))
            .filter(Boolean))],
    };
}

/**
 * Attendance carries a selfie, a GPS fix and a work pattern for a named person.
 * Who may see whose is the whole point of this page, so it is verified with three
 * contrasting accounts rather than one administrator.
 */
test.describe('Attendance log visibility follows the reporting line', () => {
    test('a department administrator, a rank-and-file employee, and an account with no permission', async ({ browser }) => {
        test.setTimeout(180_000);

        // ------------------------------------------------------------------
        // ACT 1 — Gen Magbanua: manager AND holder of attendance.logs_department.
        // ------------------------------------------------------------------
        const mgrCtx = await browser.newContext({ storageState: AUTH('manager') });
        const mgr = await mgrCtx.newPage();
        const qm = narrator(mgr, { total: 9 });

        await mgr.goto('/attendance/logs');
        await qm.say('Signed in as Gen Magbanua. Until today this page showed every employee in the company to anyone holding the Admin, Dev or Solutions Admin role. That is gone.');
        await awaitLogsPage(mgr);

        const gen = await visiblePeople(mgr);
        await qm.check(`Gen can see ${gen.users.length} people. There are 74 active accounts, so this is no longer "everyone".`);

        // Gen holds the new per-account grant, so the boundary is the department.
        expect(gen.users.length).toBeGreaterThan(1);
        expect(gen.users.length).toBeLessThan(74);
        expect(gen.users.map((u) => u.id)).toContain(3);

        const andreaVisibleToGen = gen.users.some((u) => u.id === 55);
        await qm.check('Gen holds the new attendance.logs_department grant, so the boundary is his own department — Technology and Solutions — and nothing beyond it.');
        expect(andreaVisibleToGen).toBe(false);
        await shot(mgr, 'attendance-visibility-manager');

        // Rows, not just the filter list: a day the department really worked.
        await mgr.goto(`/attendance/logs${BUSY_RANGE}`);
        await awaitLogsPage(mgr);
        const genRows = await visiblePeople(mgr);
        await qm.check(`On ${BUSY_DAY} Gen sees sessions for ${genRows.sessionUserIds.length} different people — the department he administers, and only them.`);
        expect(genRows.sessionUserIds.length).toBeGreaterThan(1);
        expect(genRows.sessionUserIds).not.toContain(55);

        await mgrCtx.close();

        // ------------------------------------------------------------------
        // ACT 2 — Arwin Lazarraga: same department, holds attendance.logs,
        //          but manages nobody.
        // ------------------------------------------------------------------
        const testerCtx = await browser.newContext({ storageState: AUTH('tester') });
        const tester = await testerCtx.newPage();
        const qt = narrator(tester, { total: 9 });

        await tester.goto('/attendance/logs');
        await qt.say('Now Arwin Lazarraga. He holds the same attendance.logs permission and sits in the same department as Gen — but nobody reports to him.');
        await awaitLogsPage(tester);

        const arwin = await visiblePeople(tester);
        await qt.check(`Arwin can see exactly ${arwin.users.length} person: himself. Sharing a department is not what opens the page — being above someone in the org chart is.`);
        expect(arwin.users.map((u) => u.id)).toEqual([34]);
        expect(arwin.sessionUserIds.filter((id) => id !== 34)).toEqual([]);

        await qt.warn('Filtering is not a security boundary, so the same rule is proven against the raw payload the server sent, not just the rows drawn on screen.');
        expect(arwin.users.map((u) => u.id)).not.toContain(3);
        await shot(tester, 'attendance-visibility-employee');

        // The same day Gen just saw two dozen people on. The date filter is the
        // obvious way to try to widen the net.
        await tester.goto(`/attendance/logs${BUSY_RANGE}`);
        await awaitLogsPage(tester);
        const arwinBusyDay = await visiblePeople(tester);
        await qt.check(`On ${BUSY_DAY} — the day Gen just saw two dozen people on — Arwin's own session is the only one on the page. The scope is applied to the query, not to the page of results.`);
        expect(arwinBusyDay.sessionUserIds).toEqual([34]);

        await tester.goto('/attendance/logs?date_from=2020-01-01&date_to=2030-12-31');
        await awaitLogsPage(tester);
        const arwinWide = await visiblePeople(tester);
        await qt.check(`Opening the range to a decade changes nothing: still ${arwinWide.users.length} person in the filter.`);
        expect(arwinWide.sessionUserIds.filter((id) => id !== 34)).toEqual([]);

        await testerCtx.close();

        // ------------------------------------------------------------------
        // ACT 3 — Andrea Sibulo: no attendance.logs at all.
        // ------------------------------------------------------------------
        const outsiderCtx = await browser.newContext({ storageState: AUTH('outsider') });
        const outsider = await outsiderCtx.newPage();
        const qo = narrator(outsider, { total: 9 });

        await outsider.goto('/dashboard');
        await qo.say('Finally Andrea Sibulo, a POS Approver in another department, who holds no attendance.logs permission at all.');

        const response = await outsider.goto('/attendance/logs');
        await qo.check(`Typing the URL in directly returns ${response.status()}. A hidden sidebar link proves nothing; the route itself is gated.`);
        expect(response.status()).toBe(403);
        await shot(outsider, 'attendance-visibility-403');

        await outsiderCtx.close();
    });
});
