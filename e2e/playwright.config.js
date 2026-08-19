const path = require('node:path');
require('dotenv').config({ path: path.resolve(__dirname, '.env.e2e') });

const { defineQaConfig } = require('qa-kit/config');

module.exports = defineQaConfig({
    root: __dirname,
    // Port 8010 deliberately: 8000 on this machine serves a DIFFERENT application.
    baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8010',
    roles: ['tester', 'manager', 'outsider'],
    workflow: true,
    // The QAT walk-through drives three logins, an upload, a PDF and a promotion
    // in one narrated story, so it needs considerably longer than a smoke test.
    timeout: 420_000,
    globalSetup: require.resolve('./global-setup.js'),
    globalTeardown: require.resolve('./global-teardown.js'),
});
