<?php

namespace App\Services;

class HostingLayout
{
    public const DIRECTORY = 'solqubit-core';

    public const DENY_RULES = "Options -Indexes\nRequire all denied\n";

    public static function isProtected(string $core, string $public): bool
    {
        $core = realpath($core);
        $public = realpath($public);
        if (! $core || ! $public || $core === $public) {
            return false;
        }
        if (! str_starts_with($core.DIRECTORY_SEPARATOR, $public.DIRECTORY_SEPARATOR)) {
            return true;
        }
        if ($core !== $public.DIRECTORY_SEPARATOR.self::DIRECTORY || is_link($public.'/'.self::DIRECTORY)) {
            return false;
        }
        $record = $core.'/bootstrap/hosting-protection.json';
        if (! is_file($record) || ! is_file($core.'/.htaccess') || file_get_contents($core.'/.htaccess') !== self::DENY_RULES) {
            return false;
        }
        $verified = json_decode(file_get_contents($record), true);

        return is_array($verified) && ($verified['core'] ?? null) === $core
            && ($verified['public'] ?? null) === $public && ($verified['layout'] ?? null) === 'domain-contained-v1';
    }

    /** Verify harmless files before extracting any configuration or application code. */
    public static function verifyWebProtection(string $core, string $public, array $server): void
    {
        if (realpath($core) !== realpath($public).'/'.self::DIRECTORY || is_link($core)) {
            throw new \RuntimeException('La carpeta del CMS debe estar dentro de este dominio.');
        }
        $name = 'solqubit-check-'.bin2hex(random_bytes(12));
        $content = bin2hex(random_bytes(32));
        $probe = $core.'/'.$name;
        $control = $public.'/'.$name.'.txt';
        if (! mkdir($probe, 0755)) {
            throw new \RuntimeException('No se pudo comprobar la protección de los archivos. Revisa los permisos del sitio.');
        }
        $files = [$control => $content, $probe.'/asset.txt' => $content, $probe.'/asset.css' => $content, $probe.'/index.php' => '<?php echo '.var_export($content, true).';'];
        try {
            foreach ($files as $file => $value) {
                if (file_put_contents($file, $value) !== strlen($value)) {
                    throw new \RuntimeException('No se pudieron crear los archivos de comprobación.');
                }
                chmod($file, 0644);
            }
            // Probe every port that answers for this domain (e.g. nginx on 443 and Apache on 8443 in
            // HestiaCP): a front server that serves static files itself must also hide the engine.
            $reachable = [];
            foreach (self::request($name.'.txt', $server) as $port => [$status, $body]) {
                if ($status === 200 && hash_equals($content, $body)) {
                    $reachable[] = $port;
                }
            }
            if (! $reachable) {
                throw new \RuntimeException('El servidor no pudo comprobar su propia dirección. Revisa que el dominio y HTTPS funcionen en este alojamiento y vuelve a intentar.');
            }
            foreach (['asset.txt', 'asset.css', 'index.php'] as $file) {
                foreach (self::request(self::DIRECTORY.'/'.$name.'/'.$file, $server, $reachable) as [$status]) {
                    if (! in_array($status, [403, 404], true)) {
                        throw new \RuntimeException('La carpeta solqubit-core todavía es accesible desde la web. En Apache conserva el archivo .htaccess del paquete. En Nginx aplica la regla de protección indicada abajo y vuelve a intentar.');
                    }
                }
            }
        } finally {
            foreach (array_keys($files) as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($probe);
        }
    }

    /**
     * Folders outside the web root where the engine can live without any web server rule:
     * HestiaCP's per-domain private folder first, then the folder that holds the site.
     *
     * @return list<string>
     */
    public static function privateLocations(string $public): array
    {
        $public = realpath($public);
        $parent = $public ? dirname($public) : '';
        if (! $public || $parent === '' || $parent === $public || $parent === DIRECTORY_SEPARATOR) {
            return [];
        }
        $candidates = [];
        if (is_dir($parent.'/private') && ! is_link($parent.'/private')) {
            $candidates[] = $parent.'/private/'.self::DIRECTORY;
        }
        // Never use a parent that is itself a web root, such as public_html holding an addon domain.
        $webRootNames = ['public_html', 'www', 'html', 'httpdocs', 'wwwroot', 'web', 'public'];
        $siteFiles = array_merge(glob($parent.'/index.*') ?: [], array_filter([$parent.'/.htaccess', $parent.'/wp-config.php'], 'file_exists'));
        if ($siteFiles === [] && ! in_array(strtolower(basename($parent)), $webRootNames, true)) {
            $candidates[] = $parent.'/'.self::DIRECTORY.'-'.(preg_replace('/[^a-zA-Z0-9._-]/', '-', basename($public)) ?: 'site');
        }

        return array_values(array_filter($candidates, static fn (string $candidate): bool => self::withinOpenBasedir(dirname($candidate))
            && ! file_exists($candidate) && is_dir(dirname($candidate)) && is_writable(dirname($candidate))));
    }

    /** True when PHP's open_basedir (used by HestiaCP and some cPanel setups) allows the path. */
    public static function withinOpenBasedir(string $path, ?string $restriction = null): bool
    {
        $restriction ??= (string) ini_get('open_basedir');
        if ($restriction === '') {
            return true;
        }
        $path = rtrim(str_replace('\\', '/', $path), '/').'/';
        foreach (explode(PATH_SEPARATOR, $restriction) as $allowed) {
            $allowed = rtrim(str_replace('\\', '/', trim($allowed)), '/').'/';
            if ($allowed !== '/' && $allowed !== './' && str_starts_with($path, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Connect only to the web server that is handling this request; never follow redirects.
     * The connection is pinned to SERVER_ADDR, so certificate checks are skipped: a new panel
     * site may still use a self-signed or origin-only certificate.
     *
     * @param  list<int>|null  $ports  Only these ports; by default the public port first, then SERVER_PORT.
     * @return array<int, array{0: int, 1: string}> Status and body for every port that answered.
     */
    private static function request(string $relative, array $server, ?array $ports = null): array
    {
        $scheme = ! empty($server['HTTPS']) && $server['HTTPS'] !== 'off' ? 'https' : 'http';
        $authority = $server['HTTP_HOST'] ?? '';
        if (! is_string($authority) || ! preg_match('/^(?:[a-z0-9.-]+|\[[a-f0-9:]+\])(?::[0-9]{1,5})?$/iD', $authority)) {
            throw new \RuntimeException('No se pudo identificar la dirección de este dominio.');
        }
        $url = parse_url($scheme.'://'.$authority);
        $address = $server['SERVER_ADDR'] ?? '';
        $port = filter_var($server['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if (! $url || ! filter_var($address, FILTER_VALIDATE_IP) || ! $port || ($url['port'] ?? 1) > 65535) {
            throw new \RuntimeException('No se pudo identificar el servidor para comprobar la protección.');
        }
        if (! function_exists('curl_init') || ! function_exists('curl_exec')) {
            throw new \RuntimeException('PHP no puede hacer peticiones HTTP en este alojamiento porque curl está desactivado.');
        }
        $script = str_replace('\\', '/', $server['SCRIPT_NAME'] ?? '/install-core.php');
        $base = rtrim(str_replace('%2F', '/', rawurlencode(dirname($script))), '/.');
        $endpoint = $scheme.'://'.$authority.$base.'/'.$relative;
        $connectAddress = str_contains($address, ':') ? '['.$address.']' : $address;
        $standardPort = $scheme === 'https' ? 443 : 80;
        $origin = $url['host'].':'.($url['port'] ?? $standardPort);
        $results = [];
        // Apache can report the external Host port behind a port mapping or proxy.
        foreach ($ports ?? array_unique([$url['port'] ?? $standardPort, $standardPort, $port]) as $localPort) {
            $curl = curl_init($endpoint);
            $body = '';
            curl_setopt_array($curl, [
                CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECT_TO => [$origin.':'.$connectAddress.':'.$localPort],
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 6,
                CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0, CURLOPT_USERAGENT => 'Solqubit installation check',
                CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                    if (strlen($body) + strlen($chunk) > 65536) {
                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($curl);
            $status = $ok === false ? 0 : (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if ($status !== 0) {
                $results[$localPort] = [$status, $body];
            }
        }

        return $results;
    }
}
