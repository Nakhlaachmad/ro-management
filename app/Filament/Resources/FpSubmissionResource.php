<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpEngine;
use App\Filament\FP\FpForms;
use App\Models\FpSubmission;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class FpSubmissionResource extends Resource
{
    protected static ?string $model = FpSubmission::class;

    protected static ?string $navigationLabel = 'Validasi Pendaftar';

    protected static ?string $navigationGroup = 'Financial Planner';

    protected static ?string $slug = 'fp-submissions';

    public static function canViewAny(): bool
    {
        return FeatureAccess::allowed(auth()->user(), 'f2.view');
    }

    public static function canView($record): bool
    {
        return self::canViewAny();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['team', 'canonicalTeam', 'identity.memberships.team', 'identity.memberships.exit', 'documents', 'membership']);
    }

    /** @return array<int, string> */
    public static function getGloballySearchableAttributes(): array
    {
        // The public receipt UUID is the primary reference an applicant gives to HRD.
        return ['uuid', 'full_name'];
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('uuid')->label('Kode penerimaan')->searchable()->copyable()->toggleable()->limit(12)->tooltip(fn ($state) => $state),
            Tables\Columns\TextColumn::make('full_name')->label('Nama')->searchable()->sortable(),
            Tables\Columns\TextColumn::make(config('fp.recruitment_team_enabled') ? 'canonicalTeam.display_name' : 'team.team')->label('Team')->sortable(),
            Tables\Columns\TextColumn::make('status')->sortable()->formatStateUsing(fn ($state) => $state->value)->badge(),
            Tables\Columns\TextColumn::make('submitted_at')->label('Dikirim')->sortable()->dateTime('d/m/Y H:i'),
            Tables\Columns\TextColumn::make('returning')->label('Riwayat')->state(fn ($record) => $record->identity->memberships->isNotEmpty() ? 'Pernah menjadi FP' : 'Baru'),
        ])->filters([Tables\Filters\SelectFilter::make('status')->placeholder('Semua status')->options(['PENDING' => 'Pending', 'APPROVED' => 'Approved', 'REJECTED' => 'Rejected'])])->actions([
            Tables\Actions\ViewAction::make()->tooltip('Lihat pengajuan'),
            ...array_filter(self::reviewActions(), fn ($action) => in_array($action->getName(), ['amendDocument', 'approve', 'reject'], true)),
        ]);
    }

    public static function reviewActions(bool $detail = false): array
    {
        $actionClass = $detail ? \Filament\Actions\Action::class : Tables\Actions\Action::class;

        return array_values(array_filter([
            $actionClass::make('documents')->label('Dokumen')->tooltip('Buka viewer dokumen privat')->url(fn ($record) => route('fp.viewer', ['submission' => $record->uuid]))->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.documents.view')),
            $actionClass::make('amendDocument')->label('Isi / ganti dokumen')->icon('heroicon-o-pencil')->tooltip('Isi KTP/ijazah sebelum approval atau perbarui setelah approval')->form([
                \Filament\Forms\Components\Select::make('type')->label('Jenis dokumen')->options(['KTP' => 'KTP', 'IJAZAH' => 'Ijazah'])->required(),
                \Filament\Forms\Components\FileUpload::make('document')->label('Dokumen pengganti')->required()->acceptedFileTypes(['image/jpeg', 'image/png'])->maxSize(8192)->storeFiles(false)->disk('local')->visibility('private'),
            ])->requiresConfirmation()->modalDescription('Dokumen dapat diisi sebelum pendaftaran disetujui maupun setelahnya. Versi lama tetap tersimpan privat dan perubahan dicatat.')->visible(fn ($record) => \App\Application\FP\OnboardingRevision::enabled() && \App\Application\FP\VictoryAccess::canManageDocuments(auth()->user()) && ($record->status?->value === 'PENDING' || ($record->status?->value === 'APPROVED' && $record->membership !== null)))->action(function ($record, array $data) {
                abort_unless(\App\Application\FP\OnboardingRevision::enabled() && \App\Application\FP\VictoryAccess::canManageDocuments(auth()->user()), 403);
                $type = (string) $data['type'];
                if ($record->status->value === 'PENDING') {
                    app(\App\Application\FP\FillSubmissionDocument::class)->execute(auth()->user(), (int) $record->id, $type, $data['document']);
                } else {
                    $membership = (int) ($record->membership?->id ?? $record->membership()->value('id'));
                    app(\App\Application\FP\AmendFpDocument::class)->execute(auth()->user(), $membership, $type, \App\Application\FP\AmendFpDocument::revision($membership, $type), $data['document']);
                }
                $record->unsetRelation('documents')->refresh();
                \Filament\Notifications\Notification::make()->success()->title('Dokumen diperbarui; histori tetap privat')->send();
            }),
            $actionClass::make('correct')->label('Koreksi biodata')->tooltip('Koreksi biodata pengajuan')->form(FpForms::biodata())->fillForm(function ($record) {
                FeatureAccess::require(auth()->user(), 'f2.pii');
                $data = $record->getAttributes();
                $data['nik'] = $record->identity->nik_encrypted;
                $data['bank_account'] = $record->bank_account_encrypted;

                return $data;
            })->action(fn ($record, array $data) => app(FpEngine::class)->updateSubmissionBiodata(auth()->user(), $record->id, $data))->visible(fn ($record) => $record->status->value === 'PENDING' && FeatureAccess::allowed(auth()->user(), 'f2.biodata') && FeatureAccess::allowed(auth()->user(), 'f2.pii')),
            $actionClass::make('verifyBiodata')->label('Verifikasi biodata')->tooltip('Verifikasi revisi biodata saat ini')->requiresConfirmation()->action(fn ($record) => app(FpEngine::class)->verifySubmissionBiodata(auth()->user(), $record->id))->visible(fn ($record) => $record->status->value === 'PENDING' && FeatureAccess::allowed(auth()->user(), 'f2.biodata') && FeatureAccess::allowed(auth()->user(), 'f2.pii')),
            $actionClass::make('verifyDocuments')->label('Verifikasi dokumen')->tooltip('Verifikasi revisi dokumen saat ini')->requiresConfirmation()->action(fn ($record) => app(FpEngine::class)->verifySubmissionDocuments(auth()->user(), $record->id))->visible(fn ($record) => $record->status->value === 'PENDING' && FeatureAccess::allowed(auth()->user(), 'f2.documents')),
            $actionClass::make('approve')->label('Setujui pendaftaran')->color('success')->tooltip('Terbitkan membership setelah approval berhasil')->requiresConfirmation()->modalDescription(fn () => 'Periksa biodata, pilih Sumber Rekrutmen, lalu isi Nama MM. '.(\App\Application\FP\OnboardingRevision::enabled() ? 'KTP/ijazah opsional dan dapat diisi admin sebelum maupun setelah persetujuan. ' : '').'NIP terbit hanya setelah transaksi berhasil.')->disabled(fn ($record) => ! \App\Application\FP\OnboardingRevision::enabled() && ($record->biodata_verified_revision !== $record->biodata_revision || $record->documents_verified_revision !== $record->document_revision))->form(FpForms::source())->action(fn ($record, array $data, \Filament\Forms\Form $form) => \App\Filament\FP\ApprovalSourceForm::approve(auth()->user(), $record->id, $data, $form))->visible(fn ($record) => $record->status->value === 'PENDING' && FeatureAccess::allowed(auth()->user(), 'f2.approve')),
            $actionClass::make('reject')->label('Tolak pendaftaran')->color('danger')->tooltip('Tolak pendaftaran dengan alasan')->requiresConfirmation()->modalDescription('Alasan penolakan akan dicatat dalam riwayat. Tindakan ini mengakhiri submission.')->form([\Filament\Forms\Components\Textarea::make('reason')->label('Alasan')->required()->maxLength(4000)])->action(fn ($record, array $data) => app(FpEngine::class)->rejectFpSubmission(auth()->user(), $record->id, $data['reason']))->visible(fn ($record) => $record->status->value === 'PENDING' && FeatureAccess::allowed(auth()->user(), 'f2.reject')),
        ], fn ($action) => ! \App\Application\FP\OnboardingRevision::enabled() || ! in_array($action->getName(), ['verifyBiodata', 'verifyDocuments'], true)));
    }

    public static function canManageInlineDocuments(?\App\Models\User $actor, \App\Models\FpSubmission $record): bool
    {
        if (! \App\Application\FP\OnboardingRevision::enabled() || ! \App\Application\FP\VictoryAccess::canManageDocuments($actor)) {
            return false;
        }

        $status = $record->status?->value;

        return $status === 'PENDING' || ($status === 'APPROVED' && $record->membership !== null);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            \Filament\Infolists\Components\Section::make('Status pemeriksaan')->description(fn () => \App\Application\FP\OnboardingRevision::enabled() ? 'Approval tidak memerlukan verifikasi manual. KTP dan ijazah opsional; dokumen yang diunggah tetap harus aman dan siap.' : 'Verifikasi berlaku untuk revisi saat ini. Koreksi biodata atau perubahan dokumen membatalkan review yang terkait.')->schema([
                TextEntry::make('status')->label('Status submission')->badge(),
                TextEntry::make('biodata_review')->visible(fn () => ! \App\Application\FP\OnboardingRevision::enabled())->label('Biodata')->state(fn ($record) => 'Revisi '.$record->biodata_revision.' · '.($record->biodata_verified_revision === $record->biodata_revision ? 'Terverifikasi' : 'Perlu verifikasi')),
                TextEntry::make('document_review')->visible(fn () => ! \App\Application\FP\OnboardingRevision::enabled())->label('Dokumen')->state(fn ($record) => 'Revisi '.$record->document_revision.' · '.($record->documents_verified_revision === $record->document_revision ? 'Terverifikasi' : 'Perlu verifikasi')),
                TextEntry::make('submitted_at')->label('Dikirim')->dateTime('d/m/Y H:i'),
                TextEntry::make('uuid')->label('Identitas pengajuan'),
                TextEntry::make('membership.nip')->label('NIP')->placeholder('—'),
                TextEntry::make('membership.approval_mm_name_snapshot')->label('Nama MM saat approval (snapshot)')->visible(fn () => \App\Application\FP\OnboardingRevision::enabled())->placeholder('Tidak tercatat'),
                TextEntry::make('recruitment_source')->label('Sumber Rekrutmen')->state(fn (FpSubmission $record) => $record->membership ? \App\Application\FP\RecruitmentPresentation::label($record->membership) : (config('fp.recruitment_team_enabled') && $record->canonicalTeam ? $record->canonicalTeam->display_name.' (pilihan pengajuan; belum disetujui)' : null))->placeholder('Belum diterbitkan'),
            ])->columns(2),
            \Filament\Infolists\Components\Section::make('Biodata pendaftar')->schema([
                TextEntry::make('full_name')->label('Nama lengkap'), TextEntry::make('email')->label('Email')->placeholder('—'), TextEntry::make('phone')->label('Telepon'), TextEntry::make(config('fp.recruitment_team_enabled') ? 'canonicalTeam.display_name' : 'team.team')->label('Team'), TextEntry::make('birth_place')->label('Tempat lahir'), TextEntry::make('birth_date')->label('Tanggal lahir')->date('d/m/Y'), TextEntry::make('address_ktp')->label('Alamat KTP'), TextEntry::make('address_current')->label('Alamat tinggal'), TextEntry::make('education_level')->label('Pendidikan terakhir'), TextEntry::make('education_institution')->label('Tempat pendidikan terakhir'), TextEntry::make('graduation_year')->label('Tahun lulus'),
                TextEntry::make('identity.nik_encrypted')->label('NIK')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.pii')),
                TextEntry::make('bank_account_encrypted')->label('Rekening BCA')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.pii')),
            ])->columns(2),
            \Filament\Infolists\Components\Section::make('Riwayat membership')->schema([
                \Filament\Infolists\Components\RepeatableEntry::make('identity.memberships')->label('Riwayat sebelum pendaftaran ini')->schema([TextEntry::make('nip'), TextEntry::make('team.display_name')->label('Team'), TextEntry::make('recruitment_source')->label('Sumber Rekrutmen')->state(fn (\App\Models\FpMembership $record) => \App\Application\FP\RecruitmentPresentation::label($record)), TextEntry::make('reference_date')->label('Tanggal referensi')->date('d/m/Y'), TextEntry::make('exit.exit_date')->label('Tanggal keluar')->date('d/m/Y'), TextEntry::make('exit.duration_days')->label('Durasi (hari)'), TextEntry::make('exit.type')->label('Jenis keluar'), TextEntry::make('exit.reason')->label('Alasan')])->columns(3),
            ])->collapsible(),
            \Filament\Infolists\Components\Section::make('Riwayat tindakan')->description('50 tindakan terbaru; riwayat hanya dapat dibaca.')->schema([
                \Filament\Infolists\Components\RepeatableEntry::make('audit_history')->label('Tindakan')->state(fn ($record) => \App\Models\FpAuditEvent::where('submission_id', $record->id)->orderByDesc('id')->limit(50)->get(['event', 'occurred_at', 'revision'])->map(fn ($event) => ['action' => $event->event->value, 'time' => $event->occurred_at->setTimezone('UTC')->format('d M Y H:i:s').' UTC', 'revision' => $event->revision ?? '—'])->all())->schema([TextEntry::make('action')->label('Tindakan'), TextEntry::make('time')->label('Waktu'), TextEntry::make('revision')->label('Revisi')])->columns(3),
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => FpSubmissionResource\Pages\ListFpSubmissions::route('/'), 'view' => FpSubmissionResource\Pages\ViewFpSubmission::route('/{record}')];
    }
}
