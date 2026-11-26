<?php

declare(strict_types=1);

namespace Tests\FpDashboard;

use App\Application\FP\FpDirectory;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class DirectoryQueryTest extends TestCase
{
    protected function setUp(): void
    {
        $container = new \Illuminate\Container\Container;
        $container->instance('config', new \Illuminate\Config\Repository(['fp' => ['organization_enabled' => false]]));
        \Illuminate\Container\Container::setInstance($container);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($container);
        $resolver = new ConnectionResolver(['offline' => new PostgresConnection(null)]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
    }

    public function test_all_preserves_null_legacy_states_without_assigning_nip(): void
    {
        $query = FpDirectory::members();
        self::assertSame('select * from "financial_planners"', $query->toSql());
        self::assertSame([], $query->getBindings());
    }

    public function test_active_is_exact_and_not_legacy_noninactive_inference(): void
    {
        $query = FpDirectory::members('active');
        self::assertSame(['ACTIVE'], $query->getBindings());
        self::assertStringNotContainsString('fps', $query->toSql());
    }

    public function test_all_exits_include_both_categories_and_preserve_unknown_exit_classification(): void
    {
        $query = FpDirectory::members('exited');
        self::assertSame(['EXITED'], $query->getBindings());
        self::assertStringNotContainsString('exists', $query->toSql());
    }

    public function test_normal_and_abnormal_require_exited_and_owned_exit_record(): void
    {
        foreach (['normal' => 'NORMAL', 'abnormal' => 'ABNORMAL'] as $scope => $type) {
            $query = FpDirectory::members($scope);
            self::assertSame(['EXITED', $type], $query->getBindings());
            self::assertStringContainsString('exists', $query->toSql());
            self::assertStringContainsString('"financial_planners"."id" = "fp_exits"."membership_id"', $query->toSql());
            self::assertStringNotContainsString('months', $query->toSql());
        }
    }

    public function test_unknown_directory_fails_closed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FpDirectory::members('legacy-active');
    }

    public function test_local_pages_cannot_be_autodiscovered_by_protected_legacy_panel(): void
    {
        self::assertFalse(\App\Filament\Pages\FP\Dashboard::isDiscovered());
        self::assertFalse(\App\Filament\Pages\FP\UserStatus::isDiscovered());
    }
}
