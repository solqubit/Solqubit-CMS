<?php

namespace App\Console\Commands;

use App\Services\Requirements;
use Illuminate\Console\Command;

class RequirementsCommand extends Command
{
    protected $signature = 'solqubit:requirements';

    protected $description = 'Comprueba los requisitos del alojamiento';

    public function handle(Requirements $requirements): int
    {
        $this->table(['Requisito', 'Estado', 'Detalle'], array_map(fn ($check) => [$check['name'], $check['ok'] ? 'OK' : 'PENDIENTE', $check['detail']], $requirements->check()));

        return $requirements->passes() ? self::SUCCESS : self::FAILURE;
    }
}
