<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/vendor/autoload.php';

// No Laravel bootstrap, Dotenv load, database configuration or PDO is permitted.
final class FpCoreNoDatabase implements \Illuminate\Database\ConnectionResolverInterface
{
    public static int $attempts = 0;

    public function connection($name = null): never
    {
        self::$attempts++;
        throw new RuntimeException('F2.1 offline tests prohibit database connections.');
    }

    public function getDefaultConnection(): string
    {
        return 'forbidden';
    }

    public function setDefaultConnection($name): never
    {
        throw new RuntimeException('F2.1 offline database resolver is immutable.');
    }
}

\Illuminate\Database\Eloquent\Model::setConnectionResolver(new FpCoreNoDatabase);
$container = new \Illuminate\Container\Container;
$container->bind('db', static function (): never {
    FpCoreNoDatabase::$attempts++;
    throw new RuntimeException('F2.1 offline tests prohibit DB facade access.');
});
\Illuminate\Support\Facades\Facade::setFacadeApplication($container);

register_shutdown_function(static function (): void {
    if (FpCoreNoDatabase::$attempts !== 0) {
        throw new RuntimeException('F2.1 offline test attempted database access.');
    }
});
