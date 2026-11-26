<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\FpApplicationRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PublicOnboardingFieldsTest extends TestCase
{
    private function input(array $changes = []): array
    {
        return array_replace(['full_name' => 'Synthetic applicant', 'phone' => '81234567890', 'bank_account' => '0012345678', 'nik' => '1234567890123456', 'address_ktp' => 'Synthetic KTP', 'address_current' => 'Synthetic domicile', 'birth_place' => 'Synthetic city', 'birth_date' => '2000-01-01', 'education_level' => 'Sarjana (S1)', 'education_institution' => 'Synthetic school', 'graduation_year' => 2020, 'team_id' => 1, 'idempotency_key' => '00000000-0000-4000-8000-000000000001'], $changes);
    }

    private function request(array $input): FpApplicationRequest
    {
        config(['fp.enabled' => true, 'fp.core_schema_only' => false]);
        $request = FpApplicationRequest::create('/fp-onboarding', 'POST', $input, [], array_fill_keys(['profile_photo', 'ktp', 'ijazah'], UploadedFile::fake()->create('synthetic.png', 1, 'image/png')));
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    public static function validPhones(): array
    {
        return [['8123456789012', '+628123456789012'], ['+628123456789012', '+628123456789012'], ['00628123456789012', '+628123456789012'], ['0123456789', '+620123456789']];
    }

    #[DataProvider('validPhones')]
    public function test_phone_maximum_and_prefix_normalization(string $input, string $expected): void
    {
        $data = $this->request($this->input(['phone' => $input]))->validated();
        self::assertSame($expected, $data['phone']);
        self::assertSame('0012345678', $data['bank_account']);
        self::assertSame([], \Illuminate\Support\Facades\Validator::make($data, \App\Domain\FP\Biodata::rules())->errors()->all());
    }

    public static function invalidInputs(): array
    {
        return [['bank_account', '00123456789'], ['bank_account', '00123456ab'], ['bank_account', '00123456-8'], ['bank_account', '00123456 8'], ['bank_account', 12345678], ['phone', '81234567890123'], ['phone', '+6281234567890123'], ['phone', '81234a6789'], ['phone', '81234-6789'], ['phone', '81234 6789'], ['phone', '+62+628123456789'], ['phone', ['8123456789']], ['education_level', 'Other'], ['education_level', ['Sarjana (S1)']], ['address_current', ''], ['address_same_as_ktp', 'yes']];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_public_values_return_422(string $field, mixed $value): void
    {
        try {
            $this->request($this->input([$field => $value]));
            self::fail('Invalid input accepted');
        } catch (HttpResponseException $e) {
            self::assertSame(422, $e->getResponse()->getStatusCode());
            self::assertArrayHasKey($field, json_decode($e->getResponse()->getContent(), true)['errors']);
        }
    }

    public function test_exactly_ten_education_options_are_accepted(): void
    {
        $expected = ['SD / MI / Sederajat', 'SMP / MTs / Sederajat', 'SMA / SMK / MA / Sederajat', 'Diploma I (D1)', 'Diploma II (D2)', 'Diploma III (D3)', 'Diploma IV (D4) / Sarjana Terapan', 'Sarjana (S1)', 'Magister (S2)', 'Doktor (S3)'];
        self::assertSame($expected, FpApplicationRequest::EDUCATION_LEVELS);
        foreach ($expected as $level) {
            self::assertSame($level, $this->request($this->input(['education_level' => $level]))->validated('education_level'));
        }
    }

    public function test_same_address_uses_latest_ktp_and_ignores_forged_domicile(): void
    {
        foreach (['Synthetic KTP', 'Synthetic updated KTP'] as $address) {
            $request = $this->request($this->input(['address_same_as_ktp' => '1', 'address_ktp' => $address, 'address_current' => 'Ignored synthetic value']));
            self::assertSame($address, $request->validated('address_current'));
        }
    }

    public function test_unchecked_address_is_preserved(): void
    {
        self::assertSame('Synthetic domicile', $this->request($this->input())->validated('address_current'));
        self::assertSame('Synthetic domicile', $this->request($this->input(['address_same_as_ktp' => '0', 'address_ktp' => 'Changed KTP']))->validated('address_current'));
    }

    public function test_saved_values_round_trip_in_isolated_sqlite_with_existing_model_cast(): void
    {
        self::assertSame('sqlite', \Illuminate\Support\Facades\DB::connection()->getDriverName());
        self::assertSame(':memory:', config('database.connections.sqlite.database'));
        \Illuminate\Support\Facades\Schema::create('fp_submissions', function ($table) {
            $table->id();
            $table->text('bank_account_encrypted');
            $table->string('phone');
            $table->text('address_ktp');
            $table->text('address_current');
            $table->string('education_level');
            $table->timestamps();
        });
        foreach ([false, true] as $same) {
            $data = $this->request($this->input(['address_same_as_ktp' => $same ? '1' : '0']))->validated();
            $model = \App\Models\FpSubmission::create(['bank_account_encrypted' => $data['bank_account'], 'phone' => $data['phone'], 'address_ktp' => $data['address_ktp'], 'address_current' => $data['address_current'], 'education_level' => $data['education_level']]);
            $loaded = $model->fresh();
            self::assertSame('0012345678', $loaded->bank_account_encrypted);
            self::assertSame('+6281234567890', $loaded->phone);
            self::assertSame($same ? 'Synthetic KTP' : 'Synthetic domicile', $loaded->address_current);
            self::assertSame('Sarjana (S1)', $loaded->education_level);
            self::assertNotSame('0012345678', $loaded->getRawOriginal('bank_account_encrypted'));
        }
    }
}
