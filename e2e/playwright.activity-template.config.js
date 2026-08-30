const { defineConfig, devices } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests',
    testMatch: /activity-template-import\.workflow\.spec\.js/,
    fullyParallel: false,
    workers: 1,
    timeout: 420_000,
    expect: { timeout: 30_000 },
    reporter: [['html', { open: 'never' }], ['list']],
    use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.E2E_BASE_URL || 'http://127.0.0.1:8012',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
        launchOptions: { slowMo: Number(process.env.SLOWMO) || 350 },
    },
});
