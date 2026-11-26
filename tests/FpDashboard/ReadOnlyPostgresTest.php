<?php

declare(strict_types=1);

namespace Tests\FpDashboard;

use App\Application\FP\FpDirectory;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class ReadOnlyPostgresTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('FP_DASHBOARD_READONLY') !== '1') {
            self::markTestSkipped('Explicit local read-only PostgreSQL test configuration absent');
        }
        $this->pdo = new \PDO('pgsql:host=127.0.0.1;port=55432;dbname=fp_management_synthetic_dev', 'fp_dev_app', (string) getenv('FP_DASHBOARD_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->beginTransaction();
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $identity = $this->pdo->query('SELECT current_database() AS db,current_user AS role,host(inet_server_addr()) AS host,inet_server_port() AS port')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['db' => 'fp_management_synthetic_dev', 'role' => 'fp_dev_app', 'host' => '127.0.0.1', 'port' => 55432], $identity);
        $resolver = new ConnectionResolver(['readonly' => new PostgresConnection($this->pdo)]);
        $resolver->setDefaultConnection('readonly');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function test_statistics_match_independent_postgresql_counts_and_directories(): void
    {
        $expected = $this->pdo->query("SELECT count(*) AS all,count(*) FILTER(WHERE f.lifecycle_state='ACTIVE') AS active,count(*) FILTER(WHERE f.lifecycle_state='EXITED' AND e.type='NORMAL') AS normal,count(*) FILTER(WHERE f.lifecycle_state='EXITED' AND e.type='ABNORMAL') AS abnormal FROM financial_planners f LEFT JOIN fp_exits e ON e.membership_id=f.id")->fetch(\PDO::FETCH_ASSOC);
        $summary = FpDirectory::counts();
        foreach ($expected as $key => $count) {
            self::assertSame((int) $count, $summary[$key]);
            self::assertSame((int) $count, FpDirectory::members($key)->count());
        }
    }

    private function virtualRows(string $scope): array
    {
        $cte = "WITH financial_planners(id,lifecycle_state,origin,nip) AS (VALUES (1,'ACTIVE','NEW',1001::bigint),(2,'EXITED','NEW',1002::bigint),(3,'EXITED','NEW',1003::bigint),(4,NULL,'LEGACY',NULL::bigint),(5,'EXITED','LEGACY',NULL::bigint)),fp_exits(membership_id,type) AS (VALUES(2,'NORMAL'),(3,'ABNORMAL')) ";
        $query = FpDirectory::members($scope)->orderBy('id');
        $statement = $this->pdo->prepare($cte.$query->toSql());
        $statement->execute($query->getBindings());

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function test_active_excludes_exited_and_unresolved(): void
    {
        self::assertSame([1], array_column($this->virtualRows('active'), 'id'));
    }

    public function test_normal_and_abnormal_use_owned_exit_type(): void
    {
        self::assertSame([2], array_column($this->virtualRows('normal'), 'id'));
        self::assertSame([3], array_column($this->virtualRows('abnormal'), 'id'));
    }

    public function test_all_preserves_unresolved_legacy_without_fabricating_nip(): void
    {
        $rows = $this->virtualRows('all');
        self::assertSame([1, 2, 3, 4, 5], array_column($rows, 'id'));
        self::assertNull($rows[3]['nip']);
        self::assertNull($rows[3]['lifecycle_state']);
        self::assertNull($rows[4]['nip']);
    }
}
