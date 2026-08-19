# External Integrations

| Integration | Package / API | Where |
|---|---|---|
| Inbound mail (IMAP) | `webklex/laravel-imap` | `app/Services/EmailTicketService.php`, `app/Console/Commands/FetchEmails.php`, `config/imap.php` |
| Outbound mail (SMTP) | Laravel Mail | `app/Mail/*` (18 mailables), `config/mail.php` |
| Google OAuth | `laravel/socialite` | `app/Http/Controllers/Auth/SocialAuthController.php`, `config/services.php` → `services.google` |
| Google Maps | REST key | `services.google.maps_api_key` (store/trip location UI) |
| linkportal (sister app) | Sanctum in + HTTP callback out | in: `app/Http/Controllers/Api/AccountingDocumentReviewController.php`; out: `app/Jobs/SendDecisionCallbackJob.php`; config `services.linkportal.*` |
| Mobile app | Capacitor 7 + Sanctum | `capacitor.config.ts`, `android/`, `ios/`, `routes/api.php` DTR endpoints, `@capacitor/geolocation` |
| PDF export | `barryvdh/laravel-dompdf` | report/PDF controllers |
| Excel import/export | `phpoffice/phpspreadsheet` | `app/Services/UatWorkbook.php`, activity-template import, roles/users export |
| Barcodes / QR | `milon/barcode` (PHP), `jsbarcode` + `qrcode` (JS) | asset labels, queue tickets |
| Redis (optional) | `predis/predis` | cache/queue when configured; dev/prod default to the `database` driver |
| Slack (configured, unused) | `services.slack.*` | `config/services.php` |
| Azure App Service | nginx + PHP-FPM | `startup.sh`, `default.conf`, `web.config`, `.github/workflows/main.yaml` |

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
