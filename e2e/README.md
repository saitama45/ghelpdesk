# Browser QA — ghelpdesk

Playwright suite driving the real UI. Playwright itself is **not** installed here:
it comes from the machine-wide kit at `~/.claude/qa`, exposed as `qa` / `qa-watch`.

## Running

```powershell
cd e2e
qa            # headless, everything. Use for a regression check.
qa-watch      # headed + slowed, with the narration banner. Use to watch it work.

qa-watch --project=workflow qat-signoff     # just the QAT story
qa-watch --project=workflow uat-signature   # just the UAT signing story
```

`package.json` here exists only to set `"type": "commonjs"` — the app's root
package.json sets `"type": "module"`, which would otherwise make Node parse these
CommonJS specs as ESM.

## What it covers

| Spec | Story |
|---|---|
| `qat-signoff.workflow.spec.js` | A tester builds a QAT cycle, records verdicts, logs a blocker finding, raises a ticket from it, submits for sign-off. The manager is **refused** an approval while the blocker is open, refused again for waiving without a reason, then signs by hand and promotes to UAT. A user with no `qat.*` gets 403 on both the index and the cycle. |
| `uat-signature.workflow.spec.js` | A client signs a UAT acceptance on the **tokenised portal with no account**, that signature appears on the in-app roster, an internal approver then signs from the admin roster, and the certificate PDF is served inline. |

## Safety

- **A full SQL Server backup runs before every suite** (`global-setup.js`) and is a
  hard gate — no backup, no run.
- Every fixture is titled `E2E-…`; `php artisan qat:e2e-purge` removes only those
  and runs both before and after the suite. It refuses to run in production.
- If a run is interrupted, clean up with `php artisan qat:e2e-purge`.

## Gotchas that will bite again

- **Port 8010.** Port 8000 on this machine serves a different application.
- **Stale `public/hot`.** If `npm run dev` was killed without cleaning up, Laravel
  serves assets from a dead Vite server and Vue never mounts — every selector then
  "does not exist" while the HTML is a healthy 200. Delete `public/hot`.
- **Login goes through `context.request`, never the form.** The in-page Inertia POST
  hangs: PHP's built-in server is single-connection on Windows and Chromium holds
  several keep-alive sockets.
- **`browser.newContext()` inherits the project's `storageState`.** Pass
  `storageState: undefined` for a genuinely signed-out context, or a "no-login"
  test silently runs logged in.
- **`page.mouse` uses viewport coordinates, `boundingBox()` reports frame
  coordinates.** Scroll a signature pad into view first or it gets signed
  somewhere else — passes headed, fails headless.
- **Headless Chromium cannot render a PDF**; navigating to one raises "Download is
  starting". `support/pdf.js` asserts the response and only screenshots when it can.
- **Scope modal interaction to `dialog[open]`** — page-level locators resolve to
  elements behind the modal.
