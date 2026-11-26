<?php

declare(strict_types=1);

namespace App\Domain\FP;

final class Biodata
{
    public const FIELDS = ['full_name', 'email', 'phone', 'bank_account', 'nik', 'address_ktp', 'address_current', 'birth_place', 'birth_date', 'education_level', 'education_institution', 'graduation_year', 'team_id'];

    public static function rules(): array
    {
        return [
            'full_name' => 'required|string|max:255', 'email' => config('fp.annual_nip_email_enabled') ? 'nullable|string|email:rfc|max:254' : 'exclude', 'phone' => 'required|regex:/\A[+0-9][0-9 ()-]{7,30}\z/',
            'bank_account' => \App\Application\FP\OnboardingRevision::enabled() ? 'nullable|string|regex:/\A[0-9]{6,10}\z/' : 'required|regex:/\A[0-9]{6,30}\z/', 'nik' => 'required|regex:/\A[0-9]{16}\z/',
            'address_ktp' => 'required|string|max:4000', 'address_current' => 'required|string|max:4000', 'birth_place' => 'required|string|max:255',
            'birth_date' => 'required|date_format:Y-m-d|before:today', 'education_level' => 'required|string|max:255',
            'education_institution' => 'required|string|max:255', 'graduation_year' => 'required|integer|min:1900|max:'.date('Y'), 'team_id' => 'required|integer|min:1'];
    }
}
