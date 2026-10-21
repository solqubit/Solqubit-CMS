<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function createApplication()
    {
        $_ENV['VIEW_COMPILED_PATH'] = $_SERVER['VIEW_COMPILED_PATH'] = dirname(__DIR__).'/storage/framework/testing-views';
        $app = parent::createApplication();
        $driver = $app['config']->get('database.default');
        $database = $app['config']->get('database.connections.'.$driver.'.database');
        $safe = ($driver === 'sqlite' && $database === ':memory:') || ($driver === 'mysql' && is_string($database) && str_ends_with($database, '_test'));
        if (! $app->environment('testing') || ! $safe) {
            throw new \RuntimeException('Pruebas detenidas para proteger los datos: limpia la caché de configuración y usa SQLite :memory: o una base dedicada con sufijo _test.');
        }

        return $app;
    }
}
