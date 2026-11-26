<?php

declare(strict_types=1);
require dirname(__DIR__, 3).'/vendor/autoload.php';
use App\Application\FP\DocumentOperations;
use App\Infrastructure\FP\DocumentStorage;

const DATABASE = 'fp_f39_stage68_20261118';
$configuredScratch = getenv('F39_SCRATCH');
define('ROOT', is_string($configuredScratch) && $configuredScratch !== '' ? $configuredScratch : 'D:/CodexF39Stage68Scratch');
function connection(): PDO
{
    return new PDO('pgsql:host=127.0.0.1;port=55432;dbname='.DATABASE, 'fp_dev_migrator', getenv('F39_MIGRATOR'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
class DiskRemote implements DocumentStorage
{
    public string $mode = 'normal';

    public int $puts = 0;

    public function adapter(): string
    {
        return 'nextcloud';
    }

    private function file(string $p): string
    {
        return ROOT.'/remote/'.hash('sha256', $p);
    }

    public function read(string $p): string
    {
        if ($this->mode === 'outage' || $this->mode === 'tls') {
            throw new RuntimeException('NEXTCLOUD_UNAVAILABLE');
        }
        if ($this->mode === 'auth') {
            throw new RuntimeException('NEXTCLOUD_AUTHENTICATION_FAILED');
        }
        $file = $this->file($p);
        if (! file_exists($file)) {
            throw new RuntimeException('NEXTCLOUD_OBJECT_MISSING');
        }

        return file_get_contents($file);
    }

    public function put(string $p, string $b): void
    {
        file_put_contents(ROOT.'/put-attempts.jsonl', json_encode(['object' => hash('sha256', $p)]).PHP_EOL, FILE_APPEND | LOCK_EX);
        $this->puts++;
        $f = @fopen($this->file($p), 'xb');
        if ($f) {
            fwrite($f, $b);
            fclose($f);
        } elseif (file_get_contents($this->file($p)) !== $b) {
            throw new RuntimeException('NEXTCLOUD_INTEGRITY_WARNING');
        }
    }

    public function corrupt(string $p): void
    {
        file_put_contents($this->file($p), 'synthetic corrupt object');
    }

    public function delete(string $p): void
    {
        throw new RuntimeException('DELETE_FORBIDDEN');
    }
}
class LostCommitPDO extends PDO
{
    public ?string $loseOperation = null;

    public function commit(): bool
    {
        $lose = false;
        if ($this->loseOperation) {
            $q = $this->prepare("SELECT count(*) FROM fp_document_operations WHERE uuid=? AND state='COMMITTED'");
            $q->execute([$this->loseOperation]);
            $lose = (bool) $q->fetchColumn();
        }
        $ok = parent::commit();
        if ($lose) {
            $this->loseOperation = null;
            throw new PDOException('synthetic lost COMMIT acknowledgement');
        }

        return $ok;
    }
}
if (($argv[1] ?? '') === 'worker') {
    try {
        $engine = new DocumentOperations(connection(), new DiskRemote, ROOT.'/staging');
        echo $engine->process((int) $argv[2], $argv[3]);
        exit(0);
    } catch (Throwable) {
        echo 'WORKER_ERROR';
        exit(1);
    }
}
$checks = [];
function verify(bool $b, string $s): void
{
    global $checks;
    if (! $b) {
        throw new RuntimeException('ASSERTION:'.$s);
    }$checks[] = $s;
}
function reject(callable $fn, string $code, string $label): void
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        verify($e->getMessage() === $code, $label);

        return;
    }throw new RuntimeException('ASSERTION:'.$label);
}
function expire(PDO $p, array $op, int $actor): void
{
    $p->beginTransaction();
    $q = $p->prepare("UPDATE fp_document_operations SET lease_until=clock_timestamp()-interval '1 second' WHERE uuid=? RETURNING *");
    $q->execute([$op['uuid']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    $q = $p->prepare("INSERT INTO fp_document_operation_events(operation_uuid,recovery_actor_id,source_state,target_state,action,fence) VALUES(?,?,?,?,'DEFERRED',?)");
    $q->execute([$row['uuid'], $actor, $row['state'], $row['state'], $row['fence']]);
    $p->commit();
}
try {
    if (! is_dir(ROOT.'/remote')) {
        mkdir(ROOT.'/remote', 0700);
    }
    $db = connection();
    $remote = new DiskRemote;
    $engine = new DocumentOperations($db, $remote, ROOT.'/staging');
    $actor = (int) $db->query("SELECT u.id FROM users u JOIN model_has_roles mr ON mr.model_id=u.id JOIN roles r ON r.id=mr.role_id WHERE u.is_active AND r.name='admin' ORDER BY u.id LIMIT 1")->fetchColumn();
    $member = (int) $db->query("SELECT id FROM financial_planners WHERE full_name LIKE 'Synthetic%' ORDER BY id LIMIT 1")->fetchColumn();
    $img = imagecreatetruecolor(320, 320);
    ob_start();
    imagepng($img);
    $image = ob_get_clean();
    imagedestroy($img);
    $new = function (string $type = 'IJAZAH') use ($db, $engine, $actor, $member, $image): array {
        $revision = (int) $db->query("SELECT COALESCE(max(revision),0) FROM fp_document_amendments WHERE membership_id=$member AND type='$type'")->fetchColumn();

        return $engine->create($actor, $member, $type, $revision, 'failure-'.bin2hex(random_bytes(16)), $image);
    };
    $op = $new();
    verify($engine->reconcile($actor, $op['uuid'])['reason'] === 'REMOTE_MISSING', 'crash_before_upload_retains_pending');
    $nonAdmin = (int) $db->query("SELECT u.id FROM users u JOIN model_has_roles mr ON mr.model_id=u.id JOIN roles r ON r.id=mr.role_id WHERE u.is_active AND r.name='bm' AND NOT EXISTS(SELECT 1 FROM model_has_roles a JOIN roles b ON b.id=a.role_id WHERE a.model_id=u.id AND b.name='admin') LIMIT 1")->fetchColumn();
    verify($nonAdmin > 0, 'bm_fixture_available');
    reject(fn () => $engine->inspect($nonAdmin, $op['uuid']), 'AUTHORIZATION_DENIED', 'bm_denied_journal_access');
    $engine->create($actor, $member, 'IJAZAH', (int) $op['expected_revision'], 'different-key-'.bin2hex(random_bytes(16)), 'invalid bytes');
} catch (Throwable $e) {
    if (str_contains($e->getMessage(), 'Only JPEG/PNG') || str_contains($e->getMessage(), 'Invalid document size')) {
        $checks[] = 'invalid_image_denied';
    } else {
        echo json_encode(['status' => 'FAIL', 'checks' => $checks, 'reason' => $e->getMessage()]);
        exit(1);
    }
}
try {
    $key = 'same-key-'.bin2hex(random_bytes(16));
    $same = $engine->create($actor, $member, 'KTP', (int) $db->query("SELECT COALESCE(max(revision),0) FROM fp_document_amendments WHERE membership_id=$member AND type='KTP'")->fetchColumn(), $key, $image);
    reject(fn () => $engine->create($actor, $member, 'KTP', (int) $same['expected_revision'] + 1, $key, $image), 'IDEMPOTENCY_CONFLICT', 'same_key_changed_payload_denied');
    // Two distinct PostgreSQL sessions: a row lock held by worker one must prevent
    // worker two from claiming or uploading that operation.
    $lock = connection();
    $lock->beginTransaction();
    $q = $lock->prepare('SELECT uuid FROM fp_document_operations WHERE uuid=? FOR UPDATE');
    $q->execute([$op['uuid']]);
    $env = getenv();
    $args = [PHP_BINARY, '-d', 'sys_temp_dir='.ROOT.'/php-temp', __FILE__, 'worker', (string) $actor, $op['uuid']];
    $child = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($child);
    $lock->rollBack();
    verify($code === 0 && $output === 'BUSY_OR_TERMINAL', 'concurrent_worker_skip_locked_no_upload');
    $race = $new('KTP');
    $children = [];
    foreach ([1, 2] as $worker) {
        $args = [PHP_BINARY, '-d', 'sys_temp_dir='.ROOT.'/php-temp', __FILE__, 'worker', (string) $actor, $race['uuid']];
        $handle = proc_open($args, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $channels, null, $env);
        fclose($channels[0]);
        $children[] = [$handle, $channels];
    }
    $outcomes = [];
    foreach ($children as [$handle,$channels]) {
        $outcomes[] = stream_get_contents($channels[1]);
        stream_get_contents($channels[2]);
        fclose($channels[1]);
        fclose($channels[2]);
        verify(proc_close($handle) === 0, 'parallel_worker_exit_clean');
    }
    verify(in_array('COMMITTED', $outcomes, true), 'parallel_workers_one_commit');
    $objectHash = hash('sha256', $race['private_path']);
    $putCount = 0;
    foreach (file(ROOT.'/put-attempts.jsonl', FILE_IGNORE_NEW_LINES) as $line) {
        if ((json_decode($line, true)['object'] ?? '') === $objectHash) {
            $putCount++;
        }
    }
    verify($putCount === 1, 'parallel_workers_exactly_one_remote_put');
    $claim = new ReflectionMethod(DocumentOperations::class, 'claim');
    $change = new ReflectionMethod(DocumentOperations::class, 'change');
    $first = $claim->invoke($engine, $actor, $op['uuid']);
    expire($db, $first, $actor);
    $second = $claim->invoke($engine, $actor, $op['uuid']);
    verify((int) $second['fence'] === (int) $first['fence'] + 1, 'stale_lease_reclaimed_with_new_fence');
    reject(fn () => $change->invoke($engine, $actor, $first, 'UPLOADING'), 'LEASE_LOST', 'stale_worker_cannot_transition');
    $change->invoke($engine, $actor, $second, 'UPLOADING');
    expire($db, $second, $actor);
    verify($engine->reconcile($actor, $op['uuid'], false)['reason'] === 'REMOTE_MISSING', 'crash_during_upload_missing_recovers_pending');
    verify($engine->process($actor, $op['uuid']) === 'COMMITTED', 'recovered_operation_uploads_once');
    foreach (['outage' => 'REMOTE_UNKNOWN', 'auth' => 'AUTHENTICATION_FAILED', 'tls' => 'REMOTE_UNKNOWN'] as $mode => $reason) {
        $pending = $new();
        $remote->mode = $mode;
        $before = $remote->puts;
        verify($engine->process($actor, $pending['uuid']) === $reason, $mode.'_preserves_uncertainty');
        verify($before === $remote->puts, $mode.'_does_not_upload_on_unknown');
        $remote->mode = 'normal';
        verify($engine->reconcile($actor, $pending['uuid'], false)['reason'] === 'REMOTE_MISSING', $mode.'_recovery_verifies_missing');
    }
    $mismatch = $new();
    $remote->corrupt($mismatch['private_path']);
    verify($engine->process($actor, $mismatch['uuid']) === 'INTEGRITY_MISMATCH', 'hash_mismatch_quarantined');
    verify($engine->inspect($actor, $mismatch['uuid'])['state'] === 'QUARANTINED', 'quarantine_persisted');
    $invalid = $new();
    file_put_contents(ROOT.'/staging/'.$invalid['uuid'].'.bin', 'corrupted synthetic staging');
    $before = $remote->puts;
    verify($engine->process($actor, $invalid['uuid']) === 'STAGING_INVALID', 'corrupt_local_staging_rejected');
    verify($engine->inspect($actor, $invalid['uuid'])['state'] === 'FAILED' && $before === $remote->puts, 'definite_pre_upload_failure_no_remote_write');
    $a = $new();
    $b = $new();
    verify($engine->process($actor, $a['uuid']) === 'COMMITTED', 'first_sequence_wins');
    verify($engine->process($actor, $b['uuid']) === 'REVISION_CONFLICT', 'second_sequence_conflict_quarantined');
    $forced = $new();
    $db->exec("ALTER TABLE fp_document_amendments ADD CONSTRAINT f39_test_reject_revision CHECK(operation_uuid IS DISTINCT FROM '".$forced['uuid']."'::uuid) NOT VALID");
    reject(fn () => $engine->process($actor, $forced['uuid']), 'DATABASE_REJECTED', 'metadata_failure_after_remote_upload');
    verify($engine->inspect($actor, $forced['uuid'])['state'] === 'VERIFYING', 'failed_commit_does_not_publish_revision');
    verify($remote->read($forced['private_path']) !== '' && file_exists(ROOT.'/staging/'.$forced['uuid'].'.bin'), 'failed_commit_preserves_remote_and_stage');
    $db->exec('ALTER TABLE fp_document_amendments DROP CONSTRAINT f39_test_reject_revision');
    expire($db, $engine->inspect($actor, $forced['uuid']), $actor);
    verify((new DocumentOperations(connection(), $remote, ROOT.'/staging'))->reconcile($actor, $forced['uuid'], false)['reason'] === 'COMMITTED', 'fresh_connection_reconciles_after_rollback');
    $lost = new LostCommitPDO('pgsql:host=127.0.0.1;port=55432;dbname='.DATABASE, 'fp_dev_migrator', getenv('F39_MIGRATOR'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $ambiguous = $new();
    $lost->loseOperation = $ambiguous['uuid'];
    $uncertain = new DocumentOperations($lost, $remote, ROOT.'/staging');
    reject(fn () => $uncertain->process($actor, $ambiguous['uuid']), 'COMMIT_UNCERTAIN', 'lost_commit_acknowledgement_reported_uncertain');
    verify(file_exists(ROOT.'/staging/'.$ambiguous['uuid'].'.bin'), 'ambiguous_commit_keeps_local_evidence');
    verify((new DocumentOperations(connection(), $remote, ROOT.'/staging'))->reconcile($actor, $ambiguous['uuid'], false)['reason'] === 'ALREADY_COMMITTED', 'fresh_connection_recovers_committed_metadata');
    verify(! file_exists(ROOT.'/staging/'.$ambiguous['uuid'].'.bin'), 'reconciled_commit_cleans_owned_local_stage');
    reject(fn () => $engine->batch($actor, 101), 'BATCH_LIMIT_INVALID', 'batch_limit_enforced');
    $reader = connection();
    $reader->beginTransaction();
    $reader->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
    $snapshot = $reader->query('SELECT count(*) FROM fp_document_operations')->fetchColumn();
    $new();
    verify($reader->query('SELECT count(*) FROM fp_document_operations')->fetchColumn() === $snapshot, 'postgres_repeatable_read_snapshot');
    $reader->rollBack();
    verify($db->query('SELECT count(*) FROM fp_document_operations')->fetchColumn() === $snapshot + 1, 'postgres_new_snapshot_sees_commit');
    $count = (int) $db->query('SELECT count(*) FROM fp_document_operation_events')->fetchColumn();
    $engine->batch($actor, 2, true);
    verify($count === (int) $db->query('SELECT count(*) FROM fp_document_operation_events')->fetchColumn(), 'batch_dry_run_has_no_writes');
    $q = $db->prepare('UPDATE fp_document_amendments SET sha256=? WHERE operation_uuid=?');
    try {
        $q->execute([str_repeat('0', 64), $op['uuid']]);
        throw new RuntimeException('ASSERTION:history_mutated');
    } catch (PDOException) {
        $checks[] = 'canonical_history_update_denied';
    }
    try {
        $db->exec("UPDATE fp_document_operations SET private_path='other' WHERE uuid='".$op['uuid']."'");
        throw new RuntimeException('ASSERTION:operation_mutated');
    } catch (PDOException) {
        $checks[] = 'operation_content_immutable';
    }
    $columns = 'uuid,operation_type,membership_id,type,actor_id,idempotency_hash,request_hash,expected_revision,target_revision,revision_uuid,private_path,mime,bytes,sha256';
    foreach (['foreign_key' => "gen_random_uuid(),operation_type,-1,type,actor_id,encode(sha256(random()::text::bytea),'hex'),request_hash,expected_revision,target_revision,gen_random_uuid(),regexp_replace(private_path,'revision-[0-9a-f-]+','revision-'||gen_random_uuid()::text),mime,bytes,sha256", 'idempotency_unique' => "gen_random_uuid(),operation_type,membership_id,type,actor_id,idempotency_hash,request_hash,expected_revision,target_revision,gen_random_uuid(),regexp_replace(private_path,'revision-[0-9a-f-]+','revision-'||gen_random_uuid()::text),mime,bytes,sha256"] as $label => $projection) {
        try {
            $db->exec('INSERT INTO fp_document_operations('.$columns.') SELECT '.$projection.' FROM fp_document_operations LIMIT 1');
            throw new RuntimeException('ASSERTION:'.$label);
        } catch (PDOException $error) {
            verify($error->getCode() === ($label === 'foreign_key' ? '23503' : '23505'),'database_'.$label.'_enforced');
        }
    }
    $guarded = $new();
    $db->beginTransaction();
    try {
        $statement = $db->prepare("UPDATE fp_document_operations SET error_code='REMOTE_UNKNOWN' WHERE uuid=?");
        $statement->execute([$guarded['uuid']]);
        $db->commit();
        throw new RuntimeException('ASSERTION:missing_audit_accepted');
    } catch (PDOException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        verify($error->getCode() === 'P0001', 'database_transition_requires_audit');
    }
    $db->beginTransaction();
    try {
        foreach (['VERIFYING', 'COMMITTED'] as $state) {
            $statement = $db->prepare('UPDATE fp_document_operations SET state=?,verified_at=clock_timestamp(),committed_at=CASE WHEN ? THEN clock_timestamp() ELSE NULL END WHERE uuid=?');
            $statement->execute([$state, $state === 'COMMITTED' ? 'true' : 'false', $guarded['uuid']]);
            $statement = $db->prepare("INSERT INTO fp_document_operation_events(operation_uuid,recovery_actor_id,source_state,target_state,action,fence) VALUES(?,?,?,?,'TRANSITION',0)");
            $statement->execute([$guarded['uuid'], $actor, 'PENDING', $state]);
        }
        $db->commit();
        throw new RuntimeException('ASSERTION:commit_without_revision_accepted');
    } catch (PDOException $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        verify($error->getCode() === 'P0001', 'database_committed_requires_canonical_revision');
    }
    verify($engine->inspect($actor, $guarded['uuid'])['state'] === 'PENDING', 'deferred_guard_rolls_back_entire_transition');
    $report = ['status' => 'PASS', 'database' => DATABASE, 'checks' => $checks, 'count' => count($checks), 'real_dav' => 'BLOCKED'];
    file_put_contents(ROOT.'/failure-tests.json',json_encode($report,JSON_PRETTY_PRINT));
    echo json_encode($report,JSON_PRETTY_PRINT),PHP_EOL;
} catch (Throwable $e) {
    echo json_encode(['status' => 'FAIL', 'checks' => $checks, 'class' => get_class($e), 'reason' => substr($e->getMessage(),0,800)]);
    exit(1);
}
