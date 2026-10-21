import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const core = path.join(root, 'core');
for (const directory of ['storage/app/media', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache']) {
  fs.mkdirSync(path.join(core, directory), { recursive: true });
}
if (!fs.existsSync(path.join(core, 'storage/app/installed.json'))) {
  const envPath = path.join(core, '.env');
  let env = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : `APP_KEY=base64:${crypto.randomBytes(32).toString('base64')}\n`;
  for (const [key, value] of Object.entries({ SOLQUBIT_INSTALL_DB_HOST: 'database', SOLQUBIT_INSTALL_DB_NAME: 'solqubit', SOLQUBIT_INSTALL_DB_USER: 'solqubit', APP_NAME: 'Solqubit', APP_URL: 'http://127.0.0.1:8088', APP_ENV: 'production', APP_DEBUG: 'false', APP_LOCALE: 'es', APP_FALLBACK_LOCALE: 'es', SESSION_DRIVER: 'file', CACHE_STORE: 'file', QUEUE_CONNECTION: 'sync' })) {
    const regex = new RegExp(`^${key}=.*$`, 'm');
    env = regex.test(env) ? env.replace(regex, `${key}=${value}`) : `${env}\n${key}=${value}`;
  }
  fs.writeFileSync(envPath, env, { mode: 0o600 });

}
console.log('Entorno local preparado. Los recursos públicos se sirven directamente desde site/. No se modificó una instalación existente.');
