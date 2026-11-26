<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class FpApplicationRequest extends FormRequest
{
    public const EDUCATION_LEVELS = [
        'SD / MI / Sederajat',
        'SMP / MTs / Sederajat',
        'SMA / SMK / MA / Sederajat',
        'Diploma I (D1)',
        'Diploma II (D2)',
        'Diploma III (D3)',
        'Diploma IV (D4) / Sarjana Terapan',
        'Sarjana (S1)',
        'Magister (S2)',
        'Doktor (S3)',
    ];

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => \Illuminate\Support\Str::lower($this->input('email'))]);
        }
        if (\App\Application\FP\OnboardingRevision::enabled()) {
            $this->merge(['bank_account' => \App\Application\FP\OnboardingRevision::bank($this->input('bank_account'))]);
        }
        $phone = $this->input('phone');
        if (is_string($phone)) {
            // Accept national digits or one explicit Indonesian country prefix.
            // Malformed input must fail validation rather than silently lose characters.
            $national = preg_replace('/\A(?:\+62|0062)/', '', $phone, 1);
            $this->merge(['phone' => '+62'.$national]);
        }
        if ($this->boolean('address_same_as_ktp')) {
            $this->merge(['address_current' => $this->input('address_ktp')]);
        }
    }

    public function authorize(): bool
    {
        return \App\Application\FP\FeatureAccess::enabled();
    }

    public function rules(): array
    {
        return [
            ...\App\Domain\FP\Biodata::rules(),
            // Keep existing minimum lengths required by the unchanged FP engine.
            'email' => config('fp.annual_nip_email_enabled') ? ['required', 'string', 'email:rfc', 'max:254'] : ['exclude'],
            'bank_account' => [\App\Application\FP\OnboardingRevision::enabled() ? 'nullable' : 'required', 'string', 'regex:/\A[0-9]{6,10}\z/'],
            'phone' => ['required', 'string', 'regex:/\A\+62[0-9]{5,13}\z/'],
            'education_level' => ['required', 'string', \Illuminate\Validation\Rule::in(self::EDUCATION_LEVELS)],
            'address_same_as_ktp' => ['sometimes', 'boolean'],
            'idempotency_key' => 'required|uuid',
            'profile_photo' => \App\Application\FP\OnboardingRevision::enabled() ? 'prohibited' : 'required|file|max:8192',
            'ktp' => (\App\Application\FP\OnboardingRevision::enabled() ? 'nullable' : 'required').'|file|max:8192',
            'ijazah' => (\App\Application\FP\OnboardingRevision::enabled() ? 'nullable' : 'required').'|file|max:8192',
        ];
    }

    public function messages(): array
    {
        return ['email.required' => 'Email wajib diisi.', 'email.email' => 'Format email tidak valid.', 'email.string' => 'Email harus berupa teks.', 'email.max' => 'Email maksimal 254 karakter.'];
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(response()->json(['message' => 'Periksa kembali data dan dokumen.', 'errors' => $validator->errors()], 422));
    }
}
