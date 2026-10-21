import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

// Builds the installation ZIP and the update ZIP inside the "web" container.
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const previous = process.env.SOLQUBIT_PREVIOUS_MANIFEST;
const args = ['compose', 'run', '--rm', '--no-deps', '-T', '--entrypoint', 'php', '-v', `${root}:/workspace`];
// The container has no Git checkout, so the repository the package will trust is resolved here.
let repository = process.env.SOLQUBIT_UPDATE_REPOSITORY || process.env.GITHUB_REPOSITORY || '';
if (!repository) {
  const remote = spawnSync('git', ['remote', 'get-url', 'origin'], { cwd: root, encoding: 'utf8' });
  repository = remote.status === 0 ? (remote.stdout.trim().match(/github\.com[:/]([A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+?)(?:\.git)?\/?$/)?.[1] ?? '') : '';
}
if (repository) args.push('-e', `SOLQUBIT_UPDATE_REPOSITORY=${repository}`);
if (previous) {
  args.push('-e', 'SOLQUBIT_PREVIOUS_MANIFEST=/previous-manifest.json', '-v', `${path.resolve(previous)}:/previous-manifest.json:ro`);
}
args.push('web', '/workspace/scripts/build-release.php');
const result = spawnSync('docker', args, { cwd: root, stdio: 'inherit', timeout: 900000 });
if (result.error) throw result.error;
process.exitCode = result.status ?? 1;
