<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\FP\OnboardingRevision;
use App\Http\Requests\FpApplicationRequest;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class OptionalOnboardingRevisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['fp.enabled' => true, 'fp.core_schema_only' => false, 'fp.optional_onboarding_enabled' => true, 'fp.annual_nip_email_enabled' => true]);
    }

    private function request(array $changes = [], array $files = []): FpApplicationRequest
    {
        $data = array_replace(['full_name' => 'Synthetic F37H', 'email' => 'QA@EXAMPLE.TEST', 'phone' => '81234567890', 'bank_account' => '', 'nik' => '1234567890123456', 'address_ktp' => 'Synthetic address', 'address_current' => 'Synthetic domicile', 'birth_place' => 'Synthetic city', 'birth_date' => '2000-01-01', 'education_level' => 'Sarjana (S1)', 'education_institution' => 'Synthetic school', 'graduation_year' => 2020, 'team_id' => 1, 'idempotency_key' => '00000000-0000-4000-8000-000000000001'], $changes);
        $request = FpApplicationRequest::create('/fp-onboarding', 'POST', $data, [], $files);
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        return $request;
    }

    public static function optionalCombinations(): array
    {
        return [[[]], [['ktp']], [['ijazah']], [['ktp', 'ijazah']]];
    }

    #[DataProvider('optionalCombinations')]
    public function test_empty_bca_and_optional_document_combinations_validate(array $types): void
    {
        $files = [];
        foreach ($types as $type) {
            $files[$type] = UploadedFile::fake()->image('synthetic.png', 320, 320);
        }
        $data = $this->request(files: $files)->validated();
        self::assertNull($data['bank_account']);
        self::assertSame('qa@example.test', $data['email']);
        self::assertSame(['KTP', 'IJAZAH'], OnboardingRevision::documentTypes());
        self::assertArrayNotHasKey('profile_photo', $data);
    }

    public function test_bca_preserves_zeroes_and_rejects_non_digits(): void
    {
        self::assertSame('0012345678', $this->request(['bank_account' => '0012345678'])->validated('bank_account'));
        foreach (['00123456789', '123456ab', ['123456']] as $bank) {
            try {
                $this->request(['bank_account' => $bank]);
                self::fail('Malformed BCA accepted');
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
                self::assertSame(422, $error->getResponse()->getStatusCode());
            }
        }
    }

    public function test_retired_photo_is_rejected_not_silently_staged(): void
    {
        try {
            $this->request(files: ['profile_photo' => UploadedFile::fake()->image('synthetic.png')]);
            self::fail('Retired photo accepted');
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
            self::assertArrayHasKey('profile_photo', json_decode($error->getResponse()->getContent(), true)['errors']);
        }
    }

    public function test_manual_mm_is_trimmed_and_rejects_empty_markup_and_controls(): void
    {
        self::assertSame('MM Synthetic', OnboardingRevision::mmName(['approval_mm_name_snapshot' => '  MM Synthetic  ']));
        foreach (['', '<script>', "MM\nInjected", str_repeat('x', 256)] as $value) {
            try {
                OnboardingRevision::mmName(['approval_mm_name_snapshot' => $value]);
                self::fail('Invalid MM snapshot accepted');
            } catch (\Illuminate\Validation\ValidationException $error) {
                self::assertArrayHasKey('approval_mm_name_snapshot', $error->errors());
            }
        }
    }

    public function test_manual_verification_service_entry_points_are_retired(): void
    {
        $actor = \Tests\VictoryAccess\RoleMatrixTest::actor(['admin']);
        foreach (['verifySubmissionBiodata', 'verifySubmissionDocuments'] as $method) {
            try {
                app(\App\Application\FP\FpEngine::class)->$method($actor, 999999);
                self::fail('Retired verification action reached persistence');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                self::assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function test_approval_actions_do_not_require_manual_review_and_bm_cannot_approve(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]));
            $record = new \App\Models\FpSubmission;
            $record->forceFill(['status' => 'PENDING', 'biodata_revision' => 1, 'document_revision' => 1]);
            $actions = array_map(fn ($action) => $action->record($record), \App\Filament\Resources\FpSubmissionResource::reviewActions(true));
            $names = array_map(fn ($action) => $action->getName(), $actions);
            self::assertNotContains('verifyBiodata', $names);
            self::assertNotContains('verifyDocuments', $names);
            $approve = $actions[array_search('approve', $names, true)];
            self::assertSame($role === 'bm', $approve->isDisabled(), $role);
            self::assertSame($role !== 'bm', $approve->isVisible());
        }
    }

    public function test_unauthorized_roles_cannot_amend_documents(): void
    {
        foreach (['ro', 'bm'] as $role) {
            try {
                app(\App\Application\FP\AmendFpDocument::class)->execute(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]), 1, 'KTP', 0, null);
                self::fail('Unauthorized role reached document storage');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                self::assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function test_hrd_reaches_document_amendment_validation(): void
    {
        try {
            app(\App\Application\FP\AmendFpDocument::class)->execute(\Tests\VictoryAccess\RoleMatrixTest::actor(['hrd']), 1, 'KTP', 0, null);
            self::fail('Validation was not reached');
        } catch (\Illuminate\Validation\ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_optional_upload_pipeline_keeps_secure_reencoding_and_never_fabricates_documents(): void
    {
        $storage = new class implements \App\Infrastructure\FP\DocumentStorage
        {
            public array $files = [];

            public function put(string $path, string $bytes): void
            {
                $this->files[$path] = $bytes;
            }

            public function read(string $path): string
            {
                return $this->files[$path];
            }

            public function delete(string $path): void
            {
                unset($this->files[$path]);
            }

            public function adapter(): string
            {
                return 'local';
            }
        };
        $uploads = new \App\Infrastructure\FP\Uploads($storage, new \App\Infrastructure\FP\SecureImage);
        self::assertSame([], $uploads->stage(['KTP' => null, 'IJAZAH' => null]));
        self::assertSame([], $storage->files);
        $documents = $uploads->stage(['KTP' => UploadedFile::fake()->image('synthetic.png', 320, 320), 'IJAZAH' => null]);
        self::assertCount(1, $documents);
        self::assertSame('KTP', $documents[0]['type']);
        self::assertSame('image/jpeg', $documents[0]['mime']);
        self::assertSame(hash('sha256', $storage->read($documents[0]['private_path'])), $documents[0]['sha256']);
        $uploads->discard($documents);
        self::assertSame([], $storage->files);
        try {
            $uploads->stage(['IJAZAH' => UploadedFile::fake()->createWithContent('bad.png', '<?php echo 1;')]);
            self::fail('Unsafe optional upload accepted');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $storage->files);
        }
    }

    public function test_admin_can_correct_historical_display_biodata_without_expanding_other_roles(): void
    {
        config(['fp.organization_enabled' => true]);
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]));
            $record = new \App\Models\FpMembership;
            $record->forceFill(['lifecycle_state' => 'EXITED']);
            self::assertSame($role === 'admin', \App\Filament\Resources\FpMembershipResource::canEdit($record), $role);
        }
        config(['fp.optional_onboarding_enabled' => false]);
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor(['admin']));
        self::assertFalse(\App\Filament\Resources\FpMembershipResource::canEdit($record));
    }

    public function test_rendered_form_has_optional_documents_and_bca_without_photo_input(): void
    {
        $this->withoutVite();
        \Illuminate\Support\Facades\Route::post('/synthetic-onboarding', fn () => null)->name('fp.submit');
        \Illuminate\Support\Facades\Route::getRoutes()->refreshNameLookups();
        $html = view('fp.onboarding', ['teams' => collect(), 'uploadLimits' => ['file_bytes' => 8388608, 'total_bytes' => 16777216, 'file_label' => '8 MB']])->render();
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);
        self::assertSame(0, $xpath->query('//input[@name="profile_photo"]')->length);
        foreach (['bank_account', 'ktp', 'ijazah'] as $field) {
            $input = $xpath->query('//input[@name="'.$field.'"]')->item(0);
            self::assertNotNull($input);
            self::assertFalse($input->hasAttribute('required'), $field);
        }
        self::assertSame('true', $xpath->query('//form[@id="fp-form"]')->item(0)->getAttribute('data-optional-documents'));
        self::assertStringContainsString('Dokumen tidak harus diisi untuk ke langkah selanjutnya', $html);
        self::assertStringContainsString('admin yang mengisi sebelum maupun setelah pendaftaran disetujui', $html);
    }

    public function test_approval_form_is_limited_to_mm_and_recruitment_source(): void
    {
        foreach ([true, false] as $optional) {
            foreach ([true, false] as $teamEnabled) {
                config(['fp.optional_onboarding_enabled' => $optional, 'fp.recruitment_team_enabled' => $teamEnabled]);
                $names = array_map(fn ($field) => $field->getName(), \App\Filament\FP\ApprovalSourceForm::fields());
                $source = $teamEnabled ? 'recruitment_team_id' : 'recruitment_path_id';

                self::assertSame(['approval_mm_name_snapshot', $source], $names);
                self::assertNotContains('source_fp_id', $names);
                self::assertNotContains('approval_summary', $names);
            }
        }
    }

    public function test_approval_source_ignores_injected_recruiter_fields(): void
    {
        foreach ([true, false] as $optional) {
            config(['fp.optional_onboarding_enabled' => $optional, 'fp.recruitment_team_enabled' => true]);
            $source = \App\Filament\FP\ApprovalSourceForm::source([
                'recruitment_team_id' => 8,
                'source_fp_id' => 4,
                'reference_type' => 'TEAM',
                'source_team_id' => 999,
                'approval_mm_name_snapshot' => 'MM Synthetic',
            ]);

            self::assertSame(8, $source['recruitment_team_id']);
            self::assertSame('TEAM', $source['source_type']);
            self::assertNull($source['source_fp_id']);
            self::assertSame(8, $source['source_team_id']);
        }
    }

    public function test_unauthorized_roles_cannot_fill_pending_documents(): void
    {
        foreach (['ro', 'bm'] as $role) {
            try {
                app(\App\Application\FP\FillSubmissionDocument::class)->execute(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]), 1, 'KTP', null);
                self::fail('Unauthorized role reached pending document storage');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                self::assertSame(403, $error->getStatusCode());
            }
        }
    }

    public function test_hrd_reaches_pending_document_fill_validation(): void
    {
        try {
            app(\App\Application\FP\FillSubmissionDocument::class)->execute(\Tests\VictoryAccess\RoleMatrixTest::actor(['hrd']), 1, 'KTP', null);
            self::fail('Validation was not reached');
        } catch (\Illuminate\Validation\ValidationException) {
            self::addToAssertionCount(1);
        }
    }

    public function test_submission_review_exposes_document_fill_to_admin_and_hrd(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor([$role]));
            $pending = new \App\Models\FpSubmission;
            $pending->forceFill(['status' => 'PENDING']);
            $approved = new \App\Models\FpSubmission;
            $approved->forceFill(['status' => 'APPROVED']);
            $approved->setRelation('membership', new \App\Models\FpMembership(['id' => 77]));
            foreach ([$pending, $approved] as $record) {
                $actions = \App\Filament\Resources\FpSubmissionResource::reviewActions(true);
                $action = collect($actions)->first(fn ($action) => $action->getName() === 'amendDocument');
                self::assertNotNull($action);
                self::assertSame(in_array($role, ['admin', 'hrd'], true), $action->record($record)->isVisible(), $role);
            }
        }
    }

    public function test_inline_document_upload_is_available_to_hrd_and_admin_for_pending_and_approved(): void
    {
        foreach (['admin', 'hrd', 'ro', 'bm'] as $role) {
            $actor = \Tests\VictoryAccess\RoleMatrixTest::actor([$role]);
            $pending = new \App\Models\FpSubmission;
            $pending->forceFill(['status' => 'PENDING']);
            $approved = new \App\Models\FpSubmission;
            $approved->forceFill(['status' => 'APPROVED']);
            $approved->setRelation('membership', new \App\Models\FpMembership(['id' => 77]));
            $rejected = new \App\Models\FpSubmission;
            $rejected->forceFill(['status' => 'REJECTED']);
            $allowed = in_array($role, ['admin', 'hrd'], true);

            self::assertSame($allowed, \App\Filament\Resources\FpSubmissionResource::canManageInlineDocuments($actor, $pending), $role);
            self::assertSame($allowed, \App\Filament\Resources\FpSubmissionResource::canManageInlineDocuments($actor, $approved), $role);
            self::assertFalse(\App\Filament\Resources\FpSubmissionResource::canManageInlineDocuments($actor, $rejected), $role);
        }
    }

    public function test_submission_table_includes_document_fill_action(): void
    {
        auth()->setUser(\Tests\VictoryAccess\RoleMatrixTest::actor(['hrd']));
        $table = \App\Filament\Resources\FpSubmissionResource::table(\Filament\Tables\Table::make($this->createMock(\Filament\Tables\Contracts\HasTable::class)));
        $names = array_map(fn ($action) => $action->getName(), $table->getActions());

        self::assertContains('amendDocument', $names);
    }
}
