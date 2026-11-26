<?php

declare(strict_types=1);

namespace Tests\FpStorage;

use App\Application\FP\DocumentOperations;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Throwable;

final class CommitBoundaryPDO extends PDO
{
    public array $calls = [];

    public ?Throwable $commitFailure = null;

    public ?Throwable $beginFailure = null;

    public ?Throwable $cleanupFailure = null;

    public string $cleanupMode = 'rollback';

    public bool $initiallyActive = false;

    private bool $commitAttempted = false;

    public function __construct() {}

    public function inTransaction(): bool
    {
        $this->calls[] = 'inTransaction';
        if (! $this->commitAttempted) {
            return $this->initiallyActive;
        }
        if ($this->cleanupMode === 'status-throws') {
            throw $this->cleanupFailure;
        }

        return $this->cleanupMode !== 'inactive';
    }

    public function beginTransaction(): bool
    {
        $this->calls[] = 'begin';
        if ($this->beginFailure) {
            throw $this->beginFailure;
        }

        return true;
    }

    public function commit(): bool
    {
        $this->calls[] = 'commit';
        $this->commitAttempted = true;
        if ($this->commitFailure) {
            throw $this->commitFailure;
        }

        return true;
    }

    public function rollBack(): bool
    {
        $this->calls[] = 'rollback';
        if ($this->cleanupFailure) {
            throw $this->cleanupFailure;
        }

        return true;
    }
}

final class F39CommitErrorClassificationTest extends TestCase
{
    private function invoke(CommitBoundaryPDO $db, callable $work): mixed
    {
        $engine = (new ReflectionClass(DocumentOperations::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(DocumentOperations::class, 'db'))->setValue($engine, $db);

        return (new ReflectionMethod(DocumentOperations::class, 'transaction'))->invoke($engine, $work);
    }

    public static function cleanupStates(): array
    {
        return [
            'transaction-status-failure' => ['status-throws', true, 0],
            'rollback-failure' => ['rollback', true, 1],
            'no-active-transaction' => ['inactive', false, 0],
            'rollback-succeeds' => ['rollback', false, 1],
        ];
    }

    #[DataProvider('cleanupStates')]
    public function test_commit_cause_survives_cleanup_and_state_ambiguity(string $mode, bool $failCleanup, int $rollbacks): void
    {
        $db = new CommitBoundaryPDO;
        $original = new PDOException('synthetic original commit cause');
        $cleanup = new PDOException('synthetic secondary cleanup cause');
        $db->commitFailure = $original;
        $db->cleanupMode = $mode;
        $db->cleanupFailure = $failCleanup ? $cleanup : null;
        $workCalls = 0;
        try {
            $this->invoke($db, function () use (&$workCalls): string {
                $workCalls++;

                return 'synthetic result';
            });
            self::fail('An ambiguous commit must not be acknowledged as success');
        } catch (RuntimeException $caught) {
            self::assertSame(RuntimeException::class, $caught::class);
            self::assertSame('COMMIT_UNCERTAIN', $caught->getMessage());
            self::assertSame($original, $caught->getPrevious());
            self::assertNotSame($cleanup, $caught->getPrevious());
        }
        self::assertSame(1, $workCalls);
        self::assertSame(1, count(array_filter($db->calls, fn ($call) => $call === 'commit')));
        self::assertSame($rollbacks, count(array_filter($db->calls, fn ($call) => $call === 'rollback')));
        self::assertSame(['inTransaction', 'begin', 'commit', 'inTransaction'], array_slice($db->calls, 0, 4));
    }

    public function test_successful_commit_preserves_result_without_cleanup_retry(): void
    {
        $db = new CommitBoundaryPDO;
        self::assertSame('done', $this->invoke($db, fn () => 'done'));
        self::assertSame(['inTransaction', 'begin', 'commit'], $db->calls);
    }

    public function test_unrelated_work_failure_preserves_original_exception(): void
    {
        $db = new CommitBoundaryPDO;
        $original = new RuntimeException('DOMAIN_REJECTED');
        try {
            $this->invoke($db, fn () => throw $original);
            self::fail('Expected domain failure');
        } catch (RuntimeException $caught) {
            self::assertSame($original, $caught);
        }
        self::assertSame(['inTransaction', 'begin', 'rollback'], $db->calls);
    }

    public function test_work_database_failure_keeps_existing_category(): void
    {
        $db = new CommitBoundaryPDO;
        try {
            $this->invoke($db, fn () => throw new PDOException('synthetic work failure'));
            self::fail('Expected work rejection');
        } catch (RuntimeException $caught) {
            self::assertSame('DATABASE_REJECTED', $caught->getMessage());
        }
        self::assertNotContains('commit', $db->calls);
    }

    public function test_begin_failure_is_not_reclassified(): void
    {
        $db = new CommitBoundaryPDO;
        $db->beginFailure = new PDOException('synthetic begin failure');
        try {
            $this->invoke($db, fn () => 'unreachable');
            self::fail('Expected begin failure');
        } catch (PDOException $caught) {
            self::assertSame($db->beginFailure, $caught);
        }
        self::assertSame(['inTransaction', 'begin'], $db->calls);
    }

    public function test_nested_transaction_guard_is_unchanged(): void
    {
        $db = new CommitBoundaryPDO;
        $db->initiallyActive = true;
        $this->expectExceptionMessage('F39_OUTERMOST_TRANSACTION_REQUIRED');
        try {
            $this->invoke($db, fn () => 'unreachable');
        } finally {
            self::assertSame(['inTransaction'], $db->calls);
        }
    }
}
