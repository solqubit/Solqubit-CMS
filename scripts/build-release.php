<?php
declare(strict_types=1);

// Dependencies: core/vendor after `composer install` (GitHub Actions or a local PHP), or the
// Docker volume at /var/www/core/vendor when run through scripts/build-release.mjs.
$vendor=is_file(dirname(__DIR__).'/core/vendor/autoload.php') ? dirname(__DIR__).'/core/vendor' : '/var/www/core/vendor';
require $vendor.'/autoload.php';
use Symfony\Component\Process\Process;

$root=dirname(__DIR__);
$app=require $root.'/core/bootstrap/app.php';
$config=require $root.'/core/config/solqubit.php';
$version=$config['version'];
// Oldest version that can update to this package with the ZIP updater.
$minimumVersion='1.0.0';
$changes=changelogEntries($root.'/CHANGELOG.md',$version);
$stage=sys_get_temp_dir().'/solqubit-release-'.bin2hex(random_bytes(8));
mkdir($stage,0700,true);
$core=$stage.'/core'; $site=$stage.'/site'; mkdir($core,0700); mkdir($site,0755);

/** @return list<string> The "- " lines of this version in CHANGELOG.md, without Markdown. */
function changelogEntries(string $path,string $version): array {
    if (!is_file($path) || !preg_match('/^## \['.preg_quote($version,'/').'\][^
]*
(.*?)(?=^## |\z)/ms',file_get_contents($path),$section)) throw new RuntimeException('Añade la sección ## ['.$version.'] a CHANGELOG.md antes de generar el paquete.');
    preg_match_all('/^- (.+)$/m',$section[1],$lines);
    $entries=array_map(fn($line)=>trim(preg_replace('/[*_`]|\[([^\]]+)\]\([^)]+\)/','$1',$line)),$lines[1]);
    if (!$entries) throw new RuntimeException('La sección ['.$version.'] de CHANGELOG.md no tiene cambios.');
    return array_slice($entries,0,20);
}
function copyTree(string $from,string $to,?callable $accept=null): void {
    if (!is_dir($to)) mkdir($to,0755,true);
    // scandir returns a complete listing on Docker Desktop bind mounts where
    // DirectoryIterator can start partway through a large Windows directory.
    $walk=function (string $relative='') use (&$walk,$from,$to,$accept): void {
        foreach (scandir($from.($relative ? '/'.$relative : '')) as $name) {
            if ($name==='.' || $name==='..') continue;
            $child=$relative ? $relative.'/'.$name : $name;
            $source=$from.'/'.$child; $target=$to.'/'.$child; $file=new SplFileInfo($source);
            if (($accept && !$accept($child,$file)) || is_link($source)) continue;
            if (is_dir($source)) { if (!is_dir($target)) mkdir($target,0755,true); $walk($child); }
            elseif (!copy($source,$target)) throw new RuntimeException('No se pudo copiar '.$child);
        }
    };
    $walk();
}
function archiveTree(ZipArchive $zip,string $from,string $prefix=''): void {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $relative=str_replace('\\','/',substr($file->getPathname(),strlen($from)+1));
        if ($file->isDir()) $zip->addEmptyDir($prefix.$relative);
        elseif (!$zip->addFile($file->getPathname(),$prefix.$relative)) throw new RuntimeException('No se pudo empaquetar '.$relative);
    }
}
foreach (['app','bootstrap','config','database','resources','routes','themes','lang'] as $directory) {
    copyTree($root.'/core/'.$directory,$core.'/'.$directory,function ($relative,$file) use ($directory) {
        if ($directory==='bootstrap' && (in_array($relative,['public-path.php','hosting-protection.json'],true)||str_starts_with($relative,'cache/'))) return false;
        if ($directory==='database' && str_contains($relative,'.sqlite')) return false;
        return true;
    });
}
copyTree($vendor,$core.'/vendor');
foreach (['artisan','composer.json','composer.lock','.env.example'] as $file) copy($root.'/core/'.$file,$core.'/'.$file);
foreach (['storage/app/private','storage/app/media','storage/framework/cache/data','storage/framework/sessions','storage/framework/views','storage/logs','bootstrap/cache'] as $directory) if (!is_dir($core.'/'.$directory)) mkdir($core.'/'.$directory,0755,true);
copyTree($root.'/site',$site,fn ($relative,$file)=>!in_array($relative,['.solqubit-path.php','.solqubit-setup.php','install-core.php','.solqubit-release.json'],true));
$composer=new Process(['composer','install','--no-dev','--no-scripts','--classmap-authoritative','--no-interaction','--no-progress','--no-ansi'],$core,null,null,600);
$composer->mustRun(function ($type,$buffer) { echo $buffer; });
$discover=new Process([PHP_BINARY,'artisan','package:discover','--no-ansi'],$core,null,null,120); $discover->mustRun();
$assets=new Process([PHP_BINARY,'artisan','filament:assets','--no-ansi'],$core,null,null,120); $assets->mustRun();
// Static copy of Livewire's script: nginx panels (CloudPanel, HestiaCP) serve *.js without PHP.
$livewire=new Process([PHP_BINARY,'artisan','vendor:publish','--tag=livewire:assets','--force','--no-interaction','--no-ansi'],$core,null,null,120); $livewire->mustRun();
if (!is_file($site.'/vendor/livewire/livewire.min.js')) throw new RuntimeException('No se publicaron los recursos de Livewire.');
// Development-only files that a running site never loads: source maps, package docs and tests, unused Livewire
// builds (the CSP and ESM variants; csp_safe is off) and Filament's dist copies, already published under site/.
function releaseExcludes(string $relative): bool {
    if (str_ends_with($relative,'.map')) return true;
    if (preg_match('~^core/vendor/[^/]+/[^/]+/(tests?|Tests?|docs?|\.github)/~',$relative)) return true;
    if (preg_match('~^core/vendor/[^/]+/[^/]+/[^/]+\.(md|dist)$~i',$relative) && stripos($relative,'license')===false) return true;
    if (preg_match('~^(site/vendor/livewire|core/vendor/livewire/livewire/dist)/livewire\.(csp|esm)[^/]*\.js$~',$relative)) return true;
    return (bool) preg_match('~^core/vendor/filament/[^/]+/dist/~',$relative);
}
foreach (['core'=>$core, 'site'=>$site] as $prefix=>$folder) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        $relative=$prefix.'/'.str_replace('\\','/',substr($file->getPathname(),strlen($folder)+1));
        if ($file->isFile() && releaseExcludes($relative) && !unlink($file->getPathname())) throw new RuntimeException('No se pudo excluir '.$relative);
    }
}
// The exclusions must never remove code the engine loads: every Composer "files" entry must remain and the
// application must boot (providers, Filament panel and routes) from the trimmed copy.
foreach (require $core.'/vendor/composer/autoload_files.php' as $autoloaded) {
    if (!is_file($autoloaded)) throw new RuntimeException('El recorte eliminó un archivo que Composer carga al arrancar: '.$autoloaded);
}
$boot=new Process([PHP_BINARY,'artisan','route:list','--json','--no-ansi'],$core,['APP_ENV'=>'production','APP_KEY'=>'base64:'.base64_encode(random_bytes(32))],null,120);
$boot->run();
if (!$boot->isSuccessful() || !is_array(json_decode($boot->getOutput(),true))) throw new RuntimeException('El motor recortado no arranca: '.trim($boot->getErrorOutput()));
// Repository the installed site will trust for updates: forks built by their own GitHub Actions point to themselves.
$repository=getenv('SOLQUBIT_UPDATE_REPOSITORY') ?: getenv('GITHUB_REPOSITORY') ?: '';
if ($repository==='') {
    try {
        $remote=new Process(['git','remote','get-url','origin'],$root,null,null,10); $remote->run();
        if ($remote->isSuccessful() && preg_match('~github\.com[:/]([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(?:\.git)?/?$~',trim($remote->getOutput()),$match)) $repository=$match[1];
    } catch (Throwable) {}
}
if ($repository!=='' && !preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~',$repository)) throw new RuntimeException('SOLQUBIT_UPDATE_REPOSITORY debe tener la forma usuario/repositorio.');
if ($repository==='') echo "Aviso: el paquete no tiene repositorio de actualizaciones. Los sitios instalados con él solo aceptarán ZIP si su .env tiene SOLQUBIT_ALLOW_UNVERIFIED=true.\n";
$sourcePath=$core.'/'.\App\Services\GitHubReleases::SOURCE;
if (!is_dir(dirname($sourcePath))) mkdir(dirname($sourcePath),0755,true);
file_put_contents($sourcePath,"<?php\n\nreturn ['repository' => ".var_export($repository,true)."];\n");
// Every release ships its own file list, so the updater can retire whatever a newer release drops.
$files=[];
foreach (['core'=>$core, 'site'=>$site] as $prefix=>$folder) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->isLink()) continue;
        $relative=$prefix.'/'.str_replace('\\','/',substr($file->getPathname(),strlen($folder)+1));
        if (!\App\Services\UpdatePackage::allowedPath($relative)) {
            // Installed sites reject a ZIP with any other public file, and their updater's list cannot change.
            if ($prefix==='site' && !in_array($relative,['site/index.php','site/.htaccess'],true)) throw new RuntimeException($relative.' no se puede actualizar en los sitios instalados: muévelo a site/js, site/css u otra carpeta permitida.');
            continue;
        }
        $files[$relative]=['size'=>$file->getSize(), 'sha256'=>hash_file('sha256',$file->getPathname())];
    }
}
$listRelative='core/'.\App\Services\ReleaseFiles::LIST; $listPath=$core.'/'.\App\Services\ReleaseFiles::LIST;
unset($files[$listRelative]); ksort($files);
if (!is_dir(dirname($listPath))) mkdir(dirname($listPath),0755,true);
file_put_contents($listPath,json_encode(['version'=>$version,'files'=>array_map(fn ($entry)=>$entry['sha256'],$files)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n");
$files[$listRelative]=['size'=>filesize($listPath), 'sha256'=>hash_file('sha256',$listPath)]; ksort($files);
$payload=$stage.'/engine.zip'; $zip=new ZipArchive(); $zip->open($payload,ZipArchive::CREATE|ZipArchive::OVERWRITE); archiveTree($zip,$core); $zip->close();
$template=rtrim(file_get_contents($root.'/scripts/root-installer.php.template'));
$hostingLayout=preg_replace('/^<\?php\s*/','',file_get_contents($root.'/core/app/Services/HostingLayout.php'));
$hostingLayout=str_replace('namespace App\Services;','',$hostingLayout);
$template=str_replace('__HOSTING_LAYOUT__',$hostingLayout,$template);
$template=str_replace('__PAYLOAD_SHA256__',hash_file('sha256',$payload),$template);
$installer=fopen($site.'/install-core.php','wb'); fwrite($installer,$template); $payloadStream=fopen($payload,'rb'); stream_copy_to_stream($payloadStream,$installer); fclose($payloadStream); fclose($installer);
$output=$root.'/release'; if (!is_dir($output)) mkdir($output,0700,true);
copy($root.'/docs/INSTALL.md',$output.'/INSTALL.md');
copy($root.'/docs/UPDATE.md',$output.'/UPDATE.md');
copy($root.'/deploy/nginx-locations.conf',$output.'/nginx-locations.conf');

// Update manifest (format 3): what the panel checks before replacing any file of an installed site.
$manifest = ['product'=>'solqubit-cms','format'=>3,'version'=>$version,'min_version'=>$minimumVersion,'php'=>'8.3.0',
    'extensions'=>['ctype','curl','dom','fileinfo','filter','hash','mbstring','openssl','pdo_mysql','session','tokenizer','xml','intl','zip','gd','bcmath'],
    'repository'=>$repository, 'changes'=>$changes, 'files'=>$files, 'remove'=>[]];
$previous = getenv('SOLQUBIT_PREVIOUS_MANIFEST');
if ($previous) {
    $old=json_decode(file_get_contents($previous),true,32,JSON_THROW_ON_ERROR);
    foreach (array_diff(array_keys($old['files']),array_keys($manifest['files'])) as $removed) {
        if (\App\Services\UpdatePackage::allowedPath($removed) && !str_starts_with($removed,'core/database/migrations/')) $manifest['remove'][]=$removed;
    }
}
foreach (glob($output.'/solqubit-'.$version.'*') ?: [] as $old) unlink($old);
// Installation ZIP: the public files and install-core.php with the engine inside. It is never used to update.
$installPath = $output.'/solqubit-'.$version.'-instalacion.zip';
$install = new ZipArchive();
if ($install->open($installPath, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo crear el ZIP de instalación.');
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->isLink()) continue;
    $relative = str_replace('\\','/',substr($file->getPathname(),strlen($site)+1));
    if (!$install->addFile($file->getPathname(),$relative)) throw new RuntimeException('No se pudo añadir '.$relative);
}
$install->close();
// Update ZIP: only the files an installed site may replace, at their final path, and the manifest.
$updatePath = $output.'/solqubit-'.$version.'-actualizacion.zip';
$update = new ZipArchive();
if ($update->open($updatePath, ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('No se pudo crear el ZIP de actualización.');
foreach (array_keys($files) as $relative) {
    $source = (str_starts_with($relative,'core/') ? $core : $site).'/'.substr($relative,5);
    if (!$update->addFile($source,$relative)) throw new RuntimeException('No se pudo añadir '.$relative);
}
$manifestJson=json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
$update->addFromString(\App\Services\UpdatePackage::MANIFEST,$manifestJson);
$update->close();
foreach ([$installPath, $updatePath] as $packagePath) {
    file_put_contents($packagePath.'.sha256',hash_file('sha256',$packagePath).'  '.basename($packagePath)."\n");
}
file_put_contents($output.'/solqubit-'.$version.'-manifest.json',$manifestJson);
(new \Illuminate\Filesystem\Filesystem)->deleteDirectory($stage);
echo 'Instalación: '.$installPath."\n".'Actualización: '.$updatePath."\n";
