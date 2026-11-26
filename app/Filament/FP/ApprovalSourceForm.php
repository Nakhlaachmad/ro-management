<?php

declare(strict_types=1);

namespace App\Filament\FP;

use App\Models\FpRecruitmentPath;
use App\Models\FpTeam;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Validator;

final class ApprovalSourceForm
{
    public static function approve(\App\Models\User $actor, int $id, array $input, \Filament\Forms\Form $form): int
    {
        \App\Application\FP\FeatureAccess::require($actor, 'f2.approve');
        try {
            return app(\App\Application\FP\FpEngine::class)->approveFpSubmission($actor, $id, self::source($input));
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $errors = [];
            foreach ($exception->errors() as $field => $messages) {
                $field = in_array($field, ['recruitment_team_id', 'recruitment_path_id', 'source_fp_id', 'source_team_id', 'reference_type', 'approval_mm_name_snapshot'], true) ? $field : (config('fp.recruitment_team_enabled') ? 'recruitment_team_id' : 'recruitment_path_id');
                $errors[$form->getStatePath().'.'.$field] = $messages;
            }
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    public static function fields(): array
    {
        $mm = TextInput::make('approval_mm_name_snapshot')->label('Nama MM')->required()->maxLength(255)->live(onBlur: true)
            ->helperText('Snapshot saat approval, tidak mengubah MM canonical Team.');

        if (config('fp.recruitment_team_enabled')) {
            $source = Select::make('recruitment_team_id')->label('Sumber Rekrutmen')->required()->searchable()->live()
                ->options(fn () => self::teams())
                ->getSearchResultsUsing(fn (string $search) => self::teams($search))
                ->getOptionLabelUsing(fn ($value) => FpTeam::where('is_active', true)->find($value)?->display_name);
        } else {
            $source = Select::make('recruitment_path_id')->label('Sumber Rekrutmen')->required()->searchable()
                ->getSearchResultsUsing(fn (string $search) => self::paths($search))
                ->options(fn () => self::paths())
                ->getOptionLabelUsing(fn ($value) => FpRecruitmentPath::where('is_active', true)->find($value)?->name);
        }

        return [$mm, $source];
    }

    public static function paths(string $search = ''): array
    {
        return FpRecruitmentPath::where('is_active', true)->when($search !== '', fn ($q) => $q->whereLike('name', '%'.$search.'%'))->orderBy('name')->limit(50)->pluck('name', 'id')->all();
    }

    public static function teams(string $search = ''): array
    {
        return FpTeam::where('is_active', true)->when($search !== '', fn ($q) => $q->whereLike('display_name', '%'.$search.'%'))->orderBy('display_name')->orderBy('team_id')->limit(50)->pluck('display_name', 'team_id')->all();
    }

    public static function source(array $input): array
    {
        // The approval form exposes only MM and one recruitment source. Discard
        // injected legacy controls instead of trusting stale browser state.
        unset($input['source_fp_id'], $input['source_type'], $input['reference_type'], $input['source_team_id']);
        $mm = ['approval_mm_name_snapshot' => \App\Application\FP\OnboardingRevision::mmName($input)];

        if (config('fp.recruitment_team_enabled')) {
            return [...\App\Application\FP\RecruitmentTeamSource::normalize($input), ...$mm];
        }

        $path = Validator::make($input, ['recruitment_path_id' => ['required', 'integer', 'min:1']])->validate();

        // FpEngine rechecks the active path under its existing transaction locks.
        return [...$mm, 'recruitment_path_id' => (int) $path['recruitment_path_id'], 'source_type' => 'HRD',
            'source_fp_id' => null, 'source_team_id' => null];
    }
}
