<?php

declare(strict_types=1);
require dirname(__DIR__, 3).'/vendor/autoload.php';
use App\Application\FP\DocumentOperations;
use App\Infrastructure\FP\DocumentStorage;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

const TEST_DB = 'fp_f39_stage68_20261118';
$configuredScratch = getenv('F39_SCRATCH');
define('SCRATCH', is_string($configuredScratch) && $configuredScratch !== '' ? $configuredScratch : 'D:/CodexF39Stage68Scratch');
function pdo(): PDO
{
    return new PDO('pgsql:host=127.0.0.1;port=55432;dbname='.TEST_DB.';connect_timeout=5', 'fp_dev_migrator', getenv('F39_MIGRATOR'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}
final class SyntheticRemote implements DocumentStorage
{
    public array $objects = [];

    public string $mode = 'normal';

    public int $puts = 0;

    public ?PDO $database = null;

    public bool $transactionObserved = false;

    public function adapter(): string
    {
        return 'nextcloud';
    }

    public function read(string $path): string
    {
        if ($this->database?->inTransaction()) {
            $this->transactionObserved = true;
        }
        if ($this->mode === 'outage') {
            throw new RuntimeException('NEXTCLOUD_UNAVAILABLE');
        }
        if ($this->mode === 'auth') {
            throw new RuntimeException('NEXTCLOUD_AUTHENTICATION_FAILED');
        }
        if (! isset($this->objects[$path])) {
            throw new RuntimeException('NEXTCLOUD_OBJECT_MISSING');
        }

        return $this->objects[$path];
    }

    public function put(string $path, string $bytes): void
    {
        if ($this->database?->inTransaction()) {
            $this->transactionObserved = true;
        }
        $this->puts++;
        if (isset($this->objects[$path]) && $this->objects[$path] !== $bytes) {
            throw new RuntimeException('NEXTCLOUD_INTEGRITY_WARNING');
        }
        $this->objects[$path] = $bytes;
        if ($this->mode === 'timeout-after-put') {
            throw new RuntimeException('NEXTCLOUD_UPLOAD_UNCERTAIN');
        }
    }

    public function delete(string $path): void
    {
        throw new RuntimeException('DELETE_FORBIDDEN');
    }
}
$results = [];
function check(bool $condition, string $label): void
{
    global $results;
    if (! $condition) {
        throw new RuntimeException('ASSERTION:'.$label);
    }$results[] = $label;
}
function image(): string
{
    $image = imagecreatetruecolor(320, 320);
    ob_start();
    imagepng($image);
    $bytes = ob_get_clean();
    imagedestroy($image);

    return $bytes;
}
try {
    $capsule = new Manager;
    $capsule->addConnection(['driver' => 'pgsql', 'host' => '127.0.0.1', 'port' => 55432, 'database' => TEST_DB, 'username' => 'fp_dev_migrator', 'password' => getenv('F39_MIGRATOR'), 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer']);
    $capsule->setAsGlobal();
    $capsule->getContainer()->instance('db', $capsule->getDatabaseManager());
    Facade::setFacadeApplication($capsule->getContainer());
    $migrationFile = dirname(__DIR__, 3).'/database/migrations/2026_11_18_000001_add_f39_document_operations.php';
    $migration = require $migrationFile;
    $db = pdo();
    $installed = $db->query("SELECT to_regclass('public.fp_document_operations') IS NOT NULL")->fetchColumn();
    if (! $installed) {
        DB::beginTransaction();
        $migration->up();
        DB::rollBack();
        check(! $db->query("SELECT to_regclass('public.fp_document_operations') IS NOT NULL")->fetchColumn(), 'migration_transaction_rollback');
        DB::transaction(function () use ($migration) {
            $migration->up();
            DB::table('migrations')->insert(['migration' => '2026_11_18_000001_add_f39_document_operations', 'batch' => 100]);
        });
    }
    check((int) $db->query("SELECT count(*) FROM migrations WHERE migration='2026_11_18_000001_add_f39_document_operations'")->fetchColumn() === 1, 'migration_ledger_single_install');
    $repository = new \Illuminate\Database\Migrations\DatabaseMigrationRepository($capsule->getDatabaseManager(), 'migrations');
    $migrator = new \Illuminate\Database\Migrations\Migrator($repository, $capsule->getDatabaseManager(), new \Illuminate\Filesystem\Filesystem, new \Illuminate\Events\Dispatcher);
    $migrator->run([$migrationFile]);
    check((int) $db->query("SELECT count(*) FROM migrations WHERE migration='2026_11_18_000001_add_f39_document_operations'")->fetchColumn() === 1, 'laravel_migrator_rerun_is_noop');
    $actor = (int) $db->query("SELECT u.id FROM users u JOIN model_has_roles mr ON mr.model_id=u.id JOIN roles r ON r.id=mr.role_id WHERE u.is_active AND r.name='admin' ORDER BY u.id LIMIT 1")->fetchColumn();
    $member = (int) $db->query("SELECT id FROM financial_planners WHERE full_name LIKE 'Synthetic%' ORDER BY id LIMIT 1")->fetchColumn();
    check($actor > 0 && $member > 0, 'existing_synthetic_fixture_available');
    $expected = (int) $db->query("SELECT COALESCE(max(revision),0) FROM fp_document_amendments WHERE membership_id=$member AND type='KTP'")->fetchColumn();
    if (! is_dir(SCRATCH.'/staging')) {
        mkdir(SCRATCH.'/staging', 0700);
    }
    $remote = new SyntheticRemote;
    $remote->database = $db;
    $engine = new DocumentOperations($db, $remote, SCRATCH.'/staging');
    $bytes = image();
    $key = 'accept-'.bin2hex(random_bytes(16));
    $op = $engine->create($actor, $member, 'KTP', $expected, $key, $bytes);
    check($op['state'] === 'PENDING', 'create_pending');
    check($engine->create($actor, $member, 'KTP', $expected, $key, $bytes)['uuid'] === $op['uuid'], 'idempotent_create');
    $before = (int) $db->query('SELECT count(*) FROM fp_document_operation_events')->fetchColumn();
    check($engine->reconcile($actor, $op['uuid'])['reason'] === 'REMOTE_MISSING', 'dry_run_missing');
    check((int) $db->query('SELECT count(*) FROM fp_document_operation_events')->fetchColumn() === $before, 'dry_run_no_audit_mutation');
    $remote->mode = 'timeout-after-put';
    check($engine->process($actor, $op['uuid']) === 'REMOTE_UNKNOWN', 'timeout_after_remote_created_uncertain');
    check($remote->puts === 1 && count($remote->objects) === 1, 'timeout_no_duplicate_or_delete');
    $remote->mode = 'normal';
    check($engine->reconcile($actor, $op['uuid'], false)['reason'] === 'COMMITTED', 'timeout_recovery_commits_matching_remote');
    check($remote->puts === 1, 'recovery_does_not_reupload');
    check($engine->reconcile($actor, $op['uuid'], false)['reason'] === 'ALREADY_COMMITTED', 'reconciliation_repeat_idempotent');
    check(! $remote->transactionObserved, 'no_network_inside_database_transaction');
    check(! file_exists(SCRATCH.'/staging/'.$op['uuid'].'.bin'), 'cleanup_local_only_after_commit');
    $revision = $db->prepare('SELECT * FROM fp_document_amendments WHERE operation_uuid=?');
    $revision->execute([$op['uuid']]);
    $row = $revision->fetch(PDO::FETCH_ASSOC);
    check($row['uuid'] === $op['revision_uuid'] && (int) $row['revision'] === $expected + 1 && $row['integrity_verified_at'] !== null, 'canonical_remote_revision_tuple');
    $report = ['status' => 'PASS', 'database' => TEST_DB, 'checks' => $results, 'count' => count($results), 'real_dav' => 'BLOCKED'];
    file_put_contents(SCRATCH.'/acceptance.json', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($report, JSON_PRETTY_PRINT),PHP_EOL;
} catch (Throwable $e) {
    $message = $e->getMessage();
    foreach (['F39_MIGRATOR', 'F39_APP', 'F39_ADMIN'] as $key) {
        $message = str_replace(getenv($key), '[REDACTED]', $message);
    }
    // SQL text can contain synthetic metadata, but keep diagnostic output bounded.
    echo json_encode(['status' => 'FAIL', 'checks_completed' => $results, 'class' => get_class($e), 'reason' => substr($message,0,1500)]),PHP_EOL;
    exit(1);
}
