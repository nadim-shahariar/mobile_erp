import { spawnSync } from 'node:child_process';
import { cp, mkdir, mkdtemp, readFile, readdir, rename, rm, stat } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const destination = path.join(root, 'public/build');
const staging = await mkdtemp(path.join(root, '.vite-build-'));

try {
    const result = spawnSync(process.execPath, [
        path.join(root, 'node_modules/vite/bin/vite.js'),
        'build', '--outDir', staging, '--emptyOutDir', ...process.argv.slice(2),
    ], { cwd: root, stdio: 'inherit' });
    if (result.error) throw result.error;
    if (result.status !== 0) process.exitCode = result.status ?? 1;
    else {
        const manifestPath = path.join(staging, 'manifest.json');
        const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
        for (const entry of Object.values(manifest)) {
            for (const file of [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])]) {
                if (!file || !path.resolve(staging, file).startsWith(staging + path.sep)) {
                    throw new Error('Invalid asset path in the generated manifest.');
                }
                await stat(path.join(staging, file));
            }
        }
        await mkdir(destination, { recursive: true });
        // Keep previous hashed assets available to pages already open in a browser.
        for (const entry of await readdir(staging)) {
            if (entry !== 'manifest.json') {
                await cp(path.join(staging, entry), path.join(destination, entry), { recursive: true, force: false });
            }
        }
        // Publish only once every referenced asset exists. A failed build leaves the live build intact.
        await rename(manifestPath, path.join(destination, 'manifest.json'));
        console.log('Published frontend assets without removing the live manifest.');
    }
} finally {
    await rm(staging, { recursive: true, force: true });
}
