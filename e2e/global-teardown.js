const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });

const { purge } = require('./support/purge');

/** Removes the marked fixtures whether the run passed or failed. */
module.exports = async () => {
    const cleared = purge();
    // eslint-disable-next-line no-console
    console.log('[e2e] post-run purge:', JSON.stringify(cleared));
};
