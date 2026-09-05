const { test } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

const BASE = process.env.E2E_BASE_URL || 'http://127.0.0.1:8010';

/**
 * Logs in through APIRequestContext rather than the login form.
 *
 * Clicking "Secure Sign In" leaves the button on "AUTHENTICATING…" forever on
 * this machine. The backend is fine (POST /login is a 302 in well under a
 * second) — PHP's built-in server handles one connection at a time on Windows
 * and Chromium holds several keep-alive sockets open, so the Inertia XHR
 * starves. `PHP_CLI_SERVER_WORKERS` is POSIX-only and does nothing here.
 *
 * APIRequestContext shares the browser context's cookie jar, so the saved
 * storageState is a genuine logged-in session.
 */
async function login(context, email, password, role) {
    const loginPage = await context.request.get(`${BASE}/login`);

    // This app names its CSRF cookie per-app rather than the shared default
    // "XSRF-TOKEN" (cookies are scoped by host, not port, so the vendor portal
    // on the same host used to overwrite it). The page announces the name it
    // uses; reading "XSRF-TOKEN" blindly gets a 419.
    const cookieName = (await loginPage.text())
        .match(/name="csrf-cookie"\s+content="([^"]+)"/)?.[1] || 'XSRF-TOKEN';

    const xsrf = decodeURIComponent(
        (await context.cookies()).find(c => c.name === cookieName)?.value || ''
    );

    const response = await context.request.post(`${BASE}/login`, {
        headers: { 'X-XSRF-TOKEN': xsrf },
        form: { email, password },
        maxRedirects: 0,
        failOnStatusCode: false,
    });

    if (response.status() !== 302) {
        throw new Error(`Login failed for ${role} (${email}): HTTP ${response.status()}`);
    }

    const dir = path.resolve(__dirname, '..', '.auth');
    fs.mkdirSync(dir, { recursive: true });
    await context.storageState({ path: path.join(dir, `${role}.json`) });
}

test('authenticate tester', async ({ context }) => {
    await login(context, process.env.E2E_TESTER_EMAIL, process.env.E2E_TESTER_PASSWORD, 'tester');
});

test('authenticate manager', async ({ context }) => {
    await login(context, process.env.E2E_MANAGER_EMAIL, process.env.E2E_MANAGER_PASSWORD, 'manager');
});

test('authenticate outsider', async ({ context }) => {
    await login(context, process.env.E2E_OUTSIDER_EMAIL, process.env.E2E_OUTSIDER_PASSWORD, 'outsider');
});
