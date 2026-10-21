<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** Releases of the repository this installation trusts for updates. */
class GitHubReleases
{
    /** Written by build-release.php: the repository the installed package was built from. */
    public const SOURCE = 'resources/solqubit/source.php';

    public const DEFAULT_REPOSITORY = 'solqubit/Solqubit-CMS';

    public const CACHE_KEY = 'solqubit.latest-release';

    /** The .env value wins, then the repository recorded in the installed package, then the official one. */
    public function repository(): ?string
    {
        $repository = config('solqubit.update_repository');
        if ($repository === null) {
            $source = config('solqubit.update_core', base_path()).'/'.self::SOURCE;
            $repository = is_file($source) ? ((require $source)['repository'] ?? '') : self::DEFAULT_REPOSITORY;
        }

        return is_string($repository) && preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repository) && ! str_contains($repository, '..') ? $repository : null;
    }

    /**
     * Latest published release, cached for 12 hours (1 hour when GitHub could not be asked).
     *
     * @return array{status: 'ok'|'error'|'disabled', version?: string, url?: string, published_at?: string, notes?: list<string>, message?: string, checked_at: string}
     */
    public function latest(bool $refresh = false): array
    {
        $repository = $this->repository();
        if ($repository === null) {
            return ['status' => 'disabled', 'checked_at' => now()->toIso8601String()];
        }
        $cached = $refresh ? null : $this->cached();
        if ($cached !== null && ($cached['repository'] ?? null) === $repository) {
            return $cached;
        }
        $release = $this->fetchLatest($repository) + ['repository' => $repository, 'checked_at' => now()->toIso8601String()];
        Cache::put(self::CACHE_KEY, $release, $release['status'] === 'ok' ? now()->addHours(12) : now()->addHour());

        return $release;
    }

    /** Reads the last answer without contacting GitHub. */
    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    public function newer(?array $release): bool
    {
        return ($release['status'] ?? null) === 'ok' && version_compare($release['version'], config('solqubit.version'), '>');
    }

    /** @return array{status: 'ok'|'error', version?: string, url?: string, published_at?: string, notes?: list<string>, message?: string} */
    private function fetchLatest(string $repository): array
    {
        try {
            $response = $this->client()->timeout(8)->accept('application/vnd.github+json')->get('https://api.github.com/repos/'.$repository.'/releases/latest');
        } catch (ConnectionException) {
            return ['status' => 'error', 'message' => 'No se pudo contactar con GitHub. Si tu hosting bloquea las conexiones externas, el aviso de versiones no está disponible.'];
        }
        if ($response->status() === 404) {
            return ['status' => 'error', 'message' => 'El repositorio github.com/'.$repository.' no tiene releases publicadas.'];
        }
        if (! $response->successful() || ! is_string($response->json('tag_name'))) {
            return ['status' => 'error', 'message' => 'GitHub no respondió correctamente (estado '.$response->status().'). Inténtalo más tarde.'];
        }
        if (! preg_match('/^v(\d+\.\d+\.\d+)$/', $response->json('tag_name'), $tag)) {
            return ['status' => 'error', 'message' => 'La última release de GitHub no tiene una versión válida.'];
        }
        $body = (string) $response->json('body');
        $notes = [];
        foreach (preg_split('/\R/', $body) as $line) {
            if (str_starts_with($line, '###')) {
                break;
            }
            if (str_starts_with($line, '- ')) {
                $notes[] = trim(preg_replace('/[*_`]|\[([^\]]+)\]\([^)]+\)/', '$1', substr($line, 2)));
            }
        }
        $url = (string) $response->json('html_url');

        return ['status' => 'ok', 'version' => $tag[1], 'url' => str_starts_with($url, 'https://github.com/') ? $url : 'https://github.com/'.$repository.'/releases', 'published_at' => (string) $response->json('published_at'), 'notes' => array_slice($notes, 0, 20)];
    }

    /** The release file that updates an installed site; the other ZIP of each release only installs. */
    public static function updateAsset(string $version): string
    {
        return 'solqubit-'.$version.'-actualizacion.zip';
    }

    public function assetUrl(string $version, string $file): string
    {
        return 'https://github.com/'.$this->repository().'/releases/download/v'.$version.'/'.$file;
    }

    /** SHA-256 published next to the release ZIP, or null when the release or a valid checksum does not exist. */
    public function checksum(string $version): ?string
    {
        try {
            $response = $this->client()->timeout(10)->get($this->assetUrl($version, self::updateAsset($version).'.sha256'));
        } catch (ConnectionException $error) {
            throw new GitHubUnavailable($error->getMessage(), previous: $error);
        }
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new GitHubUnavailable('GitHub respondió con el estado '.$response->status().'.');
        }
        $body = $response->body();

        return strlen($body) <= 1024 && preg_match('/^([a-f0-9]{64})(\s|$)/', $body, $match) ? $match[1] : null;
    }

    /** Streams the release ZIP to $target, never holding more than one block in memory. */
    public function download(string $version, string $target): void
    {
        try {
            $response = $this->client()->timeout(300)->connectTimeout(10)->withOptions(['stream' => true])->get($this->assetUrl($version, self::updateAsset($version)));
        } catch (ConnectionException $error) {
            throw new GitHubUnavailable('No se pudo descargar el ZIP desde GitHub. Inténtalo más tarde o súbelo manualmente.', previous: $error);
        }
        if (! $response->successful()) {
            throw new GitHubUnavailable('GitHub no entregó el ZIP (estado '.$response->status().'). Inténtalo más tarde o súbelo manualmente.');
        }
        $tooLarge = new \RuntimeException('La descarga supera el tamaño máximo de 128 MB.');
        if ((int) $response->header('Content-Length') > UpdatePackage::MAX_BYTES) {
            throw $tooLarge;
        }
        $body = $response->toPsrResponse()->getBody();
        $output = fopen($target, 'xb');
        if (! $output) {
            throw new \RuntimeException('No se pudo guardar la descarga en la carpeta privada de actualizaciones.');
        }
        $bytes = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(1024 ** 2);
                $bytes += strlen($chunk);
                if ($bytes > UpdatePackage::MAX_BYTES) {
                    throw $tooLarge;
                }
                fwrite($output, $chunk);
            }
        } catch (\Throwable $error) {
            fclose($output);
            @unlink($target);
            throw $error;
        }
        fclose($output);
        @chmod($target, 0600);
    }

    protected function client(): PendingRequest
    {
        return Http::withHeaders(['User-Agent' => 'Solqubit/'.config('solqubit.version')])
            ->withOptions(['allow_redirects' => ['max' => 5, 'protocols' => ['https']]]);
    }
}
