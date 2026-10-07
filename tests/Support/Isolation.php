<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;

final class Isolation
{
    public static function assertDatabase(Application $app): void
    {
        if (! $app->environment('testing') || $app['config']->get('database.default') !== 'sqlite'
            || array_keys($app['config']->get('database.connections')) !== ['sqlite']) {
            throw new RuntimeException('Non-test database configuration rejected.');
        }
        $connection = $app['db']->connection();
        if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
            throw new RuntimeException('Only in-memory SQLite is permitted.');
        }
        $databases = $connection->select('PRAGMA database_list');
        if (count($databases) !== 1 || $databases[0]->name !== 'main' || $databases[0]->file !== '') {
            throw new RuntimeException('SQLite uses persistent/attached storage; refusing tests.');
        }
        $connection->beforeExecuting(function ($sql, $bindings, $connection) use ($app): void {
            if ($app['config']->get('database.default') !== 'sqlite'
                || array_keys($app['config']->get('database.connections')) !== ['sqlite']
                || $connection->getDatabaseName() !== ':memory:'
                || preg_match('/\b(ATTACH|VACUUM\s+INTO)\b/i', $sql)) {
                throw new RuntimeException('Database isolation changed during test.');
            }
        });
    }
}
