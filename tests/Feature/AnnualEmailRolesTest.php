<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\VictoryUsers;
use App\Domain\FP\AnnualNip;
use App\Http\Requests\FpApplicationRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

final class AnnualEmailRolesTest extends TestCase
{
    public function test_nip_year_timezone_and_boundaries(): void
    {
        self::assertSame(261001, AnnualNip::number(2026, 1001));
        self::assertSame(261002, AnnualNip::number(2026, 1002));
        self::assertSame(261999, AnnualNip::number(2026, 1999));
        self::assertSame(262000, AnnualNip::number(2026, 2000));
        self::assertSame(271001, AnnualNip::number(2027, 1001));
        self::assertSame(269999, AnnualNip::number(2026, 9999));
        self::assertSame(2026, AnnualNip::year(new \DateTimeImmutable('2026-12-31 16:59:59+00:00')));
        self::assertSame(2027, AnnualNip::year(new \DateTimeImmutable('2026-12-31 17:00:00+00:00')));
        $this->expectException(\InvalidArgumentException::class);
        AnnualNip::number(2026, 10000);
    }

    public function test_new_email_required_and_history_nullable(): void
    {
        config(['fp.annual_nip_email_enabled' => true]);
        $request = FpApplicationRequest::create('/fp-onboarding', 'POST', ['email' => 'UPPER@EXAMPLE.TEST']);
        $method = new \ReflectionMethod($request, 'prepareForValidation');
        $method->invoke($request);
        self::assertSame('upper@example.test', $request->input('email'));
        $rules = ['email' => $request->rules()['email']];
        self::assertTrue(Validator::make(['email' => $request->input('email')], $rules)->passes());
        self::assertTrue(Validator::make([], $rules)->fails());
        self::assertTrue(Validator::make(['email' => 'invalid'], $rules)->fails());
        self::assertSame('Email wajib diisi.', $request->messages()['email.required']);
        self::assertTrue(Validator::make(['email' => null], ['email' => \App\Domain\FP\Biodata::rules()['email']])->passes());
    }

    public function test_feature_defaults_preserve_existing_schema(): void
    {
        config(['fp.annual_nip_email_enabled' => false]);
        self::assertSame(['exclude'], (new FpApplicationRequest)->rules()['email']);
        self::assertSame('exclude', \App\Domain\FP\Biodata::rules()['email']);
    }

    public function test_role_allowlist_and_new_account_panel_scope(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.user_roles_enabled' => true]);
        foreach (['admin' => ['admin', 'hrd', 'ro', 'bm'], 'hrd' => ['hrd', 'ro', 'bm'], 'ro' => ['hrd', 'ro', 'bm'], 'bm' => []] as $role => $expected) {
            $u = new class extends \App\Models\User
            {
                public string $role;

                public function hasRole($role, ?string $guard = null): bool
                {
                    return $role === $this->role;
                }

                public function hasAnyRole(...$roles): bool
                {
                    return true;
                }
            };
            $u->role = $role;
            $u->is_active = true;
            $u->setAttribute('fp_app_managed', true);
            self::assertSame($expected, array_keys(VictoryUsers::creationRoles($u)));
            self::assertTrue($u->canAccessPanel(\Filament\Panel::make()->id('fp-dev')));
            self::assertFalse($u->canAccessPanel(\Filament\Panel::make()->id('admin')));
            $u->setAttribute('fp_app_managed', false);
            self::assertTrue($u->canAccessPanel(\Filament\Panel::make()->id('admin')));
        }
    }

    public function test_review_refresh_enables_approval_without_reload(): void
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        $actor = new class extends \App\Models\User
        {
            public function hasRole($role, ?string $guard = null): bool
            {
                return $role === 'admin';
            }
        };
        $actor->is_active = true;
        auth()->setUser($actor);
        $record = \Mockery::mock(\App\Models\FpSubmission::class)->makePartial();
        $record->setRawAttributes(['id' => 1, 'status' => 'PENDING', 'biodata_revision' => 1, 'biodata_verified_revision' => null, 'document_revision' => 1, 'documents_verified_revision' => null]);
        $record->shouldReceive('refresh')->once()->andReturnUsing(function () use ($record) {
            $record->setAttribute('biodata_verified_revision', 1);
            $record->setAttribute('documents_verified_revision', 1);

            return $record;
        });
        $page = new \App\Filament\Resources\FpSubmissionResource\Pages\ViewFpSubmission;
        $page->record = $record;
        $method = new \ReflectionMethod($page, 'getHeaderActions');
        $actions = $method->invoke($page);
        $byName = [];
        foreach ($actions as $action) {
            $byName[$action->getName()] = $action;
        }
        self::assertTrue($byName['approve']->isDisabled());
        $byName['verifyDocuments']->callAfter();
        self::assertFalse($byName['approve']->isDisabled());
    }
}
