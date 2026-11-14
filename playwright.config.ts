import { defineConfig } from '@playwright/test';
import { baseURL, repositoryRoot, testEnvironment } from './tests/e2e/environment.js';

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './storage/framework/testing/phase1-playwright-results',
    fullyParallel: false,
    workers: 1,
    forbidOnly: Boolean((globalThis as { process?: { env?: Record<string, string | undefined> } }).process?.env?.CI),
    retries: 0,
    reporter: 'list',
    globalSetup: './tests/e2e/global-setup.ts',
    use: {
        baseURL,
        headless: true,
        screenshot: 'only-on-failure',
        trace: 'retain-on-failure',
    },
    webServer: {
        command: 'php -S 127.0.0.1:8123 -t public tests/e2e/router.php',
        cwd: repositoryRoot,
        url: `${baseURL}/__playwright/ready`,
        timeout: 30_000,
        reuseExistingServer: false,
        env: testEnvironment,
        stdout: 'pipe',
        stderr: 'pipe',
    },
});
