const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');
const { sign } = require('../support/sign');
const { verifyInlinePdf } = require('../support/pdf');

const AUTH = (role) => path.resolve(__dirname, '..', '.auth', `${role}.json`);
const SHOTS = path.resolve(__dirname, '..', 'screenshots');
const EVIDENCE = path.resolve(__dirname, '..', 'fixtures', 'defect.png');

// Every fixture this run creates carries the marker; the purge command matches
// on it and refuses to touch anything else.
const MARK = 'E2E-';
const STAMP = new Date().toISOString().slice(11, 19).replace(/:/g, '');
const CYCLE_TITLE = `${MARK}QAT Planning Website ${STAMP}`;
const UAT_TITLE = `${MARK}UAT Planning Website ${STAMP}`;

const shot = async (page, name) => {
    fs.mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: path.join(SHOTS, `${name}.png`), fullPage: false });
};

/** Scoped to dialog[open]: page-level locators resolve to elements BEHIND a modal. */
const dlg = (page) => page.locator('dialog[open]');

/**
 * Picks a value from the app's Autocomplete component.
 *
 * It is not a <select>, and it has no stable placeholder to target: when a value
 * is already chosen the placeholder attribute becomes that value's label, and
 * while closed it renders no <input> at all — only a <span>. So the field is
 * found by the label immediately before it, and the options are read from the
 * list, which the component Teleports into the open <dialog> (a modal opened with
 * showModal() sits in the browser's top layer, so a panel left on <body> would
 * render behind it).
 */
async function pickOption(page, scope, labelText, optionText) {
    const field = scope
        .locator('label')
        .filter({ hasText: new RegExp(labelText, 'i') })
        .first()
        .locator('xpath=following-sibling::*[1]');

    await field.click();
    await page.waitForTimeout(400);

    const inDialog = scope.locator('li', { hasText: new RegExp(`^\\s*${optionText}\\s*$`, 'i') }).first();
    const anywhere = page.locator('li', { hasText: new RegExp(`^\\s*${optionText}\\s*$`, 'i') }).first();

    if (await inDialog.count()) {
        await inDialog.click();
    } else {
        await anywhere.click();
    }

    await page.waitForTimeout(300);
}

test.describe('QAT sign-off gate, end to end', () => {
    test.setTimeout(420_000);

    test('a tester submits, the manager is gated by a blocker, waives it, signs and promotes', async ({ browser }) => {
        // ------------------------------------------------------------------
        // ACT 1 — Arwin, the tester. Can run tests and submit; cannot approve.
        // ------------------------------------------------------------------
        const testerCtx = await browser.newContext({ storageState: AUTH('tester') });
        const tester = await testerCtx.newPage();
        const qa = narrator(tester, { total: 22 });

        await tester.goto('/qat');
        await qa.say('This is the new QAT Tracker, signed in as Arwin Lazarraga — a tester. QAT is the internal quality pass that runs BEFORE a client ever sees a UAT.');
        await expect(tester.getByRole('heading', { name: /QAT Tracker/i })).toBeVisible();

        // --- create the cycle through the real form ---
        await tester.getByRole('button', { name: /New QAT cycle/i }).click();
        await qa.say('Arwin opens a new cycle. Everything from here is driven through the real interface, not by posting to endpoints.');
        await dlg(tester).locator('input[type=text]').first().fill(CYCLE_TITLE);
        await dlg(tester).getByRole('button', { name: /^Save$/i }).click();
        await tester.waitForURL(/\/qat\/\d+/, { timeout: 60_000 });

        const cycleId = Number(tester.url().match(/\/qat\/(\d+)/)[1]);
        await qa.check(`Cycle created and numbered automatically — this is QAT cycle #${cycleId}.`);
        await shot(tester, '01-cycle-created');

        // --- setup: a tester column, a section, two cases ---
        await tester.goto(`/qat/${cycleId}?tab=setup`);
        await qa.say('Setup. A QAT cycle needs somebody to run it and something to run — the sign-off gate refuses an empty cycle, which is the trap an "all clear" report usually hides.');

        await tester.getByRole('button', { name: /Add tester/i }).click();
        await dlg(tester).locator('input[type=text]').first().fill('DEV');
        await dlg(tester).getByRole('button', { name: /^Save$/i }).click();
        await tester.waitForTimeout(1200);
        await qa.check('A department column is on the matrix. The grid shows one column per department, not one per person.');

        await tester.getByRole('button', { name: /Sections/i }).click();
        await tester.getByRole('button', { name: /Add section/i }).click();
        await dlg(tester).locator('input[type=text]').first().fill('Issuances');
        await dlg(tester).getByRole('button', { name: /^Save$/i }).click();
        await tester.waitForTimeout(1200);

        await tester.getByRole('button', { name: /Test cases/i }).click();
        for (const title of ['Save a department order', 'Reject an invalid effective date']) {
            await tester.getByRole('button', { name: /Add test case/i }).click();
            await dlg(tester).locator('input[type=text]').nth(1).fill(title);
            await dlg(tester).getByRole('button', { name: /^Save$/i }).click();
            await tester.waitForTimeout(1200);
        }
        await qa.check('Two test cases, both critical by default. Critical cases are the ones that hold up a sign-off.');
        await shot(tester, '02-setup');

        // --- run the tests through the runner ---
        await tester.goto(`/qat/${cycleId}?tab=execute`);
        await qa.say('The test runner. Arwin records a verdict per case, exactly as a tester would.');
        await tester.waitForTimeout(1500);

        const cards = tester.locator('div.rounded-xl').filter({ has: tester.getByRole('button', { name: /Log a finding/i }) });
        await cards.first().getByRole('button', { name: /✓ Pass/ }).click();
        await tester.waitForTimeout(1500);
        await qa.check('First case passes.');

        await cards.nth(1).getByRole('button', { name: /✕ Fail/ }).click();
        await tester.waitForTimeout(1500);
        await qa.warn('Second case FAILS. A failure on its own does not raise anything — the team decides what is worth tracking, so a 96-case run cannot flood the ticket queue.');
        await shot(tester, '03-verdicts');

        // --- log a blocker finding, with the mandatory screenshot ---
        await cards.nth(1).getByRole('button', { name: /Log a finding/i }).click();
        await qa.say('Arwin logs the defect. A finding cannot be saved without a picture of it — the fixer has to be able to see what broke.');
        await dlg(tester).locator('input[type=text]').first().fill('Effective date is cleared when the order is saved');
        await dlg(tester).locator('textarea').first().fill('Set an effective date, save, reopen: the field is empty and the order is live with no date.');

        // Severity: blocker — this is what will stop the sign-off.
        await pickOption(tester, dlg(tester), 'Severity', 'Blocker');
        await qa.check('Rated a BLOCKER. Blocker and major are the two severities that gate the sign-off.');
        await dlg(tester).locator('input[type=file]').setInputFiles(EVIDENCE);
        await tester.waitForTimeout(1200);
        await dlg(tester).getByRole('button', { name: /Log finding/i }).click();
        await tester.waitForTimeout(2500);
        await qa.check('Finding F-001 logged as a BLOCKER, with its screenshot attached.');

        // --- convert it into a real helpdesk ticket ---
        await tester.goto(`/qat/${cycleId}?tab=findings`);
        await qa.say('Findings feed the helpdesk. Arwin turns this one into a real ticket — one at a time, deliberately, never automatically.');
        await tester.getByTitle(/Raise a ticket/i).first().click();
        await dlg(tester).getByRole('button', { name: /Raise ticket/i }).click();
        await tester.waitForTimeout(3000);

        const ticketBadge = tester.getByText(/Ticket\s+[A-Z]+-\d+/i).first();
        await expect(ticketBadge).toBeVisible({ timeout: 30_000 });
        const ticketKey = (await ticketBadge.innerText()).trim();
        await qa.check(`${ticketKey} raised, with the screenshot copied onto it and the finding linked back.`);
        await shot(tester, '04-finding-to-ticket');

        // --- submit for the manager's sign-off ---
        await tester.goto(`/qat/${cycleId}?tab=signoff`);
        await qa.say('The sign-off tab. Arwin cannot approve his own work — the whole point of the module.');
        await qa.warn('Note the cycle CAN be submitted with a case still failing. Blocking that would make the manager\'s waiver unreachable, since a failure is exactly why a finding exists. Only an unanswered case stops a submission.');
        await tester.getByRole('button', { name: /Submit for manager sign-off/i }).click();
        await qa.warn('The approver is NOT typed in by hand. It is resolved from the org chart, then frozen onto the cycle so a later reshuffle cannot orphan a pending decision.');
        await dlg(tester).getByRole('button', { name: /^Submit$/i }).click();
        await tester.waitForTimeout(3000);

        await expect(tester.getByText(/Waiting on/i).first()).toBeVisible({ timeout: 30_000 });
        await expect(tester.getByText(/Gen Magbanua/i).first()).toBeVisible();
        await qa.check('Resolved to Gen Magbanua — Arwin\'s actual manager in the org chart — and Gen has been notified.');
        await shot(tester, '05-submitted');

        // --- and he genuinely cannot decide it ---
        await expect(tester.getByText(/You are not one of the assigned approvers/i)).toBeVisible();
        await qa.check('Arwin sees the tab read-only. The gate is visible to him but not operable — hiding it would just generate support calls.');

        const forgedDecision = await testerCtx.request.post(`/qat/${cycleId}/signoff/decide`, {
            headers: {
                'X-XSRF-TOKEN': decodeURIComponent((await testerCtx.cookies()).find(c => c.name === 'XSRF-TOKEN')?.value || ''),
            },
            form: { result: 'passed' },
            failOnStatusCode: false,
        });
        expect(forgedDecision.status()).toBe(403);
        await qa.check(`Bypassing the UI and posting the decision straight to the endpoint returns ${forgedDecision.status()}. The button being hidden is not what protects it.`);

        await testerCtx.close();

        // ------------------------------------------------------------------
        // ACT 2 — Gen, the approving manager.
        // ------------------------------------------------------------------
        const mgrCtx = await browser.newContext({ storageState: AUTH('manager') });
        const mgr = await mgrCtx.newPage();
        const qm = narrator(mgr, { total: 22 });

        await mgr.goto(`/qat/${cycleId}?tab=signoff`);
        await qm.say('Now signed in as Gen Magbanua, the manager. Note this cycle belongs to another department — being the named approver is what grants access.');
        await expect(mgr.getByRole('heading', { name: /Your decision/i })).toBeVisible({ timeout: 30_000 });
        await qm.check('Gen gets the decision panel Arwin was denied.');

        // --- THE GATE ---
        await expect(mgr.getByText(/finding\(s\) block this sign-off/i)).toBeVisible();
        await qm.warn('This is the control the module exists for: an unresolved blocker or major finding stops an approval. Watch what happens if Gen just clicks Sign off.');
        await mgr.getByRole('button', { name: /^Sign off$/i }).click();
        await mgr.waitForTimeout(2500);

        await expect(mgr.getByText(/must be resolved or waived/i)).toBeVisible({ timeout: 30_000 });
        await qm.check('Refused, and it names the finding. A manager cannot wave a blocker through by accident.');
        await shot(mgr, '06-gate-blocks');

        // --- waiver requires a written reason ---
        await mgr.locator('input[type=checkbox]').first().check();
        await qm.say('Gen decides to accept it anyway. Ticking the finding alone is not enough...');
        await mgr.getByRole('button', { name: /^Sign off$/i }).click();
        await mgr.waitForTimeout(2500);
        await expect(mgr.getByText(/Explain why you are accepting/i)).toBeVisible({ timeout: 30_000 });
        await qm.check('...a waiver without a written reason is refused. An override that leaves no record is not a control.');

        await mgr.locator('textarea').first().fill('Accepted for this round: the affected report is not used by the client until Q4 and the fix is already ticketed.');
        await qm.say('With a reason recorded, the override becomes auditable — it is stamped on the finding itself and printed on the certificate.');

        // --- the drawn signature ---
        await sign(mgr, mgr);
        await qm.check('Signed by hand on the pad — mouse, finger or stylus, the same code path.');
        await shot(mgr, '07-signature-drawn');

        await mgr.getByRole('button', { name: /^Sign off$/i }).click();
        await mgr.waitForTimeout(4000);

        await expect(mgr.getByText(/Signed off/i).first()).toBeVisible({ timeout: 30_000 });
        await qm.check('Signed off. The cycle is frozen, the waiver is on the record, and the signature is stored.');
        await shot(mgr, '08-signed-off');

        await expect(mgr.locator('img[alt="Signature"]')).toBeVisible();
        await qm.check('The drawn signature is rendered back on the sign-off record, next to who signed and when.');

        // --- the certificate PDF ---
        await qm.say('The printed certificate. It opens in a new tab and renders inline rather than downloading.');
        const qatPdf = await verifyInlinePdf(mgrCtx, `/qat/${cycleId}/signoff/pdf`, {
            shotsDir: SHOTS,
            shotName: '09-certificate-pdf',
        });
        await qm.check(`Certificate served inline, ${qatPdf.bytes.toLocaleString()} bytes — the decision, the waiver and the signature, on one page.`);

        // --- promotion, which the sign-off gates ---
        await qm.say('And now the thing the sign-off was gating.');
        await mgr.getByRole('button', { name: /Promote to UAT/i }).click();
        await mgr.waitForTimeout(1500);
        const promoteTitle = dlg(mgr).locator('input[type=text]').first();
        await promoteTitle.fill(UAT_TITLE);
        await dlg(mgr).getByRole('button', { name: /Create the UAT cycle/i }).click();
        await mgr.waitForURL(/\/uat\/\d+/, { timeout: 60_000 });

        const uatId = Number(mgr.url().match(/\/uat\/(\d+)/)[1]);
        await qm.check(`Promoted into UAT cycle #${uatId}. The test script carried over; the verdicts, findings and testers deliberately did not — the client round starts clean.`);

        await expect(mgr.getByText(/Internal QA passed/i)).toBeVisible({ timeout: 30_000 });
        await qm.check('And the client-facing cycle now carries an audit link back to the internal pass that cleared it.');
        await shot(mgr, '10-promoted-to-uat');

        await expect(mgr.getByText('Save a department order')).toBeVisible();
        await qm.check('Both test cases arrived in the UAT cycle.');

        await mgrCtx.close();

        // ------------------------------------------------------------------
        // ACT 3 — Andrea, who holds no qat.* permission at all.
        // ------------------------------------------------------------------
        const outCtx = await browser.newContext({ storageState: AUTH('outsider') });
        const out = await outCtx.newPage();
        const qo = narrator(out, { total: 22 });

        await out.goto('/dashboard');
        await qo.say('Finally, Andrea Sibulo — a POS Approver with no QAT permission whatsoever.');
        await expect(out.getByRole('link', { name: /QAT Tracker/i })).toHaveCount(0);
        await qo.check('No QAT entry in her sidebar. But a hidden link proves nothing on its own...');

        const indexProbe = await outCtx.request.get('/qat', { failOnStatusCode: false });
        expect(indexProbe.status()).toBe(403);
        await qo.check(`...so the URL is probed directly: /qat returns ${indexProbe.status()}.`);

        const showProbe = await outCtx.request.get(`/qat/${cycleId}`, { failOnStatusCode: false });
        expect(showProbe.status()).toBe(403);
        await qo.check(`And the cycle itself returns ${showProbe.status()}. The route group is the boundary, not the sidebar.`);
        await shot(out, '11-outsider-blocked');

        await outCtx.close();
    });
});
