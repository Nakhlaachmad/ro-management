<?php

declare(strict_types=1);

namespace Tests\FpReports;

use App\Application\FP\FpReports;
use App\Filament\FP\MembershipSorting;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class PremiumReadOnlyPostgresTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (getenv('FP_DASHBOARD_READONLY') !== '1') {
            self::markTestSkipped('Explicit read-only target required');
        }
        $this->pdo = new \PDO('pgsql:host=127.0.0.1;port=55432;dbname=fp_management_synthetic_dev', 'fp_dev_app', (string) getenv('FP_DASHBOARD_PASSWORD'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->beginTransaction();
        $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $id = $this->pdo->query('SELECT current_database() db,current_user role,host(inet_server_addr()) host,inet_server_port() port,(SELECT system_identifier::text FROM pg_control_system()) cluster')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['db' => 'fp_management_synthetic_dev', 'role' => 'fp_dev_app', 'host' => '127.0.0.1', 'port' => 55432, 'cluster' => '7694154787604477244'], $id);
        $r = new \Illuminate\Database\ConnectionResolver(['readonly' => new \Illuminate\Database\PostgresConnection($this->pdo)]);
        $r->setDefaultConnection('readonly');
        Model::setConnectionResolver($r);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private function rows(array $filters, int $page = 1, string $direction = 'asc'): array
    {
        $cte = "WITH financial_planners(id,full_name,nip,team_id,reference_date,lifecycle_state,recruitment_path_id,recruitment_path_code_snapshot,recruitment_path_name_snapshot,source_type,source_name_snapshot,legacy_source_raw) AS (SELECT x,'Candidate '||x,1000+x,1,'2026-10-01'::date,'ACTIVE',1,'A','Reguler','HRD','HRD',NULL FROM generate_series(1,55) x) ";
        $q = MembershipSorting::recruitment(FpReports::query($filters), $direction)->forPage($page, 10);
        $s = $this->pdo->prepare($cte.$q->toSql());
        $s->execute($q->getBindings());

        return $s->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function test_sort_filter_and_multi_page_sql_without_writes(): void
    {
        self::assertSame(range(1, 10), array_column($this->rows([]), 'id'));
        self::assertSame(range(11, 20), array_column($this->rows([], 2), 'id'));
        self::assertSame(range(55, 46), array_column($this->rows([], 1, 'desc'), 'id'));
        self::assertSame([42], array_column($this->rows(['nip' => '1042']), 'id'));
        self::assertSame([], array_column($this->rows(['name' => '%']), 'id'));
        self::assertSame([], array_column($this->rows(['name' => "x' OR 1=1"]), 'id'));
        self::assertCount(1, $this->rows(['name' => 'Candidate 55', 'team_id' => 1, 'path_id' => 1, 'from' => '2026-10-01', 'until' => '2026-10-01']));
    }

    public function test_custom_projection_contains_only_selected_optional_data(): void
    {
        $q = \App\Application\FP\ReportColumns::prepare(FpReports::query([]), ['name', 'nip', 'email']);
        self::assertStringContainsString('"financial_planners"."email"', $q->toSql());
        self::assertStringNotContainsString('encrypted', $q->toSql());
        foreach ($q->limit(10)->get() as $fp) {
            $row = \App\Application\FP\ReportColumns::row($fp,['name', 'nip', 'email']);
            self::assertCount(3,$row);
        }
    }
}
