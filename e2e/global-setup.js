const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });

const { backupDb } = require('./support/backup');
const { purge } = require('./support/purge');

/**
 * Runs before the suite.
 *
 * The backup is a hard gate — this suite writes to the real developer database,
 * so if it cannot be backed up the run must not start. The purge afterwards
 * clears fixtures left by an interrupted previous run, because assertions on
 * derived totals pass once and then fail forever against stale leftovers.
 */
module.exports = async () => {
    backupDb();

    const cleared = purge();
    // eslint-disable-next-line no-console
    console.log('[e2e] pre-run purge:', JSON.stringify(cleared));
};
