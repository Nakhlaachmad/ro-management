<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpReports;
use App\Application\FP\ReportColumns;
use App\Application\FP\VictoryAccess;
use App\Models\FpMembership;
use App\Models\PersonIdentity;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class GlobalFpReadAccessTest extends TestCase
{
    private function actor(string $role, bool $active = true): User
    {
        $user = new User(['is_active' => $active]);
        $user->setRelation('roles', collect([new Role(['name' => $role, 'guard_name' => 'web'])]));

        return $user;
    }

    public function test_four_roles_read_and_export_but_bm_cannot_mutate(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $actor = $this->actor($role);
            self::assertTrue(FpReports::allowed($actor, true), $role);
            foreach (VictoryAccess::VIEW as $permission) {
                self::assertTrue(FeatureAccess::allowed($actor, $permission), $role.':'.$permission);
            }
        }
        foreach (VictoryAccess::MUTATE as $permission) {
            self::assertFalse(FeatureAccess::allowed($this->actor('bm'), $permission));
        }
        self::assertFalse(FpReports::allowed(null, true));
        self::assertFalse(FpReports::allowed($this->actor('other'), true));
        self::assertFalse(FpReports::allowed($this->actor('hrd', false), true));
    }

    public function test_sensitive_projection_is_selected_lazily_and_preserves_strings(): void
    {
        config(['app.key' => 'base64:'.base64_encode(str_repeat('x', 32))]);
        $fp = new FpMembership;
        $fp->setRawAttributes(['id' => 1, 'full_name' => 'Synthetic FP', 'phone' => '+628123456789', 'address_ktp' => 'KTP address', 'address_current' => 'Current address'], true);
        $fp->setRelation('team', null)->setRelation('exit', null);
        $fp->setRawAttributes(array_merge($fp->getAttributes(), ['bank_account_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('0012345678')]), true);
        $identity = new PersonIdentity;
        $identity->setRawAttributes(['nik_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('0012345678901234')], true);
        $fp->setRelation('identity', $identity);
        self::assertSame(['0012345678901234', '0012345678', '+628123456789', 'KTP address', 'Current address'], ReportColumns::row($fp, ['nik', 'bank_account', 'phone', 'address_ktp', 'address']));
        $query = ReportColumns::prepare(FpReports::query([]), ['nik', 'bank_account']);
        self::assertStringContainsString('identity_id', $query->toSql());
        self::assertStringContainsString('bank_account_encrypted', $query->toSql());
        self::assertArrayHasKey('identity', $query->getEagerLoads());
        $fp->setRawAttributes(['id' => 2, 'full_name' => 'Historic', 'bank_account_encrypted' => 'not-valid-ciphertext'], true);
        $fp->setRelation('identity', null);
        self::assertSame(['Historic'], ReportColumns::row($fp, ['name']));
        self::assertSame([''], ReportColumns::row($fp, ['nik']));
        $query = ReportColumns::prepare(FpReports::query([]), ReportColumns::DEFAULTS);
        self::assertStringNotContainsString('encrypted', $query->toSql());
        self::assertArrayNotHasKey('identity', $query->getEagerLoads());
    }
}
