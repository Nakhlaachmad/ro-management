<?php

declare(strict_types=1);

namespace Tests\FpCore;

use App\Domain\FP\ExitRule;
use App\Domain\FP\PublicReceipt;
use App\Infrastructure\FP\NextcloudStorage;
use App\Infrastructure\FP\SecureImage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IntegratedOfflineTest extends TestCase
{
    public static function exits(): array
    {
        return [['2026-01-01', '2026-03-01', 59, 'ABNORMAL'], ['2026-01-01', '2026-03-02', 60, 'NORMAL'], ['2026-01-01', '2026-03-03', 61, 'NORMAL'], ['2024-02-01', '2024-04-01', 60, 'NORMAL']];
    }

    #[DataProvider('exits')]
    public function test_calendar_exit_boundaries(string $reference, string $exit, int $days, string $type): void
    {
        self::assertSame(['duration_days' => $days, 'type' => $type], ExitRule::classify($reference, $exit));
    }

    public function test_reverse_exit_date_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExitRule::classify('2026-01-02', '2026-01-01');
    }

    public function test_public_receipt_is_a_fixed_allowlist_without_pii_or_nip(): void
    {
        $receipt = PublicReceipt::make('b86fda15-3bc1-4791-a77a-628c09d63563');
        self::assertSame(['receipt', 'message'], array_keys($receipt));
        self::assertStringNotContainsString('nip', json_encode($receipt));
    }

    public function test_spoofed_binary_is_rejected_even_with_a_jpeg_prefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SecureImage)->sanitize("\xff\xd8\xff".str_repeat('<script>', 100));
    }

    public function test_backend_rejects_pdf_until_safe_pdf_processing_exists(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new SecureImage)->sanitize('%PDF-1.7'.str_repeat('x', 100));
    }

    public function test_nextcloud_deletion_is_idempotent_using_fake_http(): void
    {
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(['*' => \Illuminate\Http\Client\Factory::response('', 404)]);
        $storage = new NextcloudStorage($http, 'https://storage.invalid/dav', 'fake-user', 'fake-password');
        $storage->delete('fp/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002.jpg');
        $http->assertSentCount(1);
        self::assertSame('nextcloud', $storage->adapter());
    }

    public function test_nextcloud_put_and_read_use_fake_webdav_without_real_http(): void
    {
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $http->fake(fn ($request) => \Illuminate\Http\Client\Factory::response($request->method() === 'GET' ? 'synthetic bytes' : '', 200));
        $store = new NextcloudStorage($http, 'https://storage.invalid/dav', 'fake-user', 'fake-password');
        $path = 'fp/00000000-0000-4000-8000-000000000001/00000000-0000-4000-8000-000000000002.jpg';
        $store->put($path, 'synthetic bytes');
        self::assertSame('synthetic bytes', $store->read($path));
        $http->assertSentCount(5);
        $http->assertSent(fn ($request) => $request->method() === 'PUT' && $request->body() === 'synthetic bytes');
    }

    public function test_inactive_user_is_denied_even_when_feature_configuration_is_enabled(): void
    {
        $app = \Illuminate\Container\Container::getInstance();
        $app->instance('config', new \Illuminate\Config\Repository(['fp' => ['enabled' => true, 'core_schema_only' => false]]));
        $user = new \App\Models\User;
        $user->is_active = false;
        self::assertFalse(\App\Application\FP\FeatureAccess::allowed($user, 'f2.approve'));
        $app->instance('config', new \Illuminate\Config\Repository(['fp' => ['enabled' => false, 'core_schema_only' => true]]));
        self::assertSame(0, \FpCoreNoDatabase::$attempts);
    }

    public function test_nextcloud_rejects_traversal_before_any_http(): void
    {
        $http = new \Illuminate\Http\Client\Factory;
        $http->preventStrayRequests();
        $storage = new NextcloudStorage($http, 'https://storage.invalid/dav', 'fake', 'fake');
        try {
            $storage->put('../public/secret.jpg', 'fake bytes');
            self::fail('Traversal accepted');
        } catch (\RuntimeException) {
            $http->assertNothingSent();
        }
    }

    public function test_feature_disabled_denies_authenticated_user_without_querying_permissions(): void
    {
        $app = \Illuminate\Container\Container::getInstance();
        $app->instance('config', new \Illuminate\Config\Repository(['fp' => ['enabled' => false, 'core_schema_only' => true]]));
        $user = new \App\Models\User;
        $user->is_active = true;
        self::assertFalse(\App\Application\FP\FeatureAccess::allowed($user, 'f2.approve'));
        self::assertFalse((new \App\Policies\FpPolicy)->viewAny($user));
        self::assertSame(0, \FpCoreNoDatabase::$attempts);
    }

    public function test_filament_and_http_classes_load_against_installed_framework(): void
    {
        foreach ([\App\Filament\Resources\FpSubmissionResource::class, \App\Filament\Resources\FpMembershipResource::class, \App\Filament\Resources\FpTeamResource::class, \App\Filament\Resources\FpSubmissionResource\Pages\ListFpSubmissions::class, \App\Filament\Resources\FpMembershipResource\Pages\ViewFpMembership::class, \App\Http\Requests\FpApplicationRequest::class, \App\Jobs\FP\DeleteRejectedDocuments::class] as $class) {
            self::assertTrue(class_exists($class), $class);
        }
    }

    public function test_three_evidence_migrations_declare_cross_phase_checks_without_running_them(): void
    {
        $root = dirname(__DIR__, 2);
        $m = [];
        foreach (glob($root.'/database/migrations/2026_10_09_00000[6-8]_*.php') as $path) {
            $migration = require $path;
            $m[] = $migration::SQL;
        }
        self::assertCount(3, $m);
        $sql = implode("\n", $m);
        foreach (['three READY linked documents', 'immutable exit record', 'EXITED requires matching exit', 'append-only audit', 'approval audit required', 'document change audit required', 'correction audit required'] as $guard) {
            self::assertStringContainsString($guard, $sql);
        }
        self::assertStringContainsString('f2_ready_document_tr AFTER INSERT OR UPDATE', $sql);
        self::assertStringContainsString('f2_audit_document_final_tr AFTER INSERT OR UPDATE', $sql);
        self::assertStringContainsString('DEFERRABLE INITIALLY DEFERRED', $sql);
        self::assertStringNotContainsString('GRANT ', $sql);
        self::assertStringNotContainsString('DROP TABLE', $sql);
    }

    public function test_uncertain_persistence_does_not_delete_potentially_committed_uploads(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/FP/OnboardingController.php');
        preg_match('/catch \(\\\\Throwable\) \{(.*?)\n        \}/s', $controller, $uncertain);
        self::assertNotEmpty($uncertain);
        self::assertStringNotContainsString('discard', $uncertain[1]);
        self::assertStringContainsString('request_key', $uncertain[1]);
        $engine = file_get_contents($root.'/app/Application/FP/FpEngine.php');
        self::assertStringContainsString('FpPersistenceException', $engine);
        self::assertStringNotContainsString('no partial transaction was saved', $engine);
    }

    public function test_provider_routes_and_jobs_do_not_change_legacy_or_enable_feature(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/routes/fp.php');
        self::assertStringContainsString('FpReportAuthenticate::class', $routes);
        self::assertTrue(is_subclass_of(\App\Http\Middleware\FpReportAuthenticate::class, \Illuminate\Auth\Middleware\Authenticate::class));
        self::assertStringContainsString('FpEnabled::class', $routes);
        self::assertStringContainsString("env('APP_ENV') === 'local' && env('FP_ENABLED', false)", file_get_contents($root.'/config/fp.php'));
        $job = file_get_contents($root.'/app/Jobs/FP/DeleteRejectedDocuments.php');
        self::assertStringContainsString('afterCommit()', $job);
        self::assertStringContainsString('DOCUMENT_CHANGED', file_get_contents($root.'/app/Application/FP/FpEngine.php'));
    }
}
