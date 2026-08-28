const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });

const { defineQaConfig } = require('qa-kit/config');

/**
 * Read-only sibling of playwright.config.js, for `*.readonly.spec.js`.
 *
 * The main config's globalSetup backs the database up and then PURGES the `E2E-`
 * fixtures an interrupted run may have left behind. That purge is a physical
 * delete against `tashelpdeskdb`, the protected developer database, and it exists
 * for the suites that write fixtures.
 *
 * A spec that only signs in and reads a page needs neither: it creates nothing,
 * so there is nothing to purge and nothing to restore. Running it here keeps a
 * read-only verification from triggering a delete.
 *
 *   qa --config=playwright.readonly.config.js
 *
 * The `readonly` project's testMatch is narrowed deliberately: the shared kit
 * names its destructive project `workflow` and matches `*.workflow.spec.js`, so
 * without this the writing QAT/UAT suites would run under a config that has no
 * backup behind it.
 */
const config = defineQaConfig({
    root: __dirname,
    // Port 8010 deliberately: 8000 on this machine serves a DIFFERENT application.
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8010',
    roles: ['tester', 'manager', 'outsider'],
    workflow: true,
    timeout: 180_000,
});

config.projects = config.projects
    .filter((project) => project.name === 'setup' || project.name === 'workflow')
    .map((project) => (project.name === 'workflow'
        ? { ...project, name: 'readonly', testMatch: /.*\.readonly\.spec\.js/ }
        : project));

module.exports = config;
