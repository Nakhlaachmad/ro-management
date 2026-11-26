<?php

declare(strict_types=1);

namespace App\Application\FP;

use Illuminate\Support\Facades\Validator;

/** F3.7H rollout: old rows keep their original evidence contract. */
final class OnboardingRevision
{
    public static function enabled(): bool
    {
        return FeatureAccess::enabled() && config('fp.optional_onboarding_enabled') === true;
    }

    public static function documentTypes(): array
    {
        return self::enabled() ? ['KTP', 'IJAZAH'] : ['PROFILE_PHOTO', 'KTP', 'IJAZAH'];
    }

    public static function bank(mixed $value): mixed
    {
        return $value === null || $value === '' ? null : $value;
    }

    public static function mmName(array $source): string
    {
        $source['approval_mm_name_snapshot'] = isset($source['approval_mm_name_snapshot']) && is_string($source['approval_mm_name_snapshot'])
            ? trim($source['approval_mm_name_snapshot']) : null;

        return Validator::make($source, ['approval_mm_name_snapshot' => ['required', 'string', 'max:255', 'regex:/\A[^\p{C}<>]+\z/u']],
            ['approval_mm_name_snapshot.required' => 'Nama MM wajib diisi.', 'approval_mm_name_snapshot.regex' => 'Nama MM tidak boleh berisi karakter kontrol atau markup.'])
            ->validate()['approval_mm_name_snapshot'];
    }
}
