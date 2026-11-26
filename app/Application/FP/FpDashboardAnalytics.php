<?php

declare(strict_types=1);

namespace App\Application\FP;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class FpDashboardAnalytics
{
    public static function bounds(?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now(ReportPeriods::timezone()))->setTimezone(ReportPeriods::timezone());

        return [$now->startOfMonth(), $now->endOfMonth()];
    }

    public static function snapshot(?CarbonImmutable $now = null): array
    {
        [$start,$end] = self::bounds($now);
        $dates = [$start->toDateString(), $end->toDateString()];
        $teams = \App\Models\FpTeam::where('is_active', true)->withCount([
            'memberships as active_count' => fn ($q) => $q->where('lifecycle_state', 'ACTIVE'),
            'memberships as joined_count' => fn ($q) => $q->whereBetween('reference_date', $dates),
            'memberships as exited_count' => fn ($q) => $q->whereHas('exit', fn ($e) => $e->whereBetween('exit_date', $dates)),
        ])->orderBy('display_name')->limit(50)->get(['team_id', 'display_name']);
        $columns = ['recruitment_path_code_snapshot', 'recruitment_path_name_snapshot', 'source_type', 'source_name_snapshot', 'legacy_source_raw'];
        if (config('fp.recruitment_team_enabled')) {
            $columns = [...$columns, 'recruitment_contract_version', 'recruitment_team_name_snapshot'];
        }
        $sources = FpDirectory::members()->whereBetween('reference_date', $dates)->select($columns)->selectRaw('count(*) as total')->groupBy($columns)->get();
        $breakdown = [];
        foreach ($sources as $source) {
            $label = RecruitmentPresentation::label($source);
            $breakdown[$label] = ($breakdown[$label] ?? 0) + (int) $source->total;
        }
        arsort($breakdown);

        return ['period' => $start->locale('id')->translatedFormat('F Y'), 'timezone' => ReportPeriods::timezone(), 'teams' => $teams, 'team_count' => \App\Models\FpTeam::where('is_active', true)->count(), 'sources' => $breakdown, 'joined' => array_sum($breakdown)];
    }

    public static function series(?CarbonImmutable $now = null): array
    {
        [$month,$end] = self::bounds($now);
        $start = $month->subMonths(5);
        $range = [$start->toDateString(), $end->toDateString()];
        $joined = FpDirectory::members()->whereBetween('reference_date', $range)->selectRaw("to_char(reference_date,'YYYY-MM') as month,count(*) as total")->groupByRaw("to_char(reference_date,'YYYY-MM')")->pluck('total', 'month')->all();
        $exited = DB::table('fp_exits')->whereIn('membership_id', FpDirectory::members()->select('id'))->whereBetween('exit_date', $range)->selectRaw("to_char(exit_date,'YYYY-MM') as month,count(*) as total")->groupByRaw("to_char(exit_date,'YYYY-MM')")->pluck('total', 'month')->all();
        $base = FpDirectory::members()->where('reference_date', '<', $start->toDateString())->count();

        return self::assemble($start, $joined, $exited, $base);
    }

    public static function assemble(CarbonImmutable $start, array $joined, array $exited, int $base): array
    {
        $result = ['labels' => [], 'joined' => [], 'exited' => [], 'cumulative' => []];
        for ($i = 0; $i < 6; $i++) {
            $date = $start->addMonths($i);
            $key = $date->format('Y-m');
            $in = (int) ($joined[$key] ?? 0);
            $base += $in;
            $result['labels'][] = $date->locale('id')->translatedFormat('M Y');
            $result['joined'][] = $in;
            $result['exited'][] = (int) ($exited[$key] ?? 0);
            $result['cumulative'][] = $base;
        }

        return $result;
    }
}
