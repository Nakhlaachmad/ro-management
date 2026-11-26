<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\FpMembership;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AmendFpBiodata
{
    public const FIELDS = ['full_name', 'email', 'phone', 'bank_account', 'address_ktp', 'address_current', 'birth_place', 'birth_date', 'education_level', 'education_institution', 'graduation_year'];

    public function execute(User $actor, int $id, array $input): int
    {
        FeatureAccess::require($actor, 'f2.biodata');
        if (array_diff(array_keys($input), [...self::FIELDS, 'expected_revision'])) {
            throw ValidationException::withMessages(['biodata' => 'Field identitas, NIP, status dan organisasi tidak dapat diubah di sini.']);
        }
        $member = FpMembership::findOrFail($id);
        if ($member->lifecycle_state?->value !== 'ACTIVE' && ! (OnboardingRevision::enabled() && VictoryAccess::role($actor) === 'admin')) {
            throw ValidationException::withMessages(['biodata' => 'Riwayat FP yang sudah keluar tetap terlindungi.']);
        }
        $rules = array_intersect_key(\App\Domain\FP\Biodata::rules(), array_flip(self::FIELDS));
        $rules['expected_revision'] = ['required', 'integer', 'min:0'];
        if (OnboardingRevision::enabled()) {
            $input['bank_account'] = OnboardingRevision::bank($input['bank_account'] ?? null);
        }
        $input['email'] = isset($input['email']) ? mb_strtolower(trim($input['email'])) : null;
        $data = Validator::make($input, $rules)->validate();
        $before = [];
        foreach (self::FIELDS as $field) {
            $before[$field] = $field === 'bank_account' ? $member->bank_account_encrypted : ($field === 'birth_date' ? $member->birth_date?->format('Y-m-d') : $member->$field);
        }
        $after = array_intersect_key($data, array_flip(self::FIELDS));
        $profile = $after;
        unset($profile['bank_account']);
        $profile['bank_account_encrypted'] = ($after['bank_account'] ?? null) === null ? null : Crypt::encryptString($after['bank_account']);
        $profile['phone_normalized'] = preg_replace('/[^+0-9]/', '', $after['phone']);

        return app(Organization::class)->command($actor, 'AMEND', ['membership_id' => $id, 'expected_revision' => $data['expected_revision'], 'data' => $profile,
            'before_encrypted' => Crypt::encryptString(json_encode($before, JSON_THROW_ON_ERROR)),
            'after_encrypted' => Crypt::encryptString(json_encode($after, JSON_THROW_ON_ERROR))]);
    }

    public static function revision(int $id): int
    {
        return (int) DB::table('fp_membership_profiles')->where('membership_id', $id)->value('revision');
    }
}
