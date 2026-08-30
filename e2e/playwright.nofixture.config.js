const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });

const { defineQaConfig } = require('qa-kit/config');

/**
 * For specs that WRITE, but create no fixtures to clean up.
 *
 * The main config's globalSetup backs the database up and then PURGES leftover
 * `E2E-` fixtures — a physical DELETE against `tashelpdeskdb`, the protected
 * developer database. That exists for suites that manufacture their own rows and
 * must start from a known state.
 *
 * A spec that acts on data already in the database creates nothing to purge, so
 * running it under the main config would perform a destructive delete for no
 * reason. This config is the same harness minus that globalSetup.
 *
 * The trade-off is deliberate: such a spec must be idempotent, because nothing
 * resets the database between runs. `apply-activity-template` qualifies — the
 * endpoint skips activities that already exist and reports "already been added".
 *
 *   qa --config=playwright.nofixture.config.js
 */
const config = defineQaConfig({
    root: __dirname,
    // Port 8010 deliberately: 8000 on this machine may serve a DIFFERENT application.
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8010',
    roles: ['tester', 'manager', 'outsider'],
    workflow: true,
    timeout: 240_000,
});

config.projects = config.projects
    .filter((project) => project.name === 'setup' || project.name === 'workflow')
    .map((project) => (project.name === 'workflow'
        ? { ...project, name: 'nofixture', testMatch: /.*\.nofixture\.spec\.js/ }
        : project));

module.exports = config;
