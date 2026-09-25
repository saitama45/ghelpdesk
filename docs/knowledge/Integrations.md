# External Integrations

| Integration | Package / API | Where |
|---|---|---|
| Inbound mail (IMAP) | `webklex/laravel-imap` | `app/Services/EmailTicketService.php`, `app/Console/Commands/FetchEmails.php`, `config/imap.php` |
| Outbound mail (SMTP) | Laravel Mail | `app/Mail/*` (18 mailables), `config/mail.php` |
| Google OAuth | `laravel/socialite` | `app/Http/Controllers/Auth/SocialAuthController.php`, `config/services.php` → `services.google` |
| Google Maps | REST key | `services.google.maps_api_key` (store/trip location UI) |
| linkportal (sister app) | Sanctum in + HTTP callback out | in: `app/Http/Controllers/Api/AccountingDocumentReviewController.php`; out: `app/Jobs/SendDecisionCallbackJob.php`; config `services.linkportal.*` |
| DAVID app (sister app) | Shared key in (`X-Integration-Key`, `integration.key:david` middleware) | `GET /api/integrations/david/ticket-tally` → `app/Services/DavidTicketTallyService.php`; config `services.integrations.david.key` (`DAVID_INTEGRATION_KEY`). Weekly per-module Incoming (created that week) / Closed (those now `closed`) for one entity (`companies.code` = DAVID `entities.code`); items opt in via `items.report_key = david.{order,commit,receiving,wastage,mec,sales_upload,admin}`; child tickets excluded |
| Mobile app (staff, Capacitor) | Capacitor 7 + Sanctum | `capacitor.config.ts`, `android/`, `ios/`, `routes/api.php` DTR endpoints, `@capacitor/geolocation` |
| Mobile app (members, Flutter) | Sanctum | separate repo `service_center_mobile` (pubspec `cbtl`, store name "CBTL"); consumes `routes/api.php` register / login / otp / loyalty / account endpoints |
| PDF export | `barryvdh/laravel-dompdf` | report/PDF controllers |
| Excel import/export | `phpoffice/phpspreadsheet` | `app/Services/UatWorkbook.php`, activity-template import, roles/users export |
| Barcodes / QR | `milon/barcode` (PHP), `jsbarcode` + `qrcode` (JS) | asset labels, queue tickets |
| Redis (optional) | `predis/predis` | cache/queue when configured; dev/prod default to the `database` driver |
| Slack (configured, unused) | `services.slack.*` | `config/services.php` |
| Azure App Service | nginx + PHP-FPM | `startup.sh`, `default.conf`, `web.config`, `.github/workflows/main.yaml` |

## Vendor portal accounts (shared `vendors` table)
linkportal authenticates its vendors against **this database's `vendors` table** (its `vendor` guard, custom `VendorUserProvider`). A row with a `password` is a portal login; the rest are back-office reference vendors. Consequences for /vendors:

- `status` drives portal access (`pending` → `active` | `rejected` | `suspended` | `deactivated`), and `is_active` is derived from it — the Edit form's "Active Vendor" checkbox is hidden for portal accounts.
- Decisions go through `VendorController@approval` (permission `vendors.approve`), which stamps `approved_by`/`approved_at`, appends to **`vendor_approvals`** (the decision log) and drops an in-app row into `portal_notifications`. No mail is sent from the back office.
- A **pending** vendor can still sign in — that is how they upload accreditation documents. The portal's `EnsureVendorIsActive` middleware is what keeps them out of transactions; `Vendor::BLOCKED_LOGIN_STATUSES` (rejected/suspended/deactivated) is what refuses a sign-in.
- Password reset: admins set one from /vendors (`vendors.reset_password`, no email), vendors self-serve at the portal's `/forgot-password` on the **`vendors` broker** with its own `vendor_password_reset_tokens` table.
- Portal vendors cannot be deleted or have their email changed here — both would break the login.

### Vendor types are a managed list
`vendors.vendor_type` no longer comes from a PHP constant. The selectable types live in **`reference_options` where `type = vendor_type`** and are added, renamed and removed inline from the /vendors modal via `ManageableAutocomplete` — the same component and the same three permissions (`reference_options.create|edit|delete`) that /activity-templates uses for project types. `Vendor::TYPES` is now only the seed; read `Vendor::types()`, which is also what the `in:` validation is built from. `ReferenceOptionController` refuses to delete a type still sitting on a vendor.

### Cashier accounts and the portal's Campaigns module
**`Cashier`** is a vendor type that is not a supplier: it is a store till running the portal's Campaigns (loyalty stamps) module.

- Picking `Cashier` in the /vendors modal reveals **Assigned Store** (`vendors.store_id`, nullable FK, Regular-class stores only). That store is the entire scope of their Campaigns module; the type and the store are both enforced server-side.
- A cashier **never self-registers** — the portal's public registration form is for suppliers — so the key icon on their /vendors row **issues** their first portal login as well as resetting a later one (`VendorController@resetPassword`, `vendors.reset_password`). Issuing activates the account immediately: an approver created it, so there is nothing left to review.
- The portal side is `linkportal`'s `/vendor/campaigns`, gated by `EnsureVendorIsCashier` (`vendor.cashier`) **on the route group**, not just by hiding the nav item — a supplier typing the URL is bounced and the JSON endpoints 403. `App\Support\CashierContext` is the single definition of "which store is this session working in".
- Campaigns mirrors /stamps tab for tab (Cards · Redemptions · Customers · Programs · Vouchers) with three deliberate reductions: **Customers, Programs and Vouchers are read-only** (Vouchers keeps only Verify / Use Voucher — no batch creation, activation, printing, cancelling or voiding), and **Cards has no "New Card"** — a card is created by scanning a member, which is the counter's real workflow.
- **Attribution.** A portal cashier has no `users` row, so every `*_by` FK on the loyalty tables would have failed. `stamp_cards`, `stamp_entries`, `stamp_redemptions`, `voucher_redemptions` and `voucher_verification_attempts` each gained a nullable **`cashier_vendor_id`**, and `voucher_redemptions.redeemed_by` / `voucher_verification_attempts.verified_by` were relaxed to nullable. The hub reads both: `creator?.name || cashier_vendor?.name`.
- **Shared `APP_KEY`.** The portal's `LoyaltyQrService` / `LoyaltyRedeemQrService` are ports of the hub's, and the member QR signature is an HMAC keyed on `app.key`. Deploy the portal with a different key and every genuine member QR is rejected.

### Vendor company profile (maker-checker)
The portal's /vendor/profile keeps the vendor's legal, tax, address and payment details in **`portal_vendor_profiles`**. A vendor's edits are NOT applied — the portal stages them in `pending_changes` with `approval_status = pending` and notifies `vendors.approve` holders.

- `App\Models\VendorProfile` (read-only apart from the review) mirrors those columns; `VendorProfile::FIELDS` is the portal form's field order and labels, and `pendingDiff()` reduces a submission to only the fields that actually differ.
- `GET /vendors/{vendor}/profile` (`vendors.view`) and `PUT /vendors/{vendor}/profile/review` (`vendors.approve`, `approved`|`rejected`, remarks required on a refusal). Approving copies the staged values onto the live columns — that is what publishes them; rejecting discards them and keeps the live profile. Only keys in `FIELDS` are ever written from a staged payload. No pending submission → 409.
- Rendered by `VendorProfilePanel.vue` above the documents in the edit modal, and `compact` in the account approval modal. A profile decision never touches `vendors.status`/`is_active`.
- **Cheque details** (`cheque_payee_name`, `cheque_delivery_method`, `cheque_is_crossed`, `cheque_remarks`) are part of the same profile and the same maker-checker review, shown as their own section (`VendorProfile::groupOf()`). They are FORMATTED for reading — `displayValue()` turns `bank_deposit` into "Bank Deposit" and the checkbox into Yes/No — and compared through `comparable()`, so a checkbox arriving as `false`/`0`/`""` is not reported as a change. Cheque release is a fixed portal enum: `pickup`, `courier`, `bank_deposit`.

### Vendor contacts and bank accounts
The same fetch (`GET /vendors/{vendor}/profile`) also returns the other two cards on the portal's profile page, and they are treated differently on purpose:

- **Contacts** (`portal_vendor_contacts`, `App\Models\VendorContact`): all of them, primary first. Directory information — read-only, no approval, no state.
- **Bank accounts** (`portal_vendor_bank_accounts`, `App\Models\VendorBankAccount`): verified before use, because payments are released against them. `PUT /vendors/{vendor}/bank-accounts/{bankAccount}/review` (`approved`|`rejected`, remarks required on a refusal) under the **`vendors.verify_bank`** permission — deliberately NOT `vendors.approve`, so a finance role can verify banking without being able to activate accounts. Pending only (409 otherwise): changing bank details in the portal creates a new row rather than re-pointing a verified one.
- **The account number is masked** to its last four digits for everyone without `vendors.verify_bank`; the full number is sent only to those who must check it against the bank certification.

### Vendor documents (accreditation files)
The portal's /vendor/documents uploads land in **`portal_vendor_documents`** (shared DB) with the file on the **portal's own public disk** (`portal/vendors/{id}/documents/...`). /vendors reads them, never writes them.

- `App\Models\VendorDocument` (read-only, no `$fillable`) + `VendorDocumentController`: `GET /vendors/{vendor}/documents` (JSON, fetched when the edit modal opens) and `GET /vendors/{vendor}/documents/{document}/file` (streamed inline, `?download=1` for an attachment). Both gated on `vendors.view`.
- The file is fetched by `App\Services\PortalDocumentStorage`: `services.linkportal.documents_root` (a shared filesystem path — set `LINKPORTAL_DOCUMENTS_ROOT` locally) first, else a server-side GET of `{LINKPORTAL_URL}/storage/{file_path}`. Serving it through this app keeps the URL permission-gated and same-origin, so Download actually downloads.
- Surfaced per document: type label (`portal_reference_options` where `type = document_type`), title, file name/size, upload date, issued/expiry dates (with expired / expiring-soon flags), version, review status, reviewer and remarks.
- **Document accreditation** — `PUT /vendors/{vendor}/documents/{document}/review` (`approved` | `rejected`, remarks required on a refusal), gated on **`vendor-documents.approve`**: the portal's own permission, reused because the permissions table is shared and it is the same decision. Mirrors `Admin\VendorController@reviewDocument` — pending only (a second decision returns 409; re-accrediting means a new upload), stamps `reviewed_by`/`reviewed_at`/`review_remarks`, and writes a `document_approved`/`document_rejected` row into `portal_notifications`.
- **Two different approvals, deliberately.** `vendors.approve` decides on the ACCOUNT (portal access, `vendors.status`); `vendor-documents.approve` decides on a FILE. Reviewing a document never touches the vendor's status or `is_active`, and approving an account never accredits a document. The account buttons are labelled "Approve/Reject **Account**" because the documents listed above them carry their own Approve/Reject.
- **Where the panel appears.** `VendorDocumentsPanel.vue` renders in two places off one store (`vendorDocuments`, keyed by `vendorId`): full in the edit modal (search, type tabs, footer) and `compact` inside the account approval modal, where it is the evidence for that decision — with a summary of awaiting/approved/rejected/expired counts above it. A document decision is an axios call that patches the row in place: **the modal never closes, the result is a toast.**

## Mobile member app — store-review requirements

The Flutter member app (`service_center_mobile`, App Store name **CBTL**) is reviewed by
Apple and Google, and two of their requirements are served by this backend.

### Two ways to ask for deletion, and they are not the same request

Both file the SAME `Account Deletion Request` ticket, through
**`app/Services/AccountDeletionRequestService.php`** — the single definition of that ticket,
reused rather than duplicated when a member asks twice.

| | Public `/account-deletion` page | `DELETE /api/account` (in-app) |
|---|---|---|
| Proves ownership with | a one-time code emailed to the address (the requester is anonymous) | the caller's Sanctum token **plus** a re-entered password |
| Account afterwards | still **open** — the desk archives it | already **archived**, every token revoked |
| Desk sees it in | Settings → Account Archive → **Deletion Requests** (Stage 1) | nothing to action; the ticket is the record, the pair is under **Loyalty Customers** awaiting purge |
| Member is emailed | `AccountDeletionRequestedMail` ("closed within 30 days, reply to cancel") | `AccountClosedMail` ("already closed, reply if this was not you") |

The in-app path closes immediately on purpose: a password re-entered on an authenticated
session is stronger proof than the emailed code, so there is nothing left for a human to
verify, and App Store Review Guideline 5.1.1(v) expects the deletion to take effect rather
than queue. `Api\AccountController` raises the ticket **before** archiving — the body quotes
the member's phone and customer id, and archiving soft-deletes that row out from under the
relation — and refuses any account holding a role, because a staff login must not archive
itself out of tickets, DTR and the task board with one tap.

Permanent removal is still Stage 2 for both: Settings → Account Archive, after the published
retention window, which is what `/account-deletion` promises. Keep the endpoint, that page and
`AccountClosedMail` in step.

### Archiving frees the email address

`users.email` carries a **UNIQUE index** and an archive is only a soft delete, so an archived
row would go on owning the address for ever — a member who deleted their account could never
sign up again with it (`Api\RegisterController` answered *"The email has already been taken"*),
which is exactly what an App Store reviewer does straight after testing deletion.

`AccountArchiveService::releaseEmail()` therefore parks the real address in the new
**`users.archived_email`** column and writes `deleted-{id}@archived.invalid` into `email`
(`.invalid` is reserved by RFC 2606, so a tombstone can never be mailed). `reclaimEmail()`
puts it back on restore — **unless somebody has taken the address in the meantime**, in which
case the tombstone stays, `restoreUser()`/`restoreCustomer()` return `email_reclaimed => false`
and it is logged, rather than the restore breaking the unique index. That gap is real: delete,
re-register, then restore the old account.

Anything displaying an archived login's address reads `archived_email ?? email` — see the
Users tab and the linked-login line on the Loyalty Customers tab in `AccountArchiveController`,
which also searches both columns.

### Letting a store reviewer sign in

**`APP_REVIEW_EMAIL` + `APP_REVIEW_OTP`** (`services.app_review.*`) name one demo account that
receives the fixed code instead of a mailed one at `POST /api/otp/send`. A reviewer signs in on
a device with no access to the demo mailbox, so without this the review ends at the
verification screen. Everything else about the step is unchanged — the code is still stored
hashed, still scoped to `OtpCode::PURPOSE_LOGIN`, still expires in 5 minutes, still counts
attempts — and leaving either value unset turns the allowlist off.

Covered by `tests/Feature/MobileAccountDeletionTest.php`. The login-free public pages the
stores require are `/privacy-policy` and `/account-deletion` (`routes/web.php`).

## Mail configuration — important
SMTP and IMAP credentials are read from the **`settings` table**, not `.env`. `AppServiceProvider::boot()` overwrites `mail.*` and `imap.accounts.default.*` from `Setting::where('group','mail')`, cached as `app_mail_settings` for 1 hour (skipped when `APP_ENV=testing`).

Consequences:
- Changing `MAIL_MAILER` in `.env` does **nothing**. The app will send **real Gmail SMTP mail synchronously** during ticket actions.
- After editing mail settings in the UI, `Cache::forget('app_mail_settings')`.
- `config:cache` is deliberately **not** run on deploy (`startup.sh` step 5) because these DB-driven settings must stay dynamic.
- Never run merge/notify flows against production data casually — a merge test once emailed four real requesters.

## Department mail routing
One mailbox, one SMTP credential. Per-department inbound addresses are plus-addresses/aliases stored **whole** in settings and resolved only through `app/Services/DepartmentMailRouter.php` (`allAddresses()`, `resolve()`, `replyToFor()`, `fromNameFor()`). Anything that needs to know "is this address ours" must go through that class, never `Setting::get('imap_username')` directly.

## Scheduled work (`routes/console.php`, run by `schedule:work` from `startup.sh`)
| Command | Cadence |
|---|---|
| `tickets:fetch-emails` | every 30s, `withoutOverlapping(5)` (5-min mutex so a killed run cannot block mail for a day) |
| `tickets:process-scheduled` | every minute |
| `presence:update-stale` | every minute |
| `tickets:auto-close` | hourly |
| `notifications:due-soon` | daily 07:30 |
| `payments:send-due-reminders` | daily 08:00 |

Other commands: `tickets:diagnose-email`, `sla:recalculate`, `inventory:backfill-ledger`, `forms:repair-tickets`, `integration:issue-token`.

## Deployment
GitHub Actions (`.github/workflows/main.yaml`) → Azure App Service. `startup.sh` configures nginx, creates storage dirs + symlink, raises PHP upload limits to 1 GB, clears caches, **runs `php artisan migrate --force`**, then launches `schedule:work`. Migrations therefore ship with the deploy — never ask the user to run them by hand.
