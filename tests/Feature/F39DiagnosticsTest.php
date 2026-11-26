<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\DocumentOperationDiagnostics;
use App\Filament\Pages\FP\DocumentOperationsStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;
use Tests\VictoryAccess\RoleMatrixTest;

final class F39DiagnosticsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.storage_adapter' => 'local', 'nextcloud.enabled' => false]);
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        Schema::create('financial_planners', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid');
            $table->string('full_name');
        });
        Schema::create('fp_document_operations', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->unsignedBigInteger('membership_id');
            $table->string('type');
            $table->uuid('revision_uuid');
            $table->integer('target_revision');
            $table->string('state');
            $table->string('storage_adapter');
            $table->text('private_path');
            $table->string('mime');
            $table->integer('bytes');
            $table->string('sha256');
            $table->string('error_code')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('lease_until')->nullable();
        });
        Schema::create('fp_document_amendments', function (Blueprint $table): void {
            $table->uuid('uuid')->primary();
            $table->uuid('operation_uuid');
            $table->unsignedBigInteger('membership_id');
            $table->string('type');
            $table->integer('revision');
            $table->string('storage_adapter');
            $table->text('private_path');
            $table->string('mime');
            $table->integer('bytes');
            $table->string('sha256');
            $table->timestamp('integrity_verified_at')->nullable();
        });
        Schema::create('fp_document_operation_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('operation_uuid');
            $table->string('action');
            $table->timestamp('occurred_at');
        });
    }

    private function fixture(array $changes = []): array
    {
        $membership = DB::table('financial_planners')->insertGetId([
            'uuid' => Uuid::uuid4()->toString(),
            'full_name' => 'SYNTHETIC_NAME_MUST_STAY_PRIVATE',
        ]);
        $operation = array_replace([
            'uuid' => Uuid::uuid4()->toString(), 'membership_id' => $membership,
            'type' => 'KTP', 'revision_uuid' => Uuid::uuid4()->toString(),
            'target_revision' => 1, 'state' => 'PENDING', 'storage_adapter' => 'nextcloud',
            'private_path' => 'SYNTHETIC_DAV_LOCATOR_MUST_STAY_PRIVATE',
            'mime' => 'image/jpeg', 'bytes' => 4096, 'sha256' => str_repeat('a', 64),
            'error_code' => null, 'verified_at' => null,
            'created_at' => '2026-11-18 01:00:00', 'lease_until' => null,
        ], $changes);
        DB::table('fp_document_operations')->insert($operation);

        return $operation;
    }

    private function canonical(array $operation, array $changes = []): void
    {
        DB::table('fp_document_amendments')->insert(array_replace([
            'uuid' => $operation['revision_uuid'], 'operation_uuid' => $operation['uuid'],
            'membership_id' => $operation['membership_id'], 'type' => $operation['type'],
            'revision' => $operation['target_revision'], 'storage_adapter' => $operation['storage_adapter'],
            'private_path' => $operation['private_path'], 'mime' => $operation['mime'],
            'bytes' => $operation['bytes'], 'sha256' => $operation['sha256'],
            'integrity_verified_at' => $operation['verified_at'],
        ], $changes));
    }

    public function test_only_active_admin_can_query_and_direct_page_calls_reauthorize_before_queries(): void
    {
        foreach ([null, 'hrd', 'ro', 'bm', 'inactive-admin'] as $role) {
            $actor = $role === null ? null : RoleMatrixTest::actor([$role === 'inactive-admin' ? 'admin' : $role]);
            if ($role === 'inactive-admin') {
                $actor->is_active = false;
            }
            auth()->forgetUser();
            if ($actor) {
                auth()->setUser($actor);
            }
            DB::enableQueryLog();
            DB::flushQueryLog();
            self::assertFalse(DocumentOperationsStatus::canAccess());
            foreach ([fn () => (new DocumentOperationDiagnostics)->snapshot($actor), fn () => (new DocumentOperationsStatus)->operations()] as $call) {
                try {
                    $call();
                    self::fail('Diagnostics accepted an unauthorized actor');
                } catch (HttpException $error) {
                    self::assertSame(403, $error->getStatusCode());
                }
            }
            self::assertSame([], DB::getQueryLog());
        }
    }

    public function test_projection_is_opaque_and_read_only_with_verified_size_from_matching_canonical_metadata(): void
    {
        $actor = RoleMatrixTest::actor(['admin']);
        auth()->setUser($actor);
        $operation = $this->fixture(['state' => 'COMMITTED', 'verified_at' => '2026-11-18 01:01:00']);
        $this->canonical($operation);
        DB::table('fp_document_operation_events')->insert([
            'operation_uuid' => $operation['uuid'], 'action' => 'TRANSITION', 'occurred_at' => '2026-11-18 01:02:00',
        ]);
        $before = DB::table('fp_document_operation_events')->count();
        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertTrue(DocumentOperationsStatus::canAccess());
        $snapshot = (new DocumentOperationsStatus)->operations();
        $row = $snapshot['rows']->items()[0];
        self::assertSame('AVAILABLE', $snapshot['status']);
        self::assertSame(4096, $row['expected_size']);
        self::assertSame(4096, $row['verified_size']);
        self::assertSame('VERIFIED', $row['integrity_state']);
        self::assertNull($row['last_reconciled_at']);
        self::assertTrue(Uuid::isValid($row['document_uuid']));
        self::assertSame([
            'operation_uuid', 'document_uuid', 'revision_uuid', 'state', 'integrity_state',
            'expected_size', 'verified_size', 'error_code', 'last_reconciled_at', 'stale', 'quarantined',
        ], array_keys($row));
        $serialized = json_encode($snapshot['rows']->items(), JSON_THROW_ON_ERROR);
        foreach (['SYNTHETIC_NAME', 'SYNTHETIC_DAV_LOCATOR', 'sha256', 'private_path', 'password', 'cookie', 'https://'] as $privateValue) {
            self::assertStringNotContainsString($privateValue, $serialized);
        }
        foreach (DB::getQueryLog() as $query) {
            self::assertMatchesRegularExpression('/^\s*(select|pragma)/i', $query['query']);
        }
        self::assertSame($before, DB::table('fp_document_operation_events')->count());
        self::assertSame('local', config('fp.storage_adapter'));
        self::assertFalse(config('nextcloud.enabled'));
        Http::assertNothingSent();
    }

    public function test_pending_or_inconsistent_records_never_fabricate_verified_size_or_reconciliation_time(): void
    {
        $actor = RoleMatrixTest::actor(['admin']);
        $pending = $this->fixture(['verified_at' => '2026-11-18 01:01:00']);
        $this->canonical($pending);
        $corrupt = $this->fixture(['state' => 'COMMITTED', 'verified_at' => '2026-11-18 01:01:00']);
        $this->canonical($corrupt, ['sha256' => str_repeat('b', 64)]);
        $rows = collect((new DocumentOperationDiagnostics)->snapshot($actor)['rows']->items())->keyBy('operation_uuid');
        self::assertNull($rows[$pending['uuid']]['verified_size']);
        self::assertSame('PENDING', $rows[$pending['uuid']]['integrity_state']);
        self::assertNull($rows[$corrupt['uuid']]['verified_size']);
        self::assertSame('WARNING', $rows[$corrupt['uuid']]['integrity_state']);
    }

    public function test_explicit_reconciliation_marker_and_stale_quarantine_states_are_displayed(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-18 02:00:00', 'UTC'));
        try {
            $old = $this->fixture(['state' => 'UPLOADING', 'lease_until' => '2026-11-18 01:59:00']);
            $fresh = $this->fixture(['state' => 'UPLOADING', 'lease_until' => '2026-11-18 02:01:00']);
            $quarantine = $this->fixture(['state' => 'QUARANTINED', 'error_code' => 'INTEGRITY_MISMATCH']);
            $unknownError = $this->fixture(['error_code' => 'SYNTHETIC_RAW_EXCEPTION_PRIVATE']);
            DB::table('fp_document_operation_events')->insert([
                ['operation_uuid' => $old['uuid'], 'action' => 'RECONCILED', 'occurred_at' => '2026-11-18 01:58:00'],
                ['operation_uuid' => $old['uuid'], 'action' => 'TRANSITION', 'occurred_at' => '2026-11-18 01:59:30'],
            ]);
            $rows = collect((new DocumentOperationDiagnostics)->snapshot(RoleMatrixTest::actor(['admin']))['rows']->items())->keyBy('operation_uuid');
            self::assertTrue($rows[$old['uuid']]['stale']);
            self::assertFalse($rows[$fresh['uuid']]['stale']);
            self::assertSame('2026-11-18 01:58:00 UTC', $rows[$old['uuid']]['last_reconciled_at']);
            self::assertTrue($rows[$quarantine['uuid']]['quarantined']);
            self::assertFalse($rows[$quarantine['uuid']]['stale']);
            self::assertSame('WARNING', $rows[$quarantine['uuid']]['integrity_state']);
            self::assertSame('UNCLASSIFIED', $rows[$unknownError['uuid']]['error_code']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_pagination_is_bounded_and_absent_schema_is_safe(): void
    {
        $actor = RoleMatrixTest::actor(['admin']);
        foreach (range(1, 27) as $number) {
            $this->fixture();
        }
        $service = new DocumentOperationDiagnostics;
        $first = $service->snapshot($actor, 1)['rows'];
        $second = $service->snapshot($actor, 2)['rows'];
        self::assertSame(25, $first->count());
        self::assertSame(2, $second->count());
        self::assertSame(27, $first->total());
        self::assertSame([], array_intersect(array_column($first->items(), 'operation_uuid'), array_column($second->items(), 'operation_uuid')));
        Schema::drop('fp_document_operation_events');
        $absent = $service->snapshot($actor);
        self::assertSame('NOT_INSTALLED', $absent['status']);
        self::assertSame([], $absent['rows']->items());
        Http::assertNothingSent();
    }

    public function test_invalid_pagination_is_rejected_and_page_keeps_no_operation_payload_in_public_state(): void
    {
        auth()->setUser(RoleMatrixTest::actor(['admin']));
        $page = new DocumentOperationsStatus;
        foreach ([0, 100001] as $number) {
            try {
                (new DocumentOperationDiagnostics)->snapshot(auth()->user(), $number);
                self::fail('Out-of-range pagination accepted');
            } catch (HttpException $error) {
                self::assertSame(422, $error->getStatusCode());
            }
        }
        $page->paginators = ['page' => 'invalid'];
        try {
            $page->operations();
            self::fail('Invalid page accepted');
        } catch (HttpException $error) {
            self::assertSame(422, $error->getStatusCode());
        }
        $public = \Livewire\Drawer\Utils::getPublicPropertiesDefinedOnSubclass($page);
        self::assertArrayNotHasKey('rows', $public);
        self::assertArrayNotHasKey('operations', $public);
        self::assertArrayNotHasKey('private_path', $public);
        self::assertArrayNotHasKey('password', $public);
    }
}
