<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class IsolationTest extends TestCase
{
    public function test_database_is_in_memory_before_any_migrations_or_user_writes(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(['sqlite'], array_keys(config('database.connections')));
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->assertSame('', DB::select('PRAGMA database_list')[0]->file);
        $this->assertFalse(app()->configurationIsCached());
        $this->assertSame(getenv('RO_CHARACTERIZATION_RUNTIME'), app()->environmentPath());
        $this->assertSame('array', config('cache.default'));
        $this->assertSame('array', config('session.driver'));
    }

    public function test_normal_database_connections_are_unavailable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DB::connection('mysql');
    }

    public function test_attaching_a_persistent_database_fails_before_execution(): void
    {
        $this->expectException(RuntimeException::class);
        DB::statement("ATTACH DATABASE 'forbidden.sqlite' AS forbidden");
    }

    public function test_unfaked_http_is_blocked(): void
    {
        $this->expectException(RuntimeException::class);
        Http::get('https://unfaked.example.test/never-network');
    }
}
