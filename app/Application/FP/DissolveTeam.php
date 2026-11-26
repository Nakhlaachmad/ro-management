<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class DissolveTeam
{
    public function execute(User $actor, int $team, array $input): int
    {
        FeatureAccess::require($actor, 'f2.teams');
        $data = Validator::make($input, ['moves' => ['present', 'array'], 'moves.*.membership_id' => ['required', 'integer', 'min:1', 'distinct'],
            'moves.*.destination_team_id' => ['nullable', 'integer', 'min:1'], 'moves.*.new_team_key' => ['nullable', 'string', 'max:50'],
            'new_teams' => ['sometimes', 'array'], 'new_teams.*.key' => ['required', 'string', 'max:50', 'distinct'],
            'new_teams.*.mm_fp_id' => ['required', 'integer', 'min:1'], 'new_teams.*.display_name' => ['required', 'string', 'max:255']])->validate();

        return app(Organization::class)->command($actor, 'DISSOLVE', ['team_id' => $team] + $data);
    }
}
