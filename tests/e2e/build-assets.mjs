import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { build } from 'vite';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const environmentPath = process.env.PLAYWRIGHT_ENV_PATH;

if (!environmentPath) {
    throw new Error('An isolated Playwright environment directory is required to build the portal assets.');
}

await build({
    configFile: path.join(projectRoot, 'vite.config.js'),
    envDir: environmentPath,
});
