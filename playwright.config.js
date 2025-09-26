const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: './backup-jlg/tests/e2e',
  timeout: 60000,
  fullyParallel: false,
  reporter: [
    ['list'],
    ['html', { outputFolder: 'playwright-report', open: 'never' }],
  ],
  use: {
    baseURL: 'https://example.test',
    viewport: { width: 1280, height: 720 },
    ignoreHTTPSErrors: true,
    trace: 'on-first-retry',
  },
});
