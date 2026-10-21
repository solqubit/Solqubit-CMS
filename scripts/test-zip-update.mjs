import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const project = process.env.RELEASE_QA_PROJECT || 'solqubit-releaseqa';
const database = process.env.RELEASE_QA_DATABASE || 'solqubit_releaseqa';
assert.match(project, /^solqubit-releaseqa[0-9]*$/);
assert.match(database, /^solqubit_releaseqa[0-9]*$/);
const credentials = JSON.parse(fs.readFileSync(path.join(root, `test-artifacts/${database}-access.json`), 'utf8'));
const base = 'http://127.0.0.1:' + (process.env.RELEASE_QA_PORT || '8089');
let core;
const compose = ['compose', '-p', project, '-f', 'compose.release-test.yaml'];
function docker(args, input) {
  const result = spawnSync('docker', [...compose, ...args], { cwd: root, input, encoding: 'utf8', maxBuffer: 1024 * 1024, timeout: 180000 });
  assert.equal(result.status, 0, `Release QA command failed: ${result.stderr}`);
  return result.stdout.trim();
}
function php(code, input, user = 'www-data') { return docker(['exec', '-T', '--user', user, 'rootcheck', 'php', '-r', code], input); }
core = php("echo require '/var/www/site/.solqubit-path.php';");
assert.ok(['/var/www/.solqubit-release-engine', '/var/www/site/solqubit-core', '/var/www/private/solqubit-core'].includes(core));
function app(code) { return php(`require '${core}/vendor/autoload.php'; $app = require '${core}/bootstrap/app.php'; $app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); ${code}`); }
const currentVersion = process.env.RELEASE_QA_VERSION || '1.1.1';
assert.match(currentVersion, /^\d+\.\d+\.\d+$/);
const parts = currentVersion.split('.').map(Number);
const actualRelease = process.env.RELEASE_QA_TARGET_VERSION;
const nextVersion = actualRelease || `${parts[0]}.${parts[1]}.${parts[2] + 1}`;
assert.match(nextVersion, /^\d+\.\d+\.\d+$/);
const zipPath = actualRelease
  ? path.join(root, `release/solqubit-${nextVersion}-actualizacion.zip`)
  : path.join(root, `test-artifacts/${database}-update.zip`);
assert.equal(app('echo config("solqubit.version");'), currentVersion, 'Only run this probe against a fresh isolated installation');

const cookies = new Map();
async function request(url, options = {}) {
  const target = new URL(url, base);
  assert.equal(target.origin, base);
  const response = await fetch(target, { ...options, redirect: 'manual', headers: { Cookie: [...cookies].map(([k,v]) => `${k}=${v}`).join('; '), ...options.headers } });
  for (const cookie of response.headers.getSetCookie()) {
    const pair = cookie.split(';')[0], at = pair.indexOf('=');
    cookies.set(pair.slice(0, at), pair.slice(at + 1));
  }
  return response;
}
const decode = value => value.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
async function login(identifier) {
  cookies.clear();
  const form = await request('/admin/login');
  assert.equal(form.status, 200);
  const html = await form.text();
  assert.ok(html.includes('Correo o usuario'));
  const snapshot = [...html.matchAll(/wire:snapshot="([^"]+)"/g)].map(m => decode(m[1])).find(raw => JSON.parse(raw).memo.name.toLowerCase().includes('login'));
  assert.ok(snapshot, 'Login component not found');
  const token = html.match(/name="csrf-token" content="([^"]+)"/)[1];
  const endpoint = html.match(/data-update-uri="([^"]+)"/)[1];
  const response = await request(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-Livewire': 'true', Accept: 'application/json' }, body: JSON.stringify({ _token: token, components: [{ snapshot, updates: { 'data.email': identifier, 'data.password': credentials.password }, calls: [{ path: '', method: 'authenticate', params: [] }] }] }) });
  assert.equal(response.status, 200, 'Login request failed');
  const data = await response.json();
  assert.ok(data.components?.[0]?.effects?.redirect, 'Login did not authenticate');
  assert.equal((await request('/admin/actualizaciones')).status, 200);
}
await login('release-admin');
await login(credentials.email);
console.log('OK: acceso por usuario y correo en el paquete instalado.');

// From the published update ZIP of this version, builds the next one with the changes that broke updates before or
// could: a file in a new public folder, a new engine folder, a new migration, a changed view and a retired file.
const retired = 'site/installer.js';
if (!actualRelease) {
php(`
copy('/releases/solqubit-${currentVersion}-actualizacion.zip', '/tmp/qa-update.zip');
$zip = new ZipArchive; $zip->open('/tmp/qa-update.zip');
$manifest = json_decode($zip->getFromName('.solqubit-update.json'), true, 32, JSON_THROW_ON_ERROR);
if (($manifest['format'] ?? null) !== 3 || !isset($manifest['files']['${retired}'])) { fwrite(STDERR, 'Unexpected update package'); exit(1); }
$manifest['version'] = '${nextVersion}'; $manifest['changes'] = ['Prueba aislada de actualización; no distribuir.'];
$config = str_replace("'version' => '${currentVersion}'", "'version' => '${nextVersion}'", $zip->getFromName('core/config/solqubit.php'));
$migration = '<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { \\Illuminate\\Support\\Facades\\Schema::create("qa_update_probe", function (\\Illuminate\\Database\\Schema\\Blueprint $table) { $table->id(); }); } public function down(): void { \\Illuminate\\Support\\Facades\\Schema::dropIfExists("qa_update_probe"); } };';
$view = 'core/resources/views/filament/media-details.blade.php';
$changes = [
    'core/config/solqubit.php' => $config,
    'core/database/migrations/2099_01_01_000000_qa_update_probe.php' => $migration,
    'site/nueva/prueba.js' => 'window.qaUpdateProbe = "${nextVersion}";',
    'core/app/Nueva/Prueba.php' => '<?php namespace App\\Nueva; class Prueba {}',
    $view => $zip->getFromName($view).'{{-- qa ${nextVersion} --}}',
];
foreach ($changes as $name => $content) {
    $zip->addFromString($name, $content);
    $manifest['files'][$name] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
}
$zip->deleteName('${retired}');
unset($manifest['files']['${retired}']);
$manifest['remove'] = ['${retired}'];
$list = json_decode($zip->getFromName('core/resources/solqubit/files.json'), true, 32, JSON_THROW_ON_ERROR);
$list = ['version' => '${nextVersion}', 'files' => array_map(fn ($entry) => $entry['sha256'], array_diff_key($manifest['files'], ['core/resources/solqubit/files.json' => true]))];
$listJson = json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
$zip->addFromString('core/resources/solqubit/files.json', $listJson);
$manifest['files']['core/resources/solqubit/files.json'] = ['size' => strlen($listJson), 'sha256' => hash('sha256', $listJson)];
ksort($manifest['files']);
$zip->addFromString('.solqubit-update.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$zip->close();
`, undefined, 'root');
docker(['cp', 'rootcheck:/tmp/qa-update.zip', zipPath]);
}

// The probe package is not published on GitHub, so this isolated site allows unverified ZIPs, as a local setup would.
if (!actualRelease) php(`$path = '${core}/.env'; $env = preg_replace('/^#? *SOLQUBIT_ALLOW_UNVERIFIED=.*$/m', '', file_get_contents($path)); file_put_contents($path, rtrim($env).PHP_EOL.'SOLQUBIT_ALLOW_UNVERIFIED=true'.PHP_EOL);`, undefined, 'root');

const snapshotCode = `
$result = [];
foreach (['users', 'posts', 'pages', 'media', 'menu_items', 'categories', 'series', 'tags'] as $table) {
    if (\\Illuminate\\Support\\Facades\\Schema::hasTable($table)) $result[$table] = hash('sha256', json_encode(\\Illuminate\\Support\\Facades\\DB::table($table)->orderBy('id')->get()));
}
$result['settings'] = hash('sha256', json_encode(\\Illuminate\\Support\\Facades\\DB::table('settings')->whereNotIn('key', ['cms_version', 'last_update'])->orderBy('key')->get()));
foreach (['.env', 'bootstrap/public-path.php', 'storage/app/installed.json', '.htaccess', 'bootstrap/hosting-protection.json'] as $file) if (is_file(base_path($file))) $result[$file] = hash_file('sha256', base_path($file));
$result['public_pointer'] = hash_file('sha256', public_path('.solqubit-path.php'));
$media = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(storage_path('app/media'), FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile()) $media[$file->getPathname()] = hash_file('sha256', $file->getPathname());
ksort($media); $result['media_bytes'] = hash('sha256', json_encode($media));
echo json_encode($result);`;
const before = JSON.parse(app(snapshotCode));
let panel = await (await request('/admin/actualizaciones')).text();
const csrf = () => panel.match(/name="_token" value="([^"]+)"/)[1];
async function upload() {
  const body = new FormData(); body.set('_token', csrf()); body.set('package', new Blob([fs.readFileSync(zipPath)], { type: 'application/zip' }), 'qa-update.zip');
  const response = await request('/admin/actualizaciones/package', { method: 'POST', body });
  assert.equal(response.status, 302, 'Package upload failed');
  panel = await (await request('/admin/actualizaciones')).text();
}
await upload();
assert.ok(panel.includes(`Aplicar actualización ${nextVersion}`), 'Update package was not accepted');
if (!actualRelease) assert.ok(panel.includes('Paquete sin verificar'), 'Unverified origin was not shown');
const applied = await request('/admin/actualizaciones/apply', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ _token: csrf() }) });
assert.equal(applied.status, 302, 'Package application failed');
const finishing = await request('/admin/actualizaciones');
assert.equal(finishing.status, 303, 'Fresh request did not complete the update');
const completed = await request('/admin/actualizaciones');
assert.equal(completed.status, 200);
panel = await completed.text();
assert.ok(panel.includes(`Solqubit ${nextVersion}`));
assert.ok(panel.includes('Última actualización completada'));
assert.equal(app('echo config("solqubit.version");'), nextVersion);
if (!actualRelease) {
  assert.equal(app('echo \\Illuminate\\Support\\Facades\\Schema::hasTable("qa_update_probe") ? "migrated" : "missing";'), 'migrated');
  const probe = await request('/nueva/prueba.js');
  assert.equal(probe.status, 200, 'A file in a new public folder was not served');
  assert.ok((await probe.text()).includes(nextVersion));
  assert.equal(php(`echo is_file('/var/www/${retired}') ? 'present' : 'retired';`), 'retired', 'The retired file is still there');
  assert.equal(php(`echo class_exists('App\\\\Nueva\\\\Prueba', false) || is_file('${core}/app/Nueva/Prueba.php') ? 'ok' : 'missing';`), 'ok');
  assert.equal((await request('/')).status, 200);
}
assert.deepEqual(JSON.parse(app(snapshotCode)), before, 'Update changed persistent data or private configuration');
assert.equal(php(`echo file_exists('${core}/storage/app/updates/transaction.json') ? 'pending' : 'finished';`), 'finished');
assert.equal(php("echo is_file('/var/www/site/install-core.php') ? 'reinstalled' : 'absent';"), 'absent');
for (const directory of ['stage', 'rollback', 'packages']) {
  assert.equal(php(`echo is_dir('${core}/storage/app/updates/${directory}') ? 'remaining' : 'removed';`), 'removed', `${directory} was not cleaned up`);
}
assert.ok(panel.includes('Ubicación de los archivos'));
assert.ok(panel.includes(core));
assert.equal((await request('/admin/pages')).status, 200);
assert.ok((await (await request('/admin/pages')).text()).includes('Papelera'));
await upload();
assert.ok(panel.includes('La versión debe ser más reciente'), 'Same-version package was accepted');
if (!actualRelease) fs.unlinkSync(zipPath);
console.log(`OK: actualización ${currentVersion} → ${nextVersion} por HTTP; contenido, biblioteca, cuentas, ajustes y configuración privada intactos; ZIP y temporales eliminados; panel de páginas disponible; misma versión rechazada.`);
