<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Infrastructure\FP\DocumentStorage;
use App\Infrastructure\FP\SecureImage;
use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/** Disposable-only orchestration. No production bindings or scheduler. */
final class DocumentOperations
{
    private bool $reconciliationMarker = false;

    public function __construct(private PDO $db, private DocumentStorage $remote, private string $stagingRoot)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            throw new RuntimeException('F39_DISPOSABLE_CONFIGURATION_REQUIRED');
        }
        $identity = $db->query('SELECT current_database() db,(SELECT system_identifier::text FROM pg_control_system()) cluster')->fetch(PDO::FETCH_ASSOC);
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || ! preg_match('/\Afp_f39_stage68_[0-9]+\z/', $identity['db'])
            || $identity['cluster'] !== '7694154787604477244'
            || $remote->adapter() !== 'nextcloud' || ! is_dir($stagingRoot)) {
            throw new RuntimeException('F39_DISPOSABLE_CONFIGURATION_REQUIRED');
        }
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->reconciliationMarker = (bool) $db->query("SELECT EXISTS(SELECT 1 FROM pg_constraint WHERE conrelid=to_regclass('public.fp_document_operation_events') AND contype='c' AND pg_get_constraintdef(oid) LIKE '%RECONCILED%')")->fetchColumn();
    }

    private function row(string $sql, array $parameters = []): ?array
    {
        $query = $this->db->prepare($sql);
        $query->execute($parameters);

        return $query->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function actor(int $actor): void
    {
        if (! $this->row("SELECT u.id FROM users u JOIN model_has_roles mr ON mr.model_id=u.id AND mr.model_type='App\\Models\\User' JOIN roles r ON r.id=mr.role_id WHERE u.id=? AND u.is_active AND r.name='admin' AND r.guard_name='web'", [$actor])) {
            throw new RuntimeException('AUTHORIZATION_DENIED');
        }
    }

    private function transaction(callable $work): mixed
    {
        if ($this->db->inTransaction()) {
            throw new RuntimeException('F39_OUTERMOST_TRANSACTION_REQUIRED');
        }
        $this->db->beginTransaction();
        try {
            $result = $work();
        } catch (\Throwable $error) {
            $this->db->rollBack();
            if ($error instanceof \PDOException) {
                throw new RuntimeException('DATABASE_REJECTED');
            }
            throw $error;
        }
        try {
            $this->db->commit();
        } catch (\Throwable $commitError) {
            // A failed COMMIT acknowledgement is not evidence that COMMIT failed.
            try {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
            } catch (\Throwable) {
                // Connection-loss cleanup must not replace the original cause.
            }
            throw new RuntimeException('COMMIT_UNCERTAIN', 0, $commitError);
        }

        return $result;
    }

    private function audit(array $op, int $actor, string $source, string $action, ?string $reason = null): void
    {
        $query = $this->db->prepare('INSERT INTO fp_document_operation_events(operation_uuid,recovery_actor_id,source_state,target_state,action,reason,fence) VALUES(?,?,?,?,?,?,?)');
        $query->execute([$op['uuid'], $actor, $source, $op['state'], $action, $reason, $op['fence']]);
    }

    private function stagePath(string $uuid): string
    {
        if (! Uuid::isValid($uuid)) {
            throw new RuntimeException('OPERATION_INVALID');
        }

        return rtrim($this->stagingRoot, '/\\').DIRECTORY_SEPARATOR.$uuid.'.bin';
    }

    public function create(int $actor, int $membership, string $type, int $expected, string $key, #[\SensitiveParameter] string $image): array
    {
        $this->actor($actor);
        if (! in_array($type, ['KTP', 'IJAZAH'], true) || $expected < 0 || strlen($key) < 16 || strlen($key) > 200) {
            throw new RuntimeException('OPERATION_INVALID');
        }
        $safe = (new SecureImage)->sanitize($image);
        $fingerprint = hash('sha256', json_encode([$membership, $type, $expected, $safe['mime'], $safe['size'], $safe['sha256']], JSON_THROW_ON_ERROR));

        return $this->transaction(function () use ($actor, $membership, $type, $expected, $key, $safe, $fingerprint): array {
            $member = $this->row('SELECT id,uuid FROM financial_planners WHERE id=? FOR UPDATE', [$membership]);
            if (! $member) {
                throw new RuntimeException('MEMBERSHIP_MISSING');
            }
            $existing = $this->row('SELECT * FROM fp_document_operations WHERE actor_id=? AND membership_id=? AND type=? AND idempotency_hash=?', [$actor, $membership, $type, hash('sha256', $key)]);
            if ($existing) {
                if (! hash_equals($existing['request_hash'], $fingerprint)) {
                    throw new RuntimeException('IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }
            $latest = $this->row('SELECT COALESCE(max(revision),0) revision FROM fp_document_amendments WHERE membership_id=? AND type=?', [$membership, $type]);
            if ((int) $latest['revision'] !== $expected) {
                throw new RuntimeException('REVISION_CONFLICT');
            }
            $uuid = Uuid::uuid4()->toString();
            $revision = Uuid::uuid4()->toString();
            $document = Uuid::uuid5($member['uuid'], $type)->toString();
            $path = 'documents/'.$member['uuid'].'/'.$document.'/revision-'.$revision.'/document.bin';
            $file = @fopen($this->stagePath($uuid), 'xb');
            if (! $file) {
                throw new RuntimeException('STAGING_INVALID');
            }
            try {
                if (fwrite($file, $safe['bytes']) !== $safe['size'] || ! fflush($file) || ! fsync($file)) {
                    throw new RuntimeException('STAGING_INVALID');
                }
            } finally {
                fclose($file);
            }
            $op = $this->row("INSERT INTO fp_document_operations(uuid,operation_type,membership_id,type,actor_id,idempotency_hash,request_hash,expected_revision,target_revision,revision_uuid,private_path,mime,bytes,sha256) VALUES(?,'ADMIN_DOCUMENT_AMENDMENT',?,?,?,?,?,?,?,?,?,?,?,?) RETURNING *",
                [$uuid, $membership, $type, $actor, hash('sha256', $key), $fingerprint, $expected, $expected + 1, $revision, $path, $safe['mime'], $safe['size'], $safe['sha256']]);
            $this->audit($op, $actor, 'PENDING', 'CREATED');

            return $op;
        });
    }

    public function inspect(int $actor, string $uuid): array
    {
        $this->actor($actor);
        if (! Uuid::isValid($uuid)) {
            throw new RuntimeException('OPERATION_INVALID');
        }

        return $this->row('SELECT * FROM fp_document_operations WHERE uuid=?', [$uuid]) ?? throw new RuntimeException('OPERATION_MISSING');
    }

    private function claim(int $actor, string $uuid): ?array
    {
        $this->actor($actor);

        return $this->transaction(function () use ($actor, $uuid): ?array {
            $op = $this->row('SELECT *,lease_until>clock_timestamp() leased FROM fp_document_operations WHERE uuid=? FOR UPDATE SKIP LOCKED', [$uuid]);
            if (! $op || in_array($op['state'], ['COMMITTED', 'FAILED', 'QUARANTINED'], true) || $op['leased']) {
                return null;
            }
            $claimed = $this->row("UPDATE fp_document_operations SET lease_owner=?,lease_until=clock_timestamp()+interval '120 seconds',fence=fence+1,attempts=attempts+1,updated_at=clock_timestamp() WHERE uuid=? RETURNING *", [Uuid::uuid4()->toString(), $uuid]);
            $this->audit($claimed, $actor, $op['state'], 'CLAIMED');

            return $claimed;
        });
    }

    private function locked(array $claim): array
    {
        $op = $this->row('SELECT *,lease_until>clock_timestamp() leased FROM fp_document_operations WHERE uuid=? FOR UPDATE', [$claim['uuid']]);
        if (! $op || ! $op['leased'] || $op['lease_owner'] !== $claim['lease_owner'] || $op['fence'] !== $claim['fence']) {
            throw new RuntimeException('LEASE_LOST');
        }

        return $op;
    }

    private function change(int $actor, array $claim, string $target, ?string $reason = null, bool $release = false, bool $verified = false): array
    {
        return $this->transaction(function () use ($actor, $claim, $target, $reason, $release, $verified): array {
            $op = $this->locked($claim);
            $next = $this->row('UPDATE fp_document_operations SET state=?,error_code=?,updated_at=clock_timestamp(),lease_owner=CASE WHEN ? THEN NULL ELSE lease_owner END,lease_until=CASE WHEN ? THEN NULL ELSE lease_until END,verified_at=CASE WHEN ? THEN clock_timestamp() ELSE verified_at END WHERE uuid=? RETURNING *',
                [$target, $reason, $release ? 'true' : 'false', $release ? 'true' : 'false', $verified ? 'true' : 'false', $op['uuid']]);
            $this->audit($next, $actor, $op['state'], $target === $op['state'] ? 'DEFERRED' : 'TRANSITION', $reason);

            return $next;
        });
    }

    private function remoteStatus(array $op): string
    {
        try {
            $bytes = $this->remote->read($op['private_path']);

            return strlen($bytes) === (int) $op['bytes'] && hash_equals($op['sha256'], hash('sha256', $bytes)) ? 'REMOTE_VERIFIED' : 'INTEGRITY_MISMATCH';
        } catch (\Throwable $error) {
            return match ($error->getMessage()) {
                'NEXTCLOUD_OBJECT_MISSING' => 'REMOTE_MISSING',
                'NEXTCLOUD_AUTHENTICATION_FAILED' => 'AUTHENTICATION_FAILED',
                default => 'REMOTE_UNKNOWN',
            };
        }
    }

    private function staged(array $op): string
    {
        $bytes = @file_get_contents($this->stagePath($op['uuid']));
        if (! is_string($bytes) || strlen($bytes) !== (int) $op['bytes'] || ! hash_equals($op['sha256'], hash('sha256', $bytes))) {
            throw new RuntimeException('STAGING_INVALID');
        }

        return $bytes;
    }

    private function commit(int $actor, array $claim): string
    {
        $result = $this->transaction(function () use ($actor, $claim): string {
            $member = $this->row('SELECT * FROM financial_planners WHERE id=? FOR UPDATE', [$claim['membership_id']]);
            $op = $this->locked($claim);
            $this->actor($actor);
            $this->actor((int) $op['actor_id']);
            $latest = $this->row('SELECT * FROM fp_document_amendments WHERE membership_id=? AND type=? ORDER BY revision DESC LIMIT 1', [$op['membership_id'], $op['type']]);
            if ((int) ($latest['revision'] ?? 0) !== (int) $op['expected_revision']) {
                $next = $this->row("UPDATE fp_document_operations SET state='QUARANTINED',error_code='REVISION_CONFLICT',lease_owner=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE uuid=? RETURNING *", [$op['uuid']]);
                $this->audit($next, $actor, $op['state'], 'TRANSITION', 'REVISION_CONFLICT');

                return 'REVISION_CONFLICT';
            }
            if ($op['state'] !== 'VERIFYING' || ! $op['verified_at']) {
                throw new RuntimeException('REMOTE_NOT_VERIFIED');
            }
            $prior = $latest['uuid'] ?? ($this->row('SELECT uuid FROM fp_documents WHERE membership_id=? AND type=?', [$op['membership_id'], $op['type']])['uuid'] ?? null);
            $query = $this->db->prepare('INSERT INTO fp_document_amendments(uuid,membership_id,submission_id,type,revision,actor_id,previous_uuid,storage_adapter,private_path,mime,bytes,sha256,operation_uuid,integrity_verified_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $query->execute([$op['revision_uuid'], $member['id'], $member['submission_id'], $op['type'], $op['target_revision'], $op['actor_id'], $prior, 'nextcloud', $op['private_path'], $op['mime'], $op['bytes'], $op['sha256'], $op['uuid'], $op['verified_at']]);
            $next = $this->row("UPDATE fp_document_operations SET state='COMMITTED',committed_at=clock_timestamp(),error_code=NULL,lease_owner=NULL,lease_until=NULL,updated_at=clock_timestamp() WHERE uuid=? RETURNING *", [$op['uuid']]);
            $this->audit($next, $actor, $op['state'], 'TRANSITION');

            return 'COMMITTED';
        });
        if ($result === 'COMMITTED') {
            $this->cleanup($actor, $claim);
        }

        return $result;
    }

    private function cleanup(int $actor, array $op): void
    {
        $path = $this->stagePath($op['uuid']);
        // An acknowledged canonical commit is required before this method is called.
        // Never unlink an unexpected file or symlink in the staging directory.
        if (is_file($path) && ! is_link($path) && filesize($path) === (int) $op['bytes']
            && hash_equals($op['sha256'], hash_file('sha256', $path))) {
            $this->transaction(function () use ($actor, $op): void {
                $current = $this->row('SELECT * FROM fp_document_operations WHERE uuid=?', [$op['uuid']]);
                // Record cleanup intent before the filesystem side effect. A crash
                // leaves the same verified operation-owned file safe to retry.
                $this->audit($current, $actor, 'COMMITTED', 'DEFERRED');
            });
            @unlink($path);
        }
    }

    public function process(int $actor, string $uuid): string
    {
        $initial = $this->inspect($actor, $uuid);
        if ($initial['state'] !== 'PENDING') {
            return $this->reconcile($actor, $uuid, false)['reason'];
        }
        $op = $this->claim($actor, $uuid);
        if (! $op) {
            return 'BUSY_OR_TERMINAL';
        }
        $op = $this->change($actor, $op, 'UPLOADING');
        $status = $this->remoteStatus($op);
        if ($status === 'REMOTE_MISSING') {
            try {
                $bytes = $this->staged($op);
            } catch (RuntimeException) {
                $op = $this->change($actor, $op, 'VERIFYING');
                $this->change($actor, $op, 'FAILED', 'STAGING_INVALID', true);

                return 'STAGING_INVALID';
            }
            try {
                $this->remote->put($op['private_path'], $bytes);
            } catch (\Throwable) {
                $this->change($actor, $op, 'UPLOADING', 'REMOTE_UNKNOWN', true);

                return 'REMOTE_UNKNOWN';
            }
            $op = $this->change($actor, $op, 'UPLOADED');
            $status = $this->remoteStatus($op);
        }

        return $this->finish($actor, $op, $status);
    }

    private function finish(int $actor, array $op, string $status): string
    {
        $op = $this->change($actor, $op, 'VERIFYING');
        if ($status === 'REMOTE_VERIFIED') {
            $op = $this->change($actor, $op, 'VERIFYING', verified: true);

            return $this->commit($actor, $op);
        }
        $target = match ($status) {
            'REMOTE_MISSING' => 'PENDING',
            'INTEGRITY_MISMATCH' => 'QUARANTINED',
            default => 'VERIFYING',
        };
        $this->change($actor, $op, $target, $status, true);

        return $status;
    }

    public function reconcile(int $actor, string $uuid, bool $dryRun = true): array
    {
        $result = $this->reconcileOperation($actor, $uuid, $dryRun);
        if (! $dryRun && $this->reconciliationMarker) {
            $this->transaction(function () use ($actor, $uuid): void {
                $op = $this->row('SELECT * FROM fp_document_operations WHERE uuid=? FOR SHARE', [$uuid]);
                $this->audit($op, $actor, $op['state'], 'RECONCILED');
            });
        }

        return $result;
    }

    private function reconcileOperation(int $actor, string $uuid, bool $dryRun): array
    {
        $op = $this->inspect($actor, $uuid);
        $canonical = $this->row('SELECT uuid FROM fp_document_amendments WHERE operation_uuid=?', [$uuid]);
        if ($op['state'] === 'COMMITTED' || $canonical) {
            $matches = $op['state'] === 'COMMITTED' && $canonical && $canonical['uuid'] === $op['revision_uuid'];
            if ($matches && ! $dryRun) {
                $this->cleanup($actor, $op);
            }

            return ['operation' => $uuid, 'reason' => $matches ? 'ALREADY_COMMITTED' : 'INVARIANT_WARNING', 'dry_run' => $dryRun];
        }
        if (in_array($op['state'], ['FAILED', 'QUARANTINED'], true)) {
            return ['operation' => $uuid, 'reason' => 'OPERATOR_REVIEW_REQUIRED', 'dry_run' => $dryRun];
        }
        if ($dryRun) {
            return ['operation' => $uuid, 'reason' => $this->remoteStatus($op), 'dry_run' => true];
        }
        $op = $this->claim($actor, $uuid);
        if (! $op) {
            return ['operation' => $uuid, 'reason' => 'BUSY_OR_TERMINAL', 'dry_run' => false];
        }

        return ['operation' => $uuid, 'reason' => $this->finish($actor, $op, $this->remoteStatus($op)), 'dry_run' => false];
    }

    public function batch(int $actor, int $limit = 25, bool $dryRun = true): array
    {
        $this->actor($actor);
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('BATCH_LIMIT_INVALID');
        }
        $query = $this->db->query("SELECT uuid FROM fp_document_operations WHERE state NOT IN ('COMMITTED','FAILED','QUARANTINED') ORDER BY created_at,uuid LIMIT ".$limit);
        $results = [];
        foreach ($query->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
            $results[] = $this->reconcile($actor, $uuid, $dryRun);
        }

        return $results;
    }
}
