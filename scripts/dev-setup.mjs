import { randomBytes } from 'node:crypto';
import { existsSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

// Prepares a fresh clone for `docker compose up -d`: database credentials (cms/.env),
// the engine's .env and the storage folders. Existing files are never overwritten.
const path = fileURLToPath(new URL('../.env', import.meta.url));
if (!existsSync(path)) {
  writeFileSync(path, `SOLQUBIT_DB_PASSWORD=${randomBytes(24).toString('hex')}\nSOLQUBIT_DB_ROOT_PASSWORD=${randomBytes(32).toString('hex')}\n`, { mode: 0o600 });
  console.log('Credenciales locales de la base de datos creadas en .env (ignorado por Git).');
} else {
  console.log('Se conservan las credenciales locales existentes.');
}
await import('./prepare-local.mjs');
