<?php

declare(strict_types=1);

namespace Tests\VictoryAccess;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpReports;
use App\Application\FP\VictoryAccess;
use PHPUnit\Framework\TestCase;

final class RoleMatrixTest extends TestCase
{
    public static function actor(array $roles): \App\Models\User
    {
        $actor = new class extends \App\Models\User
        {
            public array $testRoles = [];

            public function hasRole($roles, ?string $guard = null): bool
            {
                return in_array($roles, $this->testRoles, true) && $guard === 'web';
            }

            public function hasPermissionTo($permission, $guardName = null): bool
            {
                return true;
            }
        };
        $actor->is_active = true;
        $actor->testRoles = $roles;

        return $actor;
    }

    protected function setUp(): void
    {
        $app = new \Illuminate\Container\Container;
        $app->instance('config', new \Illuminate\Config\Repository(['fp' => ['enabled' => true, 'core_schema_only' => false]]));
        \Illuminate\Container\Container::setInstance($app);
        \Illuminate\Support\Facades\Facade::setFacadeApplication($app);
    }

    public function test_four_roles_read_every_module_including_pii_and_private_documents(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            foreach (VictoryAccess::VIEW as $action) {
                self::assertTrue(VictoryAccess::allowed(self::actor([$role]), $action), $role.' '.$action);
            }
        }
    }

    public function test_full_operators_and_bm_mutation_veto_even_with_optional_permissions(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            foreach (VictoryAccess::MUTATE as $action) {
                self::assertSame($role !== 'bm', FeatureAccess::allowed(self::actor([$role]), $action), $role.' '.$action);
            }
        }
        self::assertTrue(FpReports::allowed(self::actor(['bm']), true));
        self::assertTrue(FpReports::allowed(self::actor(['bm'])));
    }

    public function test_only_admin_permission_management_and_unknown_scope_denied(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            self::assertSame($role === 'admin', VictoryAccess::allowed(self::actor([$role]), 'f3.users.permissions'));
            self::assertFalse(VictoryAccess::allowed(self::actor([$role]), 'legacy.user.delete'));
        }
        self::assertFalse(VictoryAccess::allowed(self::actor(['outsider']), 'f2.view'));
        self::assertTrue(VictoryAccess::allowed(self::actor(['bm', 'admin']), 'f2.approve'));
        $a = self::actor(['admin']);
        $a->is_active = false;
        self::assertFalse(VictoryAccess::allowed($a, 'f2.view'));
    }

    public function test_every_bm_operator_service_denies_before_any_database_access(): void
    {
        $actor = self::actor(['bm']);
        $engine = new \App\Application\FP\FpEngine;
        foreach ([
            fn () => $engine->updateSubmissionBiodata($actor, 0, []),
            fn () => $engine->verifySubmissionBiodata($actor, 0),
            fn () => $engine->verifySubmissionDocuments($actor, 0),
            fn () => $engine->approveFpSubmission($actor, 0, []),
            fn () => $engine->rejectFpSubmission($actor, 0, 'Synthetic'),
            fn () => $engine->exitFp($actor, 0, '2026-10-11', 'Synthetic'),
            fn () => $engine->assignTeamLeader($actor, 0, null),
            fn () => $engine->createTeam($actor, 'Synthetic', 'Synthetic'),
            fn () => $engine->saveTeamProfile($actor, 0, 'Synthetic', true),
            fn () => (new \App\Application\FP\RecruitmentPaths)->save($actor, null, []),
        ] as $work) {
            try {
                $work();
                self::fail('BM operator service allowed');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                self::assertTrue(true);
            }
        }
    }
}
