import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const testingRoot = path.join(projectRoot, 'storage', 'framework', 'testing');
const envPath = path.join(tmpdir(), 'wifi-billing-playwright-test');

export const baseURL = 'http://127.0.0.1:8123';
export const cookieName = 'phase1_playwright_session';
export const fixturePath = path.join(testingRoot, 'phase1-playwright-fixture-local.json');
export const databasePath = path.join(testingRoot, 'phase1-playwright-local.sqlite');
export const sessionPath = path.join(testingRoot, 'phase1-playwright-sessions-local');
export const environmentPath = envPath;
export const repositoryRoot = projectRoot;

export const testEnvironment: NodeJS.ProcessEnv = {
    APP_ENV: 'testing',
    APP_DEBUG: 'false',
    APP_URL: baseURL,
    APP_KEY: `base64:${Buffer.alloc(32, 0x5a).toString('base64')}`,
    APP_CONFIG_CACHE: path.join(envPath, 'config-cache.php'),
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    DB_URL: '',
    DB_FOREIGN_KEYS: 'true',
    SESSION_DRIVER: 'file',
    SESSION_COOKIE: cookieName,
    SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    PAYMENT_SIMULATION_ENABLED: 'false',
    PULSE_ENABLED: 'false',
    WIREGUARD_ENABLED: 'false',
    PLAYWRIGHT_ENV_PATH: envPath,
    PLAYWRIGHT_SESSION_PATH: sessionPath,
    PLAYWRIGHT_FIXTURE_PATH: fixturePath,
    PLAYWRIGHT_DATABASE_PATH: databasePath,
    PLAYWRIGHT_SESSION_COOKIE: cookieName,
};

for (const [key, value] of Object.entries(testEnvironment)) {
    if (value !== undefined) {
        process.env[key] = value;
    }
}
