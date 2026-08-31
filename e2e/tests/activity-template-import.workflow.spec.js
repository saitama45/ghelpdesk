const { test, expect } = require('@playwright/test');
const path = require('node:path');
const fs = require('node:fs');
const { narrator } = require('qa-kit/narrate');

const WORKBOOK = path.resolve(__dirname, '..', '..', 'References', 'Hybrid_SDLC_Agile_Activity_Templates.xlsx');
const SHOTS = path.resolve(__dirname, '..', 'screenshots');

test('imports and inspects the narrated Hybrid SDLC Agile workbook', async ({ page }) => {
    const qa = narrator(page, { total: 8, hold: 1000 });
    fs.mkdirSync(SHOTS, { recursive: true });

    await page.goto('/login');
    await page.getByLabel(/email/i).fill('activity-template-e2e@example.test');
    await page.getByLabel(/password/i).fill('ActivityTemplateE2E!2026');
    await page.getByRole('button', { name: /secure sign in/i }).click();
    await page.waitForURL(url => !url.pathname.endsWith('/login'));
    await page.goto('/activity-templates');
    await qa.say('We are in an isolated SQLite test application. No development or production template data can be changed by this walkthrough.');
    await expect(page.getByText('Project Activity Blueprints')).toBeVisible();

    await page.getByRole('button', { name: /Import Excel/i }).click();
    await qa.say('The real Activity Templates import dialog accepts one workbook containing multiple entity, brand, and application-project templates.');
    const dialog = page.locator('dialog[open]');
    await dialog.locator('input[type=file]').setInputFiles(WORKBOOK);
    await qa.say('The selected workbook contains 13 recommended templates and 1,473 activity and sub-task rows, including the cross-department LINK HUB process implementation structure.');
    await dialog.getByRole('button', { name: /Start Import/i }).click();

    await expect(dialog.getByText(/Imported 13 template\(s\); skipped 0 template\(s\)/i)).toBeVisible({ timeout: 180_000 });
    await qa.check('All 13 templates imported successfully through the real upload endpoint.');
    await page.screenshot({ path: path.join(SHOTS, 'activity-template-import-success.png'), fullPage: false });

    await dialog.getByRole('button', { name: /^Close$/i }).click();
    await page.getByPlaceholder(/Search templates/i).fill('TGI - CBTL - DAVID');
    await page.waitForTimeout(1200);
    await qa.check('The entity-brand-project identity is visible in the template list while Project Name remains DAVID.');
    await expect(page.getByText('TGI - CBTL - DAVID - Hybrid SDLC Agile')).toBeVisible();

    await page.locator('button[title="Edit Template"]').first().click();
    await qa.say('Opening the imported template shows its hierarchy, weights, acceptance criteria, and the Per Store activity used for six-store rollout planning.');
    const editDialog = page.locator('dialog[open]');
    await expect(editDialog.locator('input#project_name')).toHaveValue('DAVID');
    const activityValues = await editDialog.locator('input').evaluateAll(inputs => inputs.map(input => input.value));
    expect(activityValues).toContain('Store Deployment');
    await page.screenshot({ path: path.join(SHOTS, 'activity-template-david-hierarchy.png'), fullPage: false });
    await editDialog.locator('button').first().click();

    await page.getByRole('button', { name: /Import Excel/i }).click();
    const duplicateDialog = page.locator('dialog[open]');
    await duplicateDialog.locator('input[type=file]').setInputFiles(WORKBOOK);
    await duplicateDialog.getByRole('button', { name: /Start Import/i }).click();
    await expect(duplicateDialog.getByText(/Imported 0 template\(s\); skipped 13 template\(s\)/i)).toBeVisible({ timeout: 180_000 });
    await qa.check('Re-import is safe: every exact duplicate is skipped and no existing template is overwritten.');
    await qa.warn('The workbook is now proven importable, but it has only been loaded into this disposable browser-test database.');
});
