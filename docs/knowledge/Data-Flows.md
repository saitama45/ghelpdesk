# Major Data Flows

## 1. Ticket lifecycle (the core flow)
```
intake → TicketObserver (key/company/SLA) → assignment → work → resolve → close/auto-close → survey/KB
```
**Intake channels**
| Channel | Entry point |
|---|---|
| Web form | `TicketController@store` (`resources/js/Pages/Tickets/Create.vue`) |
| Inbound email | `tickets:fetch-emails` → `app/Services/EmailTicketService.php` |
| Dynamic form | `DynamicFormController` → `app/Services/DynamicForms/DefaultFormService.php` |
| POS request approval | `app/Services/PosRequestService.php` (idempotent, retry on `ticket_key` unique) |
| Walk-in kiosk / queue | `PublicQueueController@kioskStore` → `app/Services/QueueService.php` |
| Scheduled/recurring | `tickets:process-scheduled` |

**On create (`app/Observers/TicketObserver.php`)**
1. Backfills `company_id` (store owner → creator's active entity → `TGI`) — a null `company_id` makes a ticket invisible to every user.
2. Generates `ticket_key` with the prefix of the **store's owning company**; old keys are preserved in `ticket_key_aliases` when a ticket is renumbered.
3. Issues `queue_track_token` for the public track page.
4. Creates `ticket_sla_metrics` via `app/Services/SlaService.php` (business-hours arithmetic, holiday-aware, per-department settings overrides).

**Ticket URLs are keyed, not UUID'd** (`Ticket::getRouteKeyName/resolveRouteBinding` + `TicketController@edit`)
- The canonical URL is `/tickets/TGI-4096/edit`. `getRouteKeyName()` is `ticket_key`, so `route('tickets.edit', $ticket)`
  and Ziggy both emit the key; `getRouteKey()` falls back to the UUID for a ticket whose key has not been generated.
- `resolveRouteBinding()` accepts **all three** forms — current key, UUID (every link already mailed out carries one),
  and a key retired by a renumber via `ticket_key_aliases`. The UUID branch is guarded by `Str::isUuid` because handing a
  non-UUID to a `uniqueidentifier` column is a SQL Server conversion error, not a miss.
- `canonicalKeyRedirect()` bounces any non-canonical form to the key, carrying the query string and **reflashing the
  session** so a validation error or success toast is not eaten by the extra hop.
- The Vue pages still POST `ticket.id` to the write routes — that keeps working, and is why the binding must stay
  permissive. Navigation links go through `resources/js/Composables/useTicketLink.js` (`ticketUrl()`), which prefers
  `ticket_key` and degrades to the id.
- **Classifying a ticket can renumber it** (the key follows the store's owning company), so a key is not stable —
  always link through `ticketUrl()`/the model, never by caching a key string.

**Responding requires a classified ticket** (`TicketController@storeComment` + `Tickets/Edit.vue`)
- A public response cannot be sent until the ticket has **Department, Store, Company, Item and Assignee**.
  The composer shows the missing fields and disables both send buttons; the controller repeats the check
  (`assertTicketClassifiedForResponse`) so a direct POST is refused with a `classification` validation error.
- Three exemptions: **internal notes** (the desk's own scratchpad while it is still classifying),
  **customer-view users** (`TicketAccess::isCustomerOf` — they cannot set any of these fields, so requiring
  them would silence the requester), and **partner-escalation children** (`parent_id` + `vendor_id`, where
  the partner holds the work instead of an assignee).
- A user with `tickets.edit` but not `tickets.assign` has no Assignee control, so the banner switches to
  "ask the desk that owns it" rather than telling them to set a field they cannot see.

**Serving vs requesting department**
- `tickets.department_id` = the REQUESTER's department.
- `tickets.serving_department_id` = the desk that owns the work (from the plus-address it arrived on, the form's owning department, or an override); falls back to the assignee's department. See `Ticket::scopeOwnedByDepartment()`.

## 2. Inbound email → ticket / comment
`app/Console/Commands/FetchEmails.php` (scheduled every 30s, `withoutOverlapping(5)`) → `EmailTicketService::fetchAndProcess()`:
1. Throttled to one run per 20s (`Setting: last_email_sync_at`); IMAP config comes from the `settings` table, not `.env`.
   The throttle is **frequency control, not mutual exclusion**: `last_email_sync_at` is written only *after* the fetch
   finishes, so two callers seconds apart both read the stale timestamp and both open IMAP. The scheduler covers itself
   with `withoutOverlapping(5)`; the HTTP route (`POST tickets/sync`) holds `Cache::lock('tickets:sync', 300)` and
   returns 409 rather than starting a second fetch.
   **Three entry points, and only one of them may run IMAP inside a web request:**
   - `tickets:fetch-emails` on the 30s schedule — the primary path.
   - `POST tickets/sync` (Settings → Sync Emails) — synchronous, because a human is waiting on
     the result message. Lock-guarded; returns 409 if a fetch is already running.
   - `POST tickets/sync-background` (`Dashboard.vue` on mount) — dispatches `App\Jobs\FetchEmailsJob`
     and returns **202 in ~0.1s**. Never run IMAP inline from a page load: doing so held the only
     PHP worker on the dev server, so the first load after login took 47s, and merely *deferring*
     the call just moved the stall onto the user's next click (a `/tickets` navigation during the
     fetch still cost 10.04s). Queued, the same navigation is 1.02s.

   `FetchEmailsJob` is `ShouldBeUnique` (`uniqueFor = 60`) and takes the same
   `Cache::lock('tickets:sync')`, so however many people open the dashboard at once, they collapse
   into one fetch rather than stacking IMAP sessions. **This requires a queue worker** —
   `composer run dev` runs `queue:listen`, and `startup.sh` now starts `queue:work` in production.
2. Pass 1 = unseen messages; Pass 2 = already-read messages with no recorded decision (so a human opening the shared mailbox cannot lose mail).
3. `app/Services/DepartmentMailRouter.php` decides "is this for us, and which department?" — one mailbox, per-department plus/alias addresses; a departmental hit beats the base support address.
4. Thread matching: `source_message_id` (case-preserved) / references / `ticket_key` (incl. aliases) **plus a subject-similarity guard** — a reply with a new subject becomes a NEW ticket, not a comment on the old one.
5. Every decision is written to `email_intake_logs`; diagnose with `php artisan tickets:diagnose-email`.
6. Auto-assignment via `app/Services/AutoAssigneeService.php` (sender → assignee/company/store, round-robin); auto-CC unions To/CC minus assignee and noreply into `ticket_ccs`.

## 3. Notifications (bell + email)
- In-app: `app/Services/NotificationService.php` → `App\Notifications\ActivityNotification` → `notifications` table; the bell polls every 30s (`NotificationController`).
- Recipients are resolved per domain (ticket: assignee + reporter + CC; task card: assignees/watchers/board members; project task: assigned/support + team) with the actor removed.
- Email: 18 mailables in `app/Mail/`. SMTP credentials come from the `settings` table (cached as `app_mail_settings`) — **`MAIL_MAILER` in `.env` does not control the driver**, and ticket actions send synchronously.

## 4. Projects ↔ Task boards
- Two-way sync in `app/Services/ProjectTaskBoardSyncService.php`: Checklist ↔ Milestone, Checklist item ↔ Activity, Subtask ↔ Subtask. A card is never an activity.
- Scheduling: `ProjectScheduler` + `ScheduleChain` + `ScheduleCalculator` re-derive every Gantt date from `day1_date`. Rule: **Finish = Start + LeadTime − 1 (inclusive)**; `Parallel = Yes` starts the same day as its dependency; a milestone's lead time is the SUM of its sub-tasks; `start_anchor_date` pins a row.
- Working days skip weekends **and** `holidays` rows via `app/Services/HolidayCalendar.php` (cached, flushed on write).

## 5. Approvals (POS / SAP / payments / dynamic forms / accounting)
Shared shape: request table + `*_approvals` rows per step → approvers notified via `NotificationService::notifyApproval()` → terminal approval triggers the domain effect (POS → ticket, payment → posting, accounting → `SendDecisionCallbackJob` back to linkportal).

## 6. Dashboard & reports
- `DashboardController` renders 5 lazy tabs: only Ticket Flow Board loads on first paint; the rest arrive via `Inertia::optional` + `router.reload({ only: [...] })` and are cached until a filter changes. Testing partial reloads requires `X-Inertia` headers + version.
- Report services: `StoreReportService` (store health, entity heatmap, office split), `BrandHealthService`, `PartnerPerformanceService` (vendor-escalation child tickets), `AssetOperationalHealthService` (RED/GREEN derived live from linked tickets, never stored).
- Open vs closed tally is dashboard-wide: open = non-terminal statuses, closed = `resolved` + `closed`, so Total = Open + Closed.

## 7. Public (tokenised, no login)
`routes/web.php` bottom block → `PublicTicketController` (close/survey/attachments), `PublicQueueController` (board/track/kiosk), `PublicUatController` (portal/verdict/finding/signoff), `PublicPosRequestController`, `PublicSapRequestController`. All are token-addressed and rate-limited with `throttle:*`.

## 8. Mobile / API
`routes/api.php`: `POST /api/login` → Sanctum token; `auth:sanctum` group exposes DTR (`/dtr/status`, `/dtr/offline-bootstrap`, `/dtr/log`) and `/attendance/logs` for the Capacitor app, plus the linkportal document-review intake.

## 9. File storage
Uploads land on the `public` disk; served through the symlink-free route `GET /serve-storage/{path}` (`routes/web.php:16`). Client-side compression is mandatory for image uploads (`resources/js/Composables/useImageCompressor.js`) — an oversized POST dies at PHP's limit before validation runs.

---

## QAT → UAT: the internal quality gate

Two independent modules, deliberately not one. `/qat` is the **internal** pass run by
staff; `/uat` is the **client-facing** acceptance. Either can exist alone, and the
link between them carries **no foreign key in either direction** — a real constraint
would let one module block the other's deletes.

### The flow

1. A tester builds a QAT cycle (sections → cases → department columns), records a
   verdict per case × participant, and logs findings with mandatory screenshots.
2. A finding becomes a helpdesk ticket **manually, one at a time**
   (`QatFindingController::convertToTicket`). Never automatic: a long cycle produces
   far more failed verdicts than real work, and flooding the queue is what makes
   teams stop trusting the register.
3. The tester **submits for sign-off**. The approver is resolved from the org chart
   by `ManagerApproverResolver` — direct managers (`manager_user` pivot) → climb
   `department_nodes` for an `is_manager` user → Admin/Solutions Admin fallback,
   each stage filtered by `->can('qat.approve')` and never the requester themselves.
   The resolved ids are **snapshotted onto `qat_cycles.approver_user_ids`** so a
   later org change cannot orphan a pending decision; membership of that snapshot,
   not a live re-resolution, is who may decide.
4. The manager decides. **An unresolved blocker/major finding refuses an approval.**
   The only way past is to name the findings and write a reason (≥10 chars), which
   is stamped on each finding (`waived_at/waived_by_user_id/waiver_reason`) and on
   the sign-off row. Rejecting is never gated.
5. Only a signed-off cycle can be **promoted to UAT**, which copies sections and
   cases and nothing else — no verdicts, findings, evidence or participants. The
   resulting UAT cycle carries `uat_cycles.qat_cycle_id` and shows an upstream-QA
   banner.

### Two rules that are easy to get wrong

- **Submission is gated on cases being ANSWERED, not on their having PASSED.** An
  earlier build blocked submission on any failing case, which made the waiver
  unreachable: a test fails → you log a finding → and the manager who is supposed to
  judge it can never receive the cycle. `readiness()` therefore separates
  `unanswered_cases` (blocks) from `failing_cases` (travels to the manager).
- **The approving manager is usually in a different department to the cycle.**
  `QatCycle::scopeVisibleTo()` / `isVisibleTo()` therefore admit the snapshotted
  approvers as well as the owning department. The rule lives in **one** place in two
  forms precisely because UAT states the same rule twice and they can drift — the
  symptom being a row that lists and then 403s when opened.

### Digital signatures (both modules)

Sign-offs carry a hand-drawn signature captured by `resources/js/Components/SignaturePad.vue`
(pointer events, so mouse/finger/stylus are one code path) and stored by
`App\Support\SignatureImage` as a **PNG file** on the public disk — never as a base64
data URL in the row, which would drag the image into every query touching the ledger.

For UAT the **portal is the primary signing path**: clients have no account and sign
at `/public/uat/{token}`, and that signature surfaces on the in-app acceptance
roster. The admin-side roster sign-off remains and is still required for internal
approvers. `signoff_requires_all` keeps the final sign-off locked until every
nominated approver has accepted, whichever route they used.

Both modules print the same certificate (`resources/views/pdf/testing-signoff.blade.php`)
via dompdf, **streamed inline** so the button opens a tab rather than downloading.
The signature is embedded as a base64 data URI because dompdf resolves `<img src>`
against its own chroot and cannot fetch an app URL.
