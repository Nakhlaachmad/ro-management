<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use Tests\TestCase;

final class QueueDeploymentTest extends TestCase
{
    public function test_operator_template_uses_the_database_queue(): void
    {
        $template = (string) file_get_contents(base_path('.env.example'));
        self::assertStringContainsString('QUEUE_CONNECTION=database', $template);
    }

    public function test_dokploy_runs_a_worker_with_shared_private_storage(): void
    {
        $compose = (string) file_get_contents(base_path('docker-compose.dokploy.yml'));
        self::assertStringContainsString('queue:work', $compose);
        self::assertStringContainsString('QUEUE_CONNECTION: database', $compose);
        self::assertSame(2, substr_count($compose, 'ro_storage:/var/www/html/storage'));
    }

    public function test_database_queue_retry_after_exceeds_the_publication_timeout(): void
    {
        self::assertGreaterThanOrEqual(180, (int) config('queue.connections.database.retry_after'));
    }
}
