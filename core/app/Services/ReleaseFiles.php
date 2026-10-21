<?php

namespace App\Services;

/** The file list shipped with each release and the files an update must retire. */
class ReleaseFiles
{
    public const LIST = 'resources/solqubit/files.json';

    /** Folders that only the release package writes to. */
    public const MANAGED = ['core/app', 'core/config', 'core/routes', 'core/vendor', 'site/js/filament', 'site/css/filament', 'site/fonts/filament', 'site/vendor/livewire'];

    /**
     * Files of the installed release, or null when this installation has no trustworthy list.
     *
     * @return array<string, string>|null Path => SHA-256
     */
    public function installed(): ?array
    {
        $path = config('solqubit.update_core', base_path()).'/'.self::LIST;
        if (! is_file($path) || filesize($path) > 16 * 1024 ** 2) {
            return null;
        }
        $list = json_decode((string) file_get_contents($path), true, 32);
        if (! is_array($list) || ($list['version'] ?? null) !== config('solqubit.version') || ! is_array($list['files'] ?? null)) {
            return null;
        }
        foreach ($list['files'] as $relative => $hash) {
            if (! is_string($relative) || ! UpdatePackage::allowedPath($relative) || ! is_string($hash) || ! preg_match('/^[a-f0-9]{64}$/', $hash)) {
                return null;
            }
        }

        return $list['files'];
    }

    /**
     * Files present now that the new release no longer ships, whatever versions were skipped.
     *
     * @param  array{files: array<string, mixed>, remove?: list<string>}  $manifest
     * @return list<string>
     */
    public function obsolete(array $manifest): array
    {
        $updater = app(ZipUpdater::class);
        $candidates = array_unique([...array_keys($this->installed() ?? []), ...($manifest['remove'] ?? [])]);
        $obsolete = array_filter($candidates, fn (mixed $relative): bool => is_string($relative)
            && UpdatePackage::allowedPath($relative)
            && ! str_starts_with($relative, 'core/database/migrations/')
            && ! isset($manifest['files'][$relative])
            && is_file($updater->target($relative)));
        sort($obsolete);

        return array_values($obsolete);
    }

    /**
     * Compares the files on disk with the installed release list.
     *
     * @return array{modified: list<string>, missing: list<string>, extra: list<string>, total: int}|null
     */
    public function check(): ?array
    {
        $installed = $this->installed();
        if ($installed === null) {
            return null;
        }
        $updater = app(ZipUpdater::class);
        $modified = [];
        $missing = [];
        foreach ($installed as $relative => $hash) {
            $target = $updater->target($relative);
            if (! is_file($target)) {
                $missing[] = $relative;
            } elseif (! hash_equals($hash, (string) hash_file('sha256', $target))) {
                $modified[] = $relative;
            }
        }
        $extra = [];
        foreach (self::MANAGED as $directory) {
            $root = $updater->target($directory);
            if (! is_dir($root) || is_link($root)) {
                continue;
            }
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = $directory.'/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                if (($file->isFile() || $file->isLink()) && ! isset($installed[$relative])) {
                    $extra[] = $relative;
                }
            }
        }
        sort($modified);
        sort($missing);
        sort($extra);

        return ['modified' => $modified, 'missing' => $missing, 'extra' => $extra, 'total' => count($installed)];
    }

    /** @param list<string> $removed */
    public function pruneEmptyDirectories(array $removed): void
    {
        $updater = app(ZipUpdater::class);
        foreach ($removed as $relative) {
            $parts = explode('/', $relative);
            $floor = str_starts_with($relative, 'site/vendor/livewire/') ? 3 : 2;
            for ($depth = count($parts) - 1; $depth > $floor; $depth--) {
                $directory = $updater->target(implode('/', array_slice($parts, 0, $depth)));
                if (! is_dir($directory) || is_link($directory) || (new \FilesystemIterator($directory))->valid() || ! @rmdir($directory)) {
                    break;
                }
            }
        }
    }
}
