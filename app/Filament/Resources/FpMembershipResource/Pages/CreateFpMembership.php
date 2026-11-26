<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

use App\Application\FP\RegisterFpFromPanel;
use App\Filament\Resources\FpMembershipResource;
use Filament\Forms\Form;

final class CreateFpMembership extends \Filament\Resources\Pages\Page
{
    protected static string $resource = FpMembershipResource::class;

    protected static string $view = 'filament.fp.panel-register';

    protected static ?string $title = 'Tambah Financial Planner';

    public array $data = [];

    public function mount(): void
    {
        abort_unless(FpMembershipResource::canCreate(), 403);
        $this->form->fill(['idempotency_key' => (string) \Illuminate\Support\Str::uuid()]);
    }

    public function form(Form $form): Form
    {
        $fields = \App\Filament\FP\FpForms::biodata();
        foreach ($fields as $index => $field) {
            if ($field->getName() === 'education_level') {
                $fields[$index] = \Filament\Forms\Components\Select::make('education_level')->label('Pendidikan terakhir')->options(array_combine(\App\Http\Requests\FpApplicationRequest::EDUCATION_LEVELS, \App\Http\Requests\FpApplicationRequest::EDUCATION_LEVELS))->required();
            }
            if ($field->getName() === 'team_id') {
                $field->label('Sumber Rekrutmen');
            }
            if ($field->getName() === 'email') {
                $field->required();
            }
            if ($field->getName() === 'bank_account') {
                $field->minLength(6)->maxLength(10)->regex('/\A[0-9]{6,10}\z/')->extraInputAttributes(['inputmode' => 'numeric', 'pattern' => '[0-9]{6,10}']);
            }
            if ($field->getName() === 'phone') {
                $field->prefix('+62')->minLength(5)->maxLength(13)->regex('/\A[0-9]{5,13}\z/')->extraInputAttributes(['inputmode' => 'numeric', 'pattern' => '[0-9]{5,13}']);
            }
        }
        $files = [];
        foreach ((\App\Application\FP\OnboardingRevision::enabled() ? ['ktp' => 'KTP', 'ijazah' => 'Ijazah'] : ['profile_photo' => 'Foto Profil', 'ktp' => 'KTP', 'ijazah' => 'Ijazah']) as $key => $label) {
            $files[] = \Filament\Forms\Components\FileUpload::make($key)->label($label)->required(fn () => ! \App\Application\FP\OnboardingRevision::enabled())->acceptedFileTypes(['image/jpeg', 'image/png'])->maxSize(8192)->storeFiles(false)->visibility('private')->disk('local');
        }

        return $form->statePath('data')->schema([
            \Filament\Forms\Components\Hidden::make('idempotency_key')->required(),
            \Filament\Forms\Components\Section::make('Biodata')->description('Disimpan sebagai PENDING. NIP hanya diterbitkan saat approval berhasil.')->schema($fields)->columns(2),
            \Filament\Forms\Components\Section::make('Dokumen privat')->schema($files),
        ]);
    }

    public function submit(): void
    {
        abort_unless(FpMembershipResource::canCreate(), 403);
        $data = $this->form->getState();
        $key = 'fp-panel-register:'.auth()->id();
        abort_if(\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5), 429);
        \Illuminate\Support\Facades\RateLimiter::hit($key, 60);
        try {
            $receipt = app(RegisterFpFromPanel::class)->execute(auth()->user(), \Illuminate\Support\Arr::except($data, ['profile_photo', 'ktp', 'ijazah', 'idempotency_key']),
                [...(\App\Application\FP\OnboardingRevision::enabled() ? [] : ['PROFILE_PHOTO' => $data['profile_photo'] ?? null]), 'KTP' => $data['ktp'] ?? null, 'IJAZAH' => $data['ijazah'] ?? null], $data['idempotency_key']);
        } catch (\Illuminate\Validation\ValidationException $error) {
            $errors = [];
            foreach ($error->errors() as $field => $messages) {
                $errors['data.'.$field] = $messages;
            }
            \Filament\Notifications\Notification::make()->danger()->title('Pengajuan belum tersimpan. Periksa data yang ditandai.')->send();
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        } catch (\InvalidArgumentException) {
            throw \Illuminate\Validation\ValidationException::withMessages(['data.ktp' => 'Dokumen harus gambar JPEG/PNG yang jelas, aman, dan sesuai batas ukuran.']);
        }
        $submission = \App\Models\FpSubmission::where('uuid', $receipt['receipt'])->firstOrFail();
        \Filament\Notifications\Notification::make()->success()->title('Pengajuan tersimpan — PENDING')->body('Lanjutkan pemeriksaan dan approval.')->send();
        $this->redirect(\App\Filament\Resources\FpSubmissionResource::getUrl('view', ['record' => $submission->id], panel: 'fp-dev'));
    }
}
