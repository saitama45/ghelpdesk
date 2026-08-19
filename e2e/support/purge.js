const { execFileSync } = require('node:child_process');
const path = require('node:path');

const APP = path.resolve(__dirname, '..', '..');

/**
 * Removes only the rows this suite created.
 *
 * Everything the run makes carries the `E2E-` marker in its title, and the
 * artisan command refuses to touch anything without it — see
 * App\Console\Commands\QatE2ePurge. Nothing here deletes by date, by "most
 * recent", or by anything else that could catch a real record.
 */
function purge() {
    const output = execFileSync('php', ['artisan', 'qat:e2e-purge', '--json'], {
        cwd: APP,
        encoding: 'utf8',
    });

    const line = output.trim().split(/\r?\n/).pop();

    try {
        return JSON.parse(line);
    } catch {
        return { raw: output };
    }
}

module.exports = { purge };
