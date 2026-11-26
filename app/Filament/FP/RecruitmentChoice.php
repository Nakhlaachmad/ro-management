<?php

declare(strict_types=1);

namespace App\Filament\FP;

final class RecruitmentChoice
{
    public static function decode(string $choice): array
    {
        if (! preg_match('/\A([1-9][0-9]*):(HRD|TEAM|FP):(0|[1-9][0-9]*)\z/', $choice, $m)
            || (($m[2] === 'HRD') !== ($m[3] === '0'))) {
            throw \Illuminate\Validation\ValidationException::withMessages(['recruitment_choice' => 'Pilih Sumber Rekrutmen yang valid.']);
        }

        return ['recruitment_path_id' => (int) $m[1], 'source_type' => $m[2],
            'source_team_id' => $m[2] === 'TEAM' ? (int) $m[3] : null,
            'source_fp_id' => $m[2] === 'FP' ? (int) $m[3] : null];
    }

    public static function label(string $choice): ?string
    {
        $data = self::decode($choice);
        $path = \App\Models\FpRecruitmentPath::where('is_active', true)->find($data['recruitment_path_id']);
        if (! $path) {
            return null;
        }
        $source = match ($data['source_type']) {
            'HRD' => 'HRD',
            'TEAM' => \App\Models\FpTeam::where('is_active', true)->find($data['source_team_id'])?->display_name,
            'FP' => \App\Models\FpMembership::where('lifecycle_state', 'ACTIVE')->find($data['source_fp_id'])?->full_name,
        };

        return $source ? $path->code.' — '.$path->name.' / '.$data['source_type'].' · '.$source : null;
    }

    public static function options(string $search = ''): array
    {
        $sources = ['HRD:0' => 'HRD'];
        foreach (\App\Models\FpTeam::where('is_active', true)->when($search !== '', fn ($q) => $q->whereLike('display_name', '%'.$search.'%'))->orderBy('team_id')->limit(30)->get(['team_id', 'display_name']) as $team) {
            $sources['TEAM:'.$team->team_id] = 'Team · '.$team->display_name;
        }
        foreach (\App\Models\FpMembership::where('lifecycle_state', 'ACTIVE')->when($search !== '', fn ($q) => $q->whereLike('full_name', '%'.$search.'%'))->orderBy('id')->limit(30)->get(['id', 'full_name']) as $fp) {
            $sources['FP:'.$fp->id] = 'FP · '.$fp->full_name;
        }
        $options = [];
        foreach (\App\Models\FpRecruitmentPath::where('is_active', true)->orderBy('code')->limit(30)->get(['id', 'code', 'name']) as $path) {
            foreach ($sources as $key => $source) {
                $label = $path->code.' — '.$path->name.' / '.$source;
                if ($search === '' || mb_stripos($label, $search) !== false) {
                    $options[$path->id.':'.$key] = $label;
                }
                if (count($options) >= 200) {
                    return $options;
                }
            }
        }

        return $options;
    }
}
