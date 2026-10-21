<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Local copy of DB-IP's free "IP to Country Lite" list (CC BY 4.0), turned into a compact sorted file:
 * a header, then fixed-size records (range start + country code) for IPv4 and IPv6, read by binary search.
 */
class CountryDatabase
{
    public const SOURCE_URL = 'https://download.db-ip.com/free/dbip-country-lite-%s.csv.gz';

    public const ATTRIBUTION_URL = 'https://db-ip.com';

    private const MAGIC = 'SQGEO1';

    private const HEADER_LENGTH = 21;

    /**
     * @var resource|null
     */
    private $handle = null;

    /**
     * @var array{month: string, v4: int, v6: int}|null
     */
    private ?array $header = null;

    public function path(): string
    {
        return config('solqubit.country_database_file');
    }

    public function installed(): bool
    {
        return $this->month() !== null;
    }

    /**
     * Month of the installed list (YYYY-MM), or null when none is installed.
     */
    public function month(): ?string
    {
        return $this->readHeader()['month'] ?? null;
    }

    /**
     * Two-letter country of an IP address, or null when it is unknown or no list is installed.
     */
    public function country(string $ip): ?string
    {
        $binary = @inet_pton($ip);
        $header = $this->readHeader();
        if ($binary === false || $header === null) {
            return null;
        }
        $isV4 = strlen($binary) === 4;
        $size = strlen($binary) + 2;
        $count = $isV4 ? $header['v4'] : $header['v6'];
        $offset = self::HEADER_LENGTH + ($isV4 ? 0 : $header['v4'] * 6);
        $low = 0;
        $high = $count - 1;
        $found = null;
        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);
            fseek($this->handle, $offset + $middle * $size);
            $record = (string) fread($this->handle, $size);
            if (strcmp(substr($record, 0, $size - 2), $binary) <= 0) {
                $found = substr($record, $size - 2);
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found !== null && preg_match('/^[A-Z]{2}$/', $found) && $found !== 'ZZ' ? $found : null;
    }

    /**
     * Download this month's list (or last month's, published on the 1st) and install it.
     */
    public function download(): string
    {
        if (! function_exists('gzopen')) {
            throw new RuntimeException('El alojamiento no tiene activada la extensión zlib, necesaria para descomprimir la lista de países.');
        }
        foreach ([now()->format('Y-m'), now()->subMonthNoOverflow()->format('Y-m')] as $month) {
            $response = Http::timeout(90)->get(sprintf(self::SOURCE_URL, $month));
            if ($response->successful()) {
                $archive = $this->temporaryPath('download');
                try {
                    file_put_contents($archive, $response->body());
                    $this->install($archive, $month);
                } finally {
                    @unlink($archive);
                }

                return $month;
            }
        }

        throw new RuntimeException('No se pudo descargar la lista de países de DB-IP. Inténtalo de nuevo más tarde.');
    }

    /**
     * Convert a DB-IP country CSV (optionally gzip-compressed) into the compact file.
     */
    public function install(string $csvPath, string $month): void
    {
        @set_time_limit(300);
        $input = gzopen($csvPath, 'rb');
        if ($input === false) {
            throw new RuntimeException('No se pudo abrir la lista de países descargada.');
        }
        $partPaths = ['v4' => $this->temporaryPath('v4'), 'v6' => $this->temporaryPath('v6')];
        $parts = ['v4' => fopen($partPaths['v4'], 'w+b'), 'v6' => fopen($partPaths['v6'], 'w+b')];
        $counts = ['v4' => 0, 'v6' => 0];
        $pending = ['v4' => null, 'v6' => null];
        $buffers = ['v4' => '', 'v6' => ''];
        $emit = function (string $family, ?array $range) use (&$parts, &$counts, &$buffers): void {
            if ($range !== null) {
                $buffers[$family] .= $range['start'].$range['country'];
                $counts[$family]++;
            }
            if (strlen($buffers[$family]) >= 262144 || $range === null) {
                fwrite($parts[$family], $buffers[$family]);
                $buffers[$family] = '';
            }
        };
        try {
            while (($line = gzgets($input)) !== false) {
                $fields = explode(',', trim($line));
                if (count($fields) < 3) {
                    continue;
                }
                $start = @inet_pton(trim($fields[0], '"'));
                $end = @inet_pton(trim($fields[1], '"'));
                $country = strtoupper(trim($fields[2], '"'));
                if ($start === false || $end === false || strlen($start) !== strlen($end) || ! preg_match('/^[A-Z]{2}$/', $country)) {
                    continue;
                }
                $family = strlen($start) === 4 ? 'v4' : 'v6';
                $last = $pending[$family];
                if ($last !== null && strcmp($start, $last['end']) <= 0) {
                    continue;
                }
                $expected = $last !== null ? $this->increment($last['end']) : str_repeat("\0", strlen($start));
                if ($expected !== null && strcmp($start, $expected) > 0) {
                    $emit($family, $last);
                    $last = ['start' => $expected, 'end' => $expected, 'country' => 'ZZ'];
                }
                if ($last !== null && $last['country'] === $country) {
                    $pending[$family] = ['start' => $last['start'], 'end' => $end, 'country' => $country];

                    continue;
                }
                $emit($family, $last);
                $pending[$family] = ['start' => $start, 'end' => $end, 'country' => $country];
            }
            foreach (['v4', 'v6'] as $family) {
                $last = $pending[$family];
                $emit($family, $last);
                $after = $last !== null && $last['country'] !== 'ZZ' ? $this->increment($last['end']) : null;
                if ($after !== null) {
                    $emit($family, ['start' => $after, 'end' => $after, 'country' => 'ZZ']);
                }
                $emit($family, null);
            }
            if ($counts['v4'] + $counts['v6'] === 0) {
                throw new RuntimeException('La lista de países descargada está vacía o no tiene el formato esperado.');
            }
            $this->writeFile($parts, $counts, $month);
        } finally {
            gzclose($input);
            foreach ($parts as $family => $part) {
                fclose($part);
                @unlink($partPaths[$family]);
            }
        }
    }

    /**
     * A temporary file next to the list: shared hostings often restrict the system temporary folder.
     */
    private function temporaryPath(string $purpose): string
    {
        $directory = dirname($this->path());
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se pudo crear la carpeta de la lista de países.');
        }

        return $directory.'/.'.$purpose.'-'.bin2hex(random_bytes(6)).'.tmp';
    }

    /**
     * @param  array{v4: resource, v6: resource}  $parts
     * @param  array{v4: int, v6: int}  $counts
     */
    private function writeFile(array $parts, array $counts, string $month): void
    {
        $temporary = $this->temporaryPath('list');
        $output = fopen($temporary, 'wb');
        fwrite($output, self::MAGIC.str_pad(substr($month, 0, 7), 7).pack('NN', $counts['v4'], $counts['v6']));
        foreach (['v4', 'v6'] as $family) {
            rewind($parts[$family]);
            stream_copy_to_stream($parts[$family], $output);
        }
        fclose($output);
        $this->close();
        if (! rename($temporary, $this->path())) {
            @unlink($temporary);
            throw new RuntimeException('No se pudo guardar la lista de países.');
        }
    }

    /**
     * The address right after the given one, or null when it is the last address of its family.
     */
    private function increment(string $binary): ?string
    {
        for ($index = strlen($binary) - 1; $index >= 0; $index--) {
            $byte = ord($binary[$index]);
            if ($byte < 255) {
                $binary[$index] = chr($byte + 1);

                return $binary;
            }
            $binary[$index] = "\0";
        }

        return null;
    }

    /**
     * @return array{month: string, v4: int, v6: int}|null
     */
    private function readHeader(): ?array
    {
        if ($this->header !== null) {
            return $this->header;
        }
        if (! is_file($this->path()) || ($handle = @fopen($this->path(), 'rb')) === false) {
            return null;
        }
        $raw = (string) fread($handle, self::HEADER_LENGTH);
        if (strlen($raw) !== self::HEADER_LENGTH || ! str_starts_with($raw, self::MAGIC)) {
            fclose($handle);

            return null;
        }
        ['v4' => $v4, 'v6' => $v6] = unpack('Nv4/Nv6', substr($raw, 13));
        $this->handle = $handle;

        return $this->header = ['month' => trim(substr($raw, 6, 7)), 'v4' => $v4, 'v6' => $v6];
    }

    private function close(): void
    {
        if ($this->handle !== null) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->header = null;
    }
}
