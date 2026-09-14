# Production deployment setup

The GitHub Actions workflow deploys the same build to Azure and to both MonsterASP sites. Each MonsterASP matrix job now:

1. Recreates that server's `.env` from an encrypted GitHub Actions secret.
2. Runs `php artisan migrate --force --no-interaction` against that server's database.
3. Deploys the application, including the correct `.env`, through FTP.

Migrations run before FTP deployment. If a migration fails, that MonsterASP job stops and does not publish code that may require an unapplied schema change. The two MonsterASP jobs are independent, so one failure does not cancel the other.

## Required GitHub secrets

Keep the existing FTP and Azure secrets:

- `AZURE_WEBAPP_PUBLISH_PROFILE`
- `FTP_SERVER`
- `FTP_USERNAME`
- `FTP_PASSWORD`
- `FTP_SERVER_GALGATIX`
- `FTP_USERNAME_GALGATIX`
- `FTP_PASSWORD_GALGATIX`

Add these two repository secrets:

- `MONSTERASP_ENV_FILE` — complete production `.env` for the primary MonsterASP site.
- `GALGATIX_ENV_FILE` — complete production `.env` for the GALGATIX MonsterASP site.

In GitHub, open **Settings > Secrets and variables > Actions > New repository secret**. Paste the complete contents of the corresponding server's working `.env` as the secret value. Multiline secret values are supported.

Each file should have that site's own values, especially:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://the-correct-site.example

DB_CONNECTION=sqlsrv
DB_HOST=the-correct-database-host
DB_PORT=1433
DB_DATABASE=the-correct-database
DB_USERNAME=the-correct-database-user
DB_PASSWORD=the-correct-database-password
```

Also retain the site's real `APP_KEY`, mail, OAuth, API, queue, cache, and other production settings. Do not reuse `.env.example` and do not commit either production `.env` file.

## Database connectivity requirement

The SQL Server endpoint must accept connections from GitHub-hosted runners. The workflow installs `pdo_sqlsrv`/`sqlsrv` and connects directly from the runner. If the database firewall uses an allowlist, permit GitHub Actions runner traffic or replace the hosted runner with a self-hosted runner that can reach both databases.

## First run

Use **Actions > Deploy Laravel to Azure and MonsterASP > Run workflow** after adding both environment-file secrets. Confirm that these jobs pass:

- `Deploy to MonsterASP primary`
- `Deploy to MonsterASP GALGATIX`

The migration command is idempotent: Laravel records completed migrations in the `migrations` table, so later deployments run only new migrations.

## Rotating configuration

When a database password, application URL, OAuth setting, or another production value changes, update only the corresponding `*_ENV_FILE` GitHub secret. The next deployment uploads the updated `.env`; no manual server-side edit is required.
