<?php

namespace App\Services;

/** Accepts only packages identical to a release of the trusted repository, unless the server allows otherwise. */
class PackageOrigin
{
    public function __construct(private GitHubReleases $releases) {}

    /** @return 'github'|'unverified' */
    public function verify(string $zip, string $version): string
    {
        try {
            $repository = $this->releases->repository();
            if ($repository === null) {
                throw new \RuntimeException('Este sitio no tiene un repositorio de actualizaciones configurado. Para instalar un ZIP sin verificar, el responsable del servidor debe añadir SOLQUBIT_ALLOW_UNVERIFIED=true al archivo .env.');
            }
            try {
                $expected = $this->releases->checksum($version);
            } catch (GitHubUnavailable) {
                throw new \RuntimeException('No se pudo contactar con GitHub para comprobar el origen del ZIP. Inténtalo más tarde. Si tu hosting bloquea las conexiones externas, el responsable del servidor puede permitir ZIP sin verificar con SOLQUBIT_ALLOW_UNVERIFIED=true en el archivo .env.');
            }
            if ($expected === null || ! hash_equals($expected, (string) hash_file('sha256', $zip))) {
                throw new \RuntimeException('Este ZIP no coincide con la versión '.$version.' publicada en github.com/'.$repository.'. Descárgalo de nuevo desde la release oficial.');
            }

            return 'github';
        } catch (\RuntimeException $error) {
            if (config('solqubit.allow_unverified')) {
                return 'unverified';
            }
            throw $error;
        }
    }
}
