<?php

declare(strict_types=1);

namespace App\Application\FP;

use Illuminate\Database\Eloquent\Builder;

final class FpReports
{
    public const COLUMNS = ['financial_planners.id', 'full_name', 'nip', 'team_id', 'reference_date', 'lifecycle_state', 'recruitment_path_id', 'recruitment_path_code_snapshot', 'recruitment_path_name_snapshot', 'source_type', 'source_name_snapshot', 'legacy_source_raw'];

    public static function query(array $filters): Builder
    {
        $status = $filters['status'] ?? 'all';
        if (! array_key_exists($status, ReportFilters::STATUSES)) {
            throw new \InvalidArgumentException('Unknown report status');
        }
        $q = FpDirectory::members($status === 'unresolved' ? 'all' : $status);
        if ($status === 'unresolved') {
            $q->where(fn (Builder $x) => $x->whereNull('lifecycle_state')->orWhere(fn (Builder $e) => $e->where('lifecycle_state', 'EXITED')->whereDoesntHave('exit')));
        }
        foreach (['team_id' => 'team_id', 'path_id' => 'recruitment_path_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $q->where($column, $filters[$filter]);
            }
        }
        if (! empty($filters['recruitment_team_id'])) {
            $q->where('recruitment_contract_version', 2)->where('recruitment_team_id', $filters['recruitment_team_id']);
        }
        if (! empty($filters['name'])) {
            $name = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['name']);
            $q->where('full_name', 'ilike', '%'.$name.'%');
        }
        if (isset($filters['nip']) && $filters['nip'] !== '') {
            \App\Filament\FP\NipSearch::apply($q, (string) $filters['nip']);
        }
        $field = $filters['date_field'] ?? 'reference_date';
        if (! in_array($field, ['reference_date', 'exit_date'], true)) {
            throw new \InvalidArgumentException('Unknown report date field');
        }
        $dates = function (Builder $x) use ($filters, $field) {
            if (! empty($filters['from'])) {
                $x->whereDate($field, '>=', $filters['from']);
            }
            if (! empty($filters['until'])) {
                $x->whereDate($field, '<=', $filters['until']);
            }
        };
        if ($field === 'exit_date' && (! empty($filters['from']) || ! empty($filters['until']))) {
            $q->whereHas('exit', $dates);
        } elseif ($field === 'reference_date') {
            $dates($q);
        }

        $columns = self::COLUMNS;
        if (config('fp.recruitment_team_enabled')) {
            $columns = [...$columns, 'recruitment_contract_version', 'recruitment_team_id', 'recruitment_team_name_snapshot'];
        }
        return $q->select($columns)->with(['team:team_id,display_name', 'exit:membership_id,exit_date,duration_days,type']);
    }

    public static function counts(array $filters): array
    {
        $counts = ['filtered' => self::query($filters)->count()];
        foreach (array_keys(ReportFilters::STATUSES) as $status) {
            $counts[$status] = self::query(array_replace($filters, ['status' => $status]))->count();
        }

        return $counts;
    }

    public static function allowed(?\App\Models\User $user, bool $export = false): bool
    {
        return FeatureAccess::allowed($user, 'f2.view') && FeatureAccess::allowed($user, 'f3.reports.view') && (! $export || FeatureAccess::allowed($user, 'f3.reports.export'));
    }

    public static function row(\App\Models\FpMembership $fp): array
    {
        $state = $fp->lifecycle_state?->value;
        $type = $fp->exit?->type?->value;
        $status = $state === 'ACTIVE' ? 'Aktif' : ($state === 'EXITED' && $type ? ($type === 'NORMAL' ? 'Keluar Normal' : 'Keluar Tidak Normal') : 'Belum terklasifikasi');

        return [$fp->id, $fp->full_name, $fp->nip ?? '', $fp->team?->display_name ?? '', RecruitmentPresentation::label($fp) ?? '', $status, $fp->reference_date?->format('Y-m-d') ?? '', $fp->exit?->exit_date?->format('Y-m-d') ?? '', $fp->exit?->duration_days ?? ''];
    }
}
