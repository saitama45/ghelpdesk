const { execFileSync } = require('node:child_process');
const path = require('node:path');

const APP = path.resolve(__dirname, '..', '..');

/**
 * Creates a marked UAT cycle that has finished testing and is waiting to be
 * signed. Used only to set the stage — the signing itself is always driven
 * through the real interface.
 */
function seedUatCycle({ approverId, title }) {
    const args = ['artisan', 'uat:e2e-seed', `--approver=${approverId}`];
    if (title) args.push(`--title=${title}`);

    const output = execFileSync('php', args, { cwd: APP, encoding: 'utf8' });
    const line = output.trim().split(/\r?\n/).pop();

    return JSON.parse(line);
}

module.exports = { seedUatCycle };
