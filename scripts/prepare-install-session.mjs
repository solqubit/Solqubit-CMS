import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const directory = path.join(root, 'test-artifacts/install-session');
const credentialsPath = path.join(directory, 'access.json');
fs.mkdirSync(directory, { recursive: true });
let credentials;
if (fs.existsSync(credentialsPath)) {
  credentials = JSON.parse(fs.readFileSync(credentialsPath, 'utf8'));
} else {
  credentials = { database: 'solqubit_install', user: 'solqubit_install', password: crypto.randomBytes(24).toString('hex'), appKey: crypto.randomBytes(32).toString('base64') };
  fs.writeFileSync(credentialsPath, JSON.stringify(credentials, null, 2), { mode: 0o600 });
}
const sql = `CREATE DATABASE IF NOT EXISTS solqubit_install CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;\nCREATE USER IF NOT EXISTS 'solqubit_install'@'%' IDENTIFIED BY '${credentials.password}';\nGRANT ALL PRIVILEGES ON solqubit_install.* TO 'solqubit_install'@'%';\n`;
execFileSync('docker', ['compose', 'exec', '-T', 'database', 'sh', '-c', 'exec mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"'], { cwd: root, input: sql, stdio: ['pipe', 'pipe', 'pipe'] });
fs.writeFileSync(path.join(directory, 'app.env'), `APP_NAME=Solqubit\nAPP_ENV=production\nAPP_DEBUG=false\nAPP_KEY=base64:${credentials.appKey}\nAPP_URL=http://127.0.0.1:8090\nAPP_LOCALE=es\nAPP_FALLBACK_LOCALE=es\nSOLQUBIT_INSTALL_DB_HOST=database\nSOLQUBIT_INSTALL_DB_NAME=solqubit_install\nSOLQUBIT_INSTALL_DB_USER=solqubit_install\nDB_CONNECTION=mysql\nDB_HOST=database\nDB_PORT=3306\nDB_DATABASE=solqubit_install\nDB_USERNAME=solqubit_install\nDB_PASSWORD=${credentials.password}\nSESSION_DRIVER=file\nSESSION_COOKIE=solqubit_installer_session\nCACHE_STORE=file\nQUEUE_CONNECTION=sync\n`, { mode: 0o600 });
fs.writeFileSync(path.join(directory, 'LEEME.txt'), `INSTALACIÓN LOCAL NUEVA DE SOLQUBIT\n\nAbre http://127.0.0.1:8090/install\n\nServidor de base de datos: database\nPuerto: 3306\nBase de datos: solqubit_install\nUsuario: solqubit_install\nContraseña de la base: ${credentials.password}\n\nElige tú el nombre, correo y contraseña del administrador.\nLa dirección se detecta automáticamente.\nElige una entrada de muestra o un blog completamente vacío.\n\nEste archivo es privado. No lo publiques.\n`, { mode: 0o600 });
const envPath = path.join(root, 'core/.env');
let env = fs.readFileSync(envPath, 'utf8');
const line = 'SOLQUBIT_INSTALLER_URL=http://127.0.0.1:8090/install';
env = /^SOLQUBIT_INSTALLER_URL=/m.test(env) ? env.replace(/^SOLQUBIT_INSTALLER_URL=.*$/m, line) : env.trimEnd()+'\n'+line+'\n';
fs.writeFileSync(envPath, env);
console.log('Instalación preparada en 8090. Datos privados en test-artifacts/install-session/LEEME.txt. No se ha enviado el formulario.');
