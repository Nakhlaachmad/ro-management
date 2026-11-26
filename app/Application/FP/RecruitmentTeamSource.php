<?php

declare(strict_types=1);

namespace App\Application\FP;

use Illuminate\Support\Facades\Validator;

final class RecruitmentTeamSource
{
    public static function normalize(array $input): array
    {
        $data = Validator::make($input, [
            'recruitment_team_id' => ['required', 'integer', 'min:1'],
            'source_fp_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
        $fp = empty($data['source_fp_id']) ? null : (int) $data['source_fp_id'];

        return ['recruitment_team_id' => (int) $data['recruitment_team_id'], 'source_type' => $fp ? 'FP' : 'TEAM', 'source_fp_id' => $fp, 'source_team_id' => $fp ? null : (int) $data['recruitment_team_id']];
    }
}
