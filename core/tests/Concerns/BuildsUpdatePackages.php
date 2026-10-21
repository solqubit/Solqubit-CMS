<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\File;

/** An isolated installation on disk and small update packages for it. */
trait BuildsUpdatePackages
{
    private string $work;

    private function prepareUpdateWorkspace(): void
    {
        $this->work = sys_get_temp_dir().'/sq-update-test-'.bin2hex(random_bytes(8));
        foreach (['core/storage/app/updates', 'core/app', 'core/bootstrap/cache', 'site/editor'] as $dir) {
            File::ensureDirectoryExists($this->work.'/'.$dir, 0700);
        }
        file_put_contents($this->work.'/installed', '{}');
        config(['solqubit.installed_file' => $this->work.'/installed', 'solqubit.update_core' => $this->work.'/core', 'solqubit.update_site' => $this->work.'/site', 'solqubit.update_storage' => $this->work.'/core/storage/app/updates', 'solqubit.version' => '1.1.0', 'solqubit.update_repository' => '', 'solqubit.allow_unverified' => true]);
    }

    private function removeUpdateWorkspace(): void
    {
        File::deleteDirectory($this->work);
    }

    /**
     * An update ZIP in the release format: ".solqubit-update.json" and every file at its final path.
     *
     * @param  array<string, string>  $files
     */
    private function package(array $files = ['core/app/Example.php' => '<?php // new'], array $overrides = []): string
    {
        $manifest = $overrides + ['product' => 'solqubit-cms', 'format' => 3, 'version' => '1.1.1', 'min_version' => '1.1.0', 'php' => '8.3.0', 'extensions' => ['zip'], 'changes' => ['Correcciones'], 'files' => [], 'remove' => []];
        $path = $this->work.'/'.bin2hex(random_bytes(6)).'.zip';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        foreach ($files as $name => $content) {
            $manifest['files'][$name] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)];
            $zip->addFromString($name, $content);
        }
        $zip->addFromString('.solqubit-update.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $zip->close();

        return $path;
    }
}
