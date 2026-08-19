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
