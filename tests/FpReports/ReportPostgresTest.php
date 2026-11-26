<?php

declare(strict_types=1);

namespace Tests\FpReports;

use App\Application\FP\FpReports;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\PostgresConnection;
use PHPUnit\Framework\TestCase;

final class ReportPostgresTest extends TestCase
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

    public function test_live_filtered_counts_and_export_rows_match_independent_sql(): void
    {
        $expected = $this->pdo->query("SELECT count(*) FROM financial_planners WHERE lifecycle_state='ACTIVE'")->fetchColumn();
        $filters = ['status' => 'active'];
        self::assertSame((int) $expected, FpReports::query($filters)->count());
        $counts = FpReports::counts($filters);
        self::assertSame($counts['active'], $counts['filtered']);
        self::assertSame($counts['all'], $counts['active'] + $counts['normal'] + $counts['abnormal'] + $counts['unresolved']);
        foreach (FpReports::query($filters)->get() as $row) {
            $values = FpReports::row($row);
            self::assertCount(9, $values);
            self::assertSame('Aktif', $values[5]);
            self::assertArrayNotHasKey('bank_account_encrypted', $row->getAttributes());
        }
    }

    private function virtual(array $filters): array
    {
        $cte = "WITH financial_planners(id,full_name,nip,team_id,reference_date,lifecycle_state,recruitment_path_id,recruitment_path_code_snapshot,recruitment_path_name_snapshot,source_type,source_name_snapshot,legacy_source_raw) AS (VALUES
        (1,'A',1001::bigint,10,'2026-01-01'::date,'ACTIVE',1,'A','Original','HRD','HRD',NULL),
        (2,'B',1002::bigint,10,'2026-01-02'::date,'EXITED',1,'A','Original','HRD','HRD',NULL),
        (3,'C',1003::bigint,20,'2026-01-03'::date,'EXITED',2,'B','Other','TEAM','Other Team',NULL),
        (4,'Legacy',NULL::bigint,10,NULL::date,NULL,NULL,NULL,NULL,NULL,NULL,NULL),
        (5,'Legacy exit',NULL::bigint,10,NULL::date,'EXITED',NULL,NULL,NULL,NULL,NULL,NULL)),
        fp_exits(membership_id,type,exit_date,duration_days) AS (VALUES(2,'NORMAL','2026-03-03'::date,60),(3,'ABNORMAL','2026-03-03'::date,59)) ";
        $q = FpReports::query($filters)->orderBy('financial_planners.id');
        $s = $this->pdo->prepare($cte.$q->toSql());
        $s->execute($q->getBindings());

        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function test_postgres_team_path_date_status_filters_and_unresolved(): void
    {
        self::assertSame([1, 2], array_column($this->virtual(['team_id' => 10, 'path_id' => 1]), 'id'));
        self::assertSame([2], array_column($this->virtual(['from' => '2026-01-02', 'until' => '2026-01-02']), 'id'));
        self::assertSame([2], array_column($this->virtual(['date_field' => 'exit_date', 'from' => '2026-03-03', 'until' => '2026-03-03', 'status' => 'normal']), 'id'));
        self::assertSame([3], array_column($this->virtual(['status' => 'abnormal']), 'id'));
        $legacy = $this->virtual(['status' => 'unresolved']);
        self::assertSame([4, 5], array_column($legacy, 'id'));
        self::assertNull($legacy[0]['nip']);
        self::assertSame([], $this->virtual(['path_id' => 99999]));
    }
}
