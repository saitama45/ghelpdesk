const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

/**
 * Full SQL Server backup, taken before anything destructive runs.
 *
 * This is a hard gate on purpose: the suite writes to the developer database
 * `tashelpdeskdb`, and a backup that "usually works" is worth nothing at the
 * moment it is needed. If the backup cannot be taken, the run does not start.
 *
 * WITH INIT overwrites the single rolling file rather than appending, so a
 * long-lived machine does not quietly fill its disk with .bak sets.
 */
function backupDb() {
    const host = process.env.E2E_DB_HOST || '127.0.0.1';
    const db = process.env.E2E_DB_NAME;
    const user = process.env.E2E_DB_USER;
    const password = process.env.E2E_DB_PASSWORD;
    const dir = process.env.E2E_BACKUP_DIR || path.resolve(__dirname, '..', 'backups');

    if (!db || !user || !password) {
        throw new Error('Refusing to run: E2E_DB_NAME / E2E_DB_USER / E2E_DB_PASSWORD are not set in .env.e2e');
    }

    fs.mkdirSync(dir, { recursive: true });
    const target = path.join(dir, `${db}-e2e.bak`);

    // eslint-disable-next-line no-console
    console.log(`[e2e] backing up ${db} -> ${target}`);

    execFileSync('sqlcmd', [
        '-S', host, '-U', user, '-P', password, '-C', '-b',
        // No COMPRESSION: it is unsupported on SQL Server Express, and this is a
        // hard gate — a backup option that errors would stop the suite entirely.
        '-Q', `BACKUP DATABASE [${db}] TO DISK = N'${target}' WITH INIT`,
    ], { stdio: 'inherit' });

    return target;
}

module.exports = { backupDb };
