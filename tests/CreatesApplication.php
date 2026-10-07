<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_DATABASE') !== ':memory:'
            || getenv('RO_CHARACTERIZATION_RUNTIME') === false) {
            throw new \RuntimeException('Run tests through tests/bootstrap.php; isolation is mandatory.');
        }

        $app = require __DIR__.'/../bootstrap/app.php';
        // Laravel 10 only recognizes '/' as an absolute cache prefix by default.
        if (preg_match('/^[A-Za-z]:/', getenv('RO_CHARACTERIZATION_RUNTIME'), $match)) {
            $app->addAbsoluteCachePathPrefix($match[0]);
        }

        // Never load the repository .env (or its normal development credentials).
        $app->useEnvironmentPath(getenv('RO_CHARACTERIZATION_RUNTIME'));
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\RegisterProviders::class, function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections', [
                'sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            ]);
            $app['config']->set('cache.default', 'array');
            $app['config']->set('cache.stores', ['array' => ['driver' => 'array', 'serialize' => false]]);
            $app['config']->set('session.driver', 'array');
            $app['config']->set('queue.default', 'sync');
            $app['config']->set('mail.default', 'array');
            $app['config']->set('logging.default', 'null');
            $app['config']->set('services.iwacs_ro.enabled', false);
        });
        $app->beforeBootstrapping(\Illuminate\Foundation\Bootstrap\BootProviders::class, function ($app): void {
            \Tests\Support\Isolation::assertDatabase($app);
            \Illuminate\Support\Facades\Http::preventStrayRequests();
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
