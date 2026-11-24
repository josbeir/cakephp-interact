import { defineConfig, devices } from '@playwright/test';

const ci = Boolean(process.env.CI);

export default defineConfig({
  testDir: './tests/browser',
  globalSetup: './tests/browser/setup.ts',
  fullyParallel: true,
  // Container lifecycle changes can interrupt another worker's Chromium network.
  workers: ci ? 1 : 3,
  maxFailures: ci ? 1 : 0,
  retries: 0,
  reporter: 'list',
  use: { trace: 'retain-on-failure' },
  projects: [
    { name: 'chromium', default: ci, use: { ...devices['Desktop Chrome'] } },
    { name: 'firefox', use: { ...devices['Desktop Firefox'] } },
    { name: 'webkit', default: ci, use: { ...devices['Desktop Safari'] } },
  ],
});
