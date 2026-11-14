import { execFileSync } from 'node:child_process';
import { mkdir, rm } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import type { FullConfig } from '@playwright/test';
import {
    databasePath,
    environmentPath,
    fixturePath,
    repositoryRoot,
    sessionPath,
    testEnvironment,
} from './environment.js';

const setupScript = path.join(path.dirname(fileURLToPath(import.meta.url)), 'prepare-database.php');

async function cleanup(): Promise<void> {
    await Promise.all([
        rm(databasePath, { force: true }),
        rm(fixturePath, { force: true }),
        rm(sessionPath, { recursive: true, force: true }),
        rm(environmentPath, { recursive: true, force: true }),
    ]);
}

export default async function globalSetup(_config: FullConfig): Promise<() => Promise<void>> {
    await mkdir(path.dirname(databasePath), { recursive: true });
    await mkdir(environmentPath, { recursive: false });

    try {
        execFileSync('php', [setupScript], {
            cwd: repositoryRoot,
            env: testEnvironment,
            stdio: 'inherit',
        });

        const buildScript = path.join(path.dirname(fileURLToPath(import.meta.url)), 'build-assets.mjs');
        execFileSync(process.execPath, [buildScript], {
            cwd: repositoryRoot,
            env: testEnvironment,
            stdio: 'inherit',
        });
    } catch (error) {
        await cleanup();
        throw error;
    }

    return cleanup;
}
