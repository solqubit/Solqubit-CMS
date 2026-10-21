import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
const root=path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const database=process.env.RELEASE_QA_DATABASE || 'solqubit_releaseqa';
const project=process.env.RELEASE_QA_PROJECT || 'solqubit-releaseqa';
const version=process.env.RELEASE_QA_VERSION || '1.1.1';
const contained=version.localeCompare('1.1.3','en',{numeric:true})>=0;
// RELEASE_QA_LAYOUT=private simulates HestiaCP: a writable "private" folder next to the web root.
const privateLayout=contained && process.env.RELEASE_QA_LAYOUT==='private';
const core=privateLayout ? '/var/www/private/solqubit-core' : contained ? '/var/www/site/solqubit-core' : '/var/www/.solqubit-release-engine';
const sourceBootstrap=process.env.RELEASE_QA_SOURCE_BOOTSTRAP==='1';
assert.match(project,/^solqubit-releaseqa[0-9]*$/);
assert.match(database,/^solqubit_releaseqa[0-9]*$/);
assert.match(version,/^\d+\.\d+\.\d+$/);
const credentials=JSON.parse(fs.readFileSync(path.join(root,`test-artifacts/${database}-access.json`),'utf8'));
const base='http://127.0.0.1:'+(process.env.RELEASE_QA_PORT || '8089');
const cookies=new Map();
async function request(url,options={}) {
 const response=await fetch(base+url,{...options,headers:{Cookie:[...cookies].map(([k,v])=>`${k}=${v}`).join('; '),...options.headers},redirect:'manual'});
 for(const cookie of response.headers.getSetCookie()){const pair=cookie.split(';')[0],at=pair.indexOf('=');cookies.set(pair.slice(0,at),pair.slice(at+1));}
 return response;
}
function command(args) {const result=spawnSync('docker',['compose','-p',project,'-f','compose.release-test.yaml','exec','-T','rootcheck',...args],{cwd:root,encoding:'utf8'});assert.equal(result.status,0,'Could not inspect release container: '+result.stderr);return result.stdout.trim();}
function php(code) {return command(['php','-r',code.replaceAll('/var/www/.solqubit-release-engine',core)]);}
if(sourceBootstrap) php("if (is_file('/var/www/site/install-core.php')) copy('/var/www/site/install-core.php','/tmp/qa-root-installer.php');");
if(privateLayout) php("if (!is_dir('/var/www/private')) mkdir('/var/www/private'); chown('/var/www/private','www-data');");
const first=await request('/install-core.php');
if([302,404].includes(first.status) && php("echo is_file('/var/www/.solqubit-release-engine/storage/app/installed.json') ? 'installed' : 'waiting';")==='installed'){
 await verifyInstalled();
 console.log('OK: instalación aislada ya completada; entrada de muestra y acceso comprobados sin reinstalar.');
 process.exit(0);
}
assert.equal(first.status,200);
const html=await first.text();
const csrf=html.match(/name="csrf" value="([^"]+)"/)[1];
assert.ok(!html.includes('name="setup_key"'));
if(contained) {
 assert.ok(!html.includes('name="private_path"'));
 assert.ok(!html.includes('No necesitas una clave'));
 assert.ok(html.includes('Tus archivos internos, protegidos.'));
}
async function bootstrap(target,csrfValue=csrf){return request('/install-core.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({csrf:csrfValue,private_path:target})});}
assert.equal((await bootstrap('/var/www/.solqubit-release-engine','invalid-csrf')).status,422,'Invalid CSRF was accepted');
if(!contained) assert.equal((await bootstrap('/var/www/site/private')).status,422,'Public engine placement was accepted');
if(contained && !privateLayout) {
 const htaccess=php("echo base64_encode(file_get_contents('/var/www/site/.htaccess'));");
 php("$rules=file_get_contents('/var/www/site/.htaccess');file_put_contents('/var/www/site/.htaccess',preg_replace('/^.*RewriteRule \\^solqubit-core.*\\R/m','',$rules));file_put_contents('/etc/apache2/conf-enabled/qa-unsafe.conf',\"<Directory /var/www/site/solqubit-core>\\nAllowOverride None\\nRequire all granted\\n</Directory>\\n\");");
 command(['apachectl','-k','graceful']);
 try {
  const unsafe=await bootstrap(core);
  assert.equal(unsafe.status,422,'Installer accepted an exposed engine');
  const unsafeHtml=await unsafe.text();
  assert.ok(unsafeHtml.includes('todavía es accesible'),unsafeHtml.match(/<div class="install-feedback[\s\S]*?<\/div>/)?.[0] || 'Missing rejection feedback');
  assert.equal(php(`echo is_dir('${core}') ? 'remaining' : 'clean';`),'clean');
  assert.equal(php("echo is_file('/var/www/site/.solqubit-path.php') ? 'created' : 'absent';"),'absent');
 } finally {
  php(`file_put_contents('/var/www/site/.htaccess',base64_decode('${htaccess}'));unlink('/etc/apache2/conf-enabled/qa-unsafe.conf');`);
  command(['apachectl','-k','graceful']);
 }
 php(`mkdir('${core}');file_put_contents('${core}/existing.txt','preserve');`);
 try {
  assert.equal((await bootstrap(core)).status,422,'Existing engine directory was overwritten');
  assert.equal(php(`echo file_get_contents('${core}/existing.txt');`),'preserve');
 } finally { php(`unlink('${core}/existing.txt');rmdir('${core}');`); }
}
const prepared=await bootstrap(contained ? '/var/www/unwanted-engine' : core);
assert.equal(prepared.status,303,'Engine extraction failed: '+await prepared.text());
if(contained) assert.equal(php("echo is_dir('/var/www/unwanted-engine') ? 'created' : 'absent';"),'absent');
assert.equal(php("echo is_file('/var/www/.solqubit-release-engine/vendor/autoload.php') ? 'ok' : 'missing';"),'ok');
assert.equal(php("echo require '/var/www/.solqubit-release-engine/bootstrap/public-path.php';"),'/var/www/site');
const form=await request('/install');assert.equal(form.status,200);
const installer=await form.text();assert.ok(installer.includes('Tu blog empieza aquí'));
assert.ok(installer.includes('name="db_host" value="localhost"'));
assert.ok(!installer.includes('name="install_token"') && !installer.includes('name="site_url"'));
assert.ok(installer.includes('type="radio" name="starter_content" value="sample"'));
assert.ok(installer.includes('requirement-item is-ok'));
const token=installer.match(/name="_token" value="([^"]+)"/)[1];
const missingCsrf=await request('/install',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({site_name:'invalid'})});
assert.equal(missingCsrf.status,419,'Missing CSRF token was accepted');
const body=new URLSearchParams({_token:token,starter_content:'sample', site_name:'Solqubit',timezone:'America/Guayaquil',db_host:'database',db_port:'3306',db_database:database,db_username:database,db_password:credentials.db_password,name:'Administrador',username:'release-admin',email:credentials.email,password:credentials.password,password_confirmation:credentials.password});
const invalidBody=new URLSearchParams(body);invalidBody.set('db_password','incorrect-password');
const badConnection=await request('/install/check-database',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body:invalidBody});
assert.equal(badConnection.status,422);const badMessage=await badConnection.text();assert.ok(badMessage.includes('No se pudo conectar'));assert.ok(!badMessage.includes('incorrect-password'));
const checked=await request('/install/check-database',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Accept:'application/json'},body});
assert.equal(checked.status,200);assert.equal((await checked.json()).ok,true);
assert.equal(php("echo is_file('/var/www/.solqubit-release-engine/storage/app/installed.json') ? 'installed' : 'waiting';"),'waiting');
const installed=await request('/install',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded',Referer:`${base}/install`},body});
assert.equal(installed.status,302,'Database/admin installation failed');
assert.ok(installed.headers.get('location').endsWith('/admin/login'),'Installer did not finish');
for(const [url,status] of [['/',200],['/admin/login',200],['/install',200],['/.env',403],['/.solqubit-path.php',403],['/.solqubit-setup.php',403],['/vendor/autoload.php',[403,404]],['/vendor/livewire/livewire.min.js',200]]){
 const response=await request(url);assert.ok([status].flat().includes(response.status),url+': '+response.status);await response.arrayBuffer();
}
if(contained) {
 for(const suffix of ['', '/', '/.env', '/composer.json', '/vendor/autoload.php', '/storage/app/installed.json', '/storage/logs/laravel.log', '/bootstrap/hosting-protection.json', '/%2eenv', '/vendor/../.env']) {
  const response=await request('/solqubit-core'+suffix);
  assert.ok([403,404].includes(response.status),'Internal route exposed: '+suffix);
 }
 assert.equal(php("echo json_encode(glob('/var/www/.solqubit-*'));"),'[]');
}
assert.equal(php("echo is_file('/var/www/.solqubit-release-engine/storage/app/installed.json') ? 'locked' : 'open';"),'locked');
assert.equal(php("echo is_file('/var/www/site/install-core.php') ? 'present' : 'removed';"),'removed');
await verifyInstalled();
console.log(`OK: paquete instalado con motor ${privateLayout ? 'en la carpeta privada del panel' : contained ? 'dentro del dominio' : 'separado'}; protección web, CSRF, MariaDB, administrador y cierre del asistente comprobados.`);

async function verifyInstalled(){
 assert.equal((await request('/entrada-de-muestra')).status,200);
 assert.equal((await request('/admin/login')).status,200);
 assert.equal(php("echo is_file('/var/www/site/install-core.php') ? 'present' : 'removed';"),'removed');
 assert.equal(php("echo is_file('/var/www/site/.solqubit-release.json') ? 'present' : 'removed';"),'removed');
 const complete = await (await request('/install')).text();
 const token = complete.match(/name="_token" value="([^"]+)"/)?.[1] || decodeURIComponent(cookies.get('XSRF-TOKEN') || '');
 const post = await request('/install', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','X-XSRF-TOKEN':token}, body:new URLSearchParams({})});
 assert.equal(post.status,404, 'Installed site reopened the setup');
 if(sourceBootstrap) php("copy('/tmp/qa-root-installer.php','/var/www/site/install-core.php');");
 else php(`$zip=new ZipArchive; $zip->open('/releases/solqubit-${version}-instalacion.zip',ZipArchive::RDONLY); $input=$zip->getStream('install-core.php'); $output=fopen('/var/www/site/install-core.php','wb'); stream_copy_to_stream($input,$output); fclose($input); fclose($output); $zip->close();`);
 try {
  const repeated = await request('/install-core.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:new URLSearchParams({private_path:'/var/www/unwanted-engine'})});
  assert.equal(repeated.status,302,'Reuploaded installer did not detect the existing installation');
  assert.equal(php("echo is_dir('/var/www/unwanted-engine') ? 'created' : 'absent';"),'absent');
 } finally {
  php("unlink('/var/www/site/install-core.php');");
 }
}
