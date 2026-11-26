<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Application\FP\FeatureAccess;
use App\Application\FP\FpEngine;
use App\Models\FpMembership;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

final class FpMembershipResource extends Resource
{
    protected static ?string $model = FpMembership::class;

    protected static ?string $navigationLabel = 'Bank FP';

    protected static ?string $navigationGroup = 'Financial Planner';

    protected static ?string $slug = 'fp-memberships';

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
        return config('fp.recruitment_team_enabled') && FeatureAccess::allowed(auth()->user(), 'f2.biodata') && FeatureAccess::allowed(auth()->user(), 'f2.documents');
    }

    public static function canEdit($record): bool
    {
        return config('fp.organization_enabled') && ($record->lifecycle_state?->value === 'ACTIVE' || (\App\Application\FP\OnboardingRevision::enabled() && \App\Application\FP\VictoryAccess::role(auth()->user()) === 'admin')) && FeatureAccess::allowed(auth()->user(), 'f2.biodata');
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['team', 'exit', 'documents', 'submission', 'identity.memberships']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('created_at')->label('Dibuat')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('updated_at')->label('Diperbarui')->dateTime('d/m/Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            Tables\Columns\TextColumn::make('nip')->label('NIP')->sortable()->searchable(query: fn ($query, string $search) => \App\Filament\FP\NipSearch::apply($query, $search))->placeholder('—')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.view')),
            Tables\Columns\TextColumn::make('full_name')->label('Nama FP')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('team.display_name')->label('Team')->sortable(),
            Tables\Columns\TextColumn::make('recruitment_source')->label('Sumber Rekrutmen')->state(fn (FpMembership $record) => \App\Application\FP\RecruitmentPresentation::label($record))->sortable(query: fn ($query, string $direction) => \App\Filament\FP\MembershipSorting::recruitment($query, $direction)),
            Tables\Columns\TextColumn::make('lifecycle_state')->label('Status')->sortable()->formatStateUsing(fn ($state) => $state?->value ?? 'Belum terklasifikasi')->badge()->color(fn ($state) => match ($state?->value) {
                'ACTIVE' => 'success', 'EXITED' => 'gray', default => 'warning'
            })->placeholder('Belum terklasifikasi'),
            Tables\Columns\TextColumn::make('submission.submitted_at')->label('Tanggal pendaftaran')->sortable()->dateTime('d/m/Y H:i')->placeholder('Tidak tersedia'),
            Tables\Columns\TextColumn::make('exit.exit_date')->sortable()->visible(fn ($livewire) => ! ($livewire instanceof \App\Filament\Resources\FpMembershipResource\Pages\ListActiveFp))->label('Tanggal keluar')->date('d/m/Y'),
            Tables\Columns\TextColumn::make('exit.duration_days')->visible(fn ($livewire) => ! ($livewire instanceof \App\Filament\Resources\FpMembershipResource\Pages\ListActiveFp))->label('Durasi (hari)'),
            Tables\Columns\TextColumn::make('exit.type')->visible(fn ($livewire) => ! ($livewire instanceof \App\Filament\Resources\FpMembershipResource\Pages\ListActiveFp))->label('Jenis keluar')->badge()->color(fn ($state) => ($state instanceof \BackedEnum ? $state->value : $state) === 'ABNORMAL' ? 'danger' : 'warning'),
            Tables\Columns\TextColumn::make('exit.reason')->visible(fn ($livewire) => ! ($livewire instanceof \App\Filament\Resources\FpMembershipResource\Pages\ListActiveFp))->label('Alasan keluar')->wrap()->limit(60)->toggleable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('lifecycle_state')->label('Status')->options(['ACTIVE' => 'Aktif', 'EXITED' => 'Keluar']),
            Tables\Filters\SelectFilter::make('team_id')->label('Team')->relationship('team', 'display_name')->searchable(),
            Tables\Filters\Filter::make('period')->label('Periode pendaftaran')->form([\Filament\Forms\Components\DatePicker::make('from')->native(false)->locale('id')->displayFormat('d/m/Y')->format('Y-m-d')->label('Dari'), \Filament\Forms\Components\DatePicker::make('until')->native(false)->locale('id')->displayFormat('d/m/Y')->format('Y-m-d')->label('Sampai')])->query(fn ($query, $data) => $query->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('reference_date', '>=', $date))->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('reference_date', '<=', $date))),
        ])->actions([
            Tables\Actions\Action::make('editFp')->label('Edit Biodata')->icon('heroicon-o-pencil')->url(fn ($record) => self::getUrl('edit', ['record' => $record]))->visible(fn ($record) => self::canEdit($record)),
            Tables\Actions\ViewAction::make()->tooltip('Lihat detail FP'), Tables\Actions\Action::make('documents')->label('Dokumen')->tooltip('Buka viewer dokumen privat')->url(fn ($record) => route('fp.viewer', ['submission' => $record->submission?->uuid ?? 'missing']))->visible(fn ($record, $livewire) => ! ($livewire instanceof \App\Filament\Resources\FpMembershipResource\Pages\ListActiveFp) && $record->submission_id !== null && FeatureAccess::allowed(auth()->user(), 'f2.documents.view')),
            Tables\Actions\Action::make('exit')->label('Proses keluar')->color('danger')->tooltip('Mengakhiri membership FP')->form([\Filament\Forms\Components\DatePicker::make('exit_date')->native(false)->locale('id')->displayFormat('d/m/Y')->format('Y-m-d')->label('Tanggal Keluar')->required(), \Filament\Forms\Components\Textarea::make('reason')->label('Alasan')->required()->maxLength(4000)])->action(fn ($record, array $data) => app(FpEngine::class)->exitFp(auth()->user(), $record->id, $data['exit_date'], $data['reason']))->visible(fn ($record) => $record->lifecycle_state?->value === 'ACTIVE' && FeatureAccess::allowed(auth()->user(), 'f2.exit')),
        ]);
    }

    public static function infolist(Infolist $list): Infolist
    {
        return $list->schema([
            \Filament\Infolists\Components\Section::make('Informasi Financial Planner')->schema([
                TextEntry::make('full_name')->label('Nama lengkap'), TextEntry::make('nip')->label('NIP')->placeholder('—'), TextEntry::make('email')->label('Email')->placeholder('—'), TextEntry::make('phone')->label('Nomor telepon'),
                TextEntry::make('lifecycle_state')->label('Status keanggotaan')->badge()->placeholder('Belum terklasifikasi'),
                TextEntry::make('birth_place')->label('Tempat lahir'), TextEntry::make('birth_date')->label('Tanggal lahir')->date('d/m/Y'), TextEntry::make('address_ktp')->label('Alamat KTP'), TextEntry::make('address_current')->label('Alamat domisili'),
                TextEntry::make('education_level')->label('Pendidikan terakhir'), TextEntry::make('education_institution')->label('Institusi pendidikan'), TextEntry::make('graduation_year')->label('Tahun lulus'),
                TextEntry::make('identity.nik_encrypted')->label('NIK')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.pii')), TextEntry::make('bank_account_encrypted')->label('Rekening BCA')->visible(fn () => FeatureAccess::allowed(auth()->user(), 'f2.pii')),
            ])->columns(2),
            \Filament\Infolists\Components\Section::make('Keanggotaan dan perekrutan')->schema([
                TextEntry::make('team.display_name')->label('Team'), TextEntry::make('recruitment_source')->label('Sumber Rekrutmen')->state(fn (\App\Models\FpMembership $record) => \App\Application\FP\RecruitmentPresentation::label($record)),
                TextEntry::make('reference_date')->label('Tanggal referensi')->date('d/m/Y'), TextEntry::make('approval_mm_name_snapshot')->label('Nama MM saat approval (snapshot)')->visible(fn () => \App\Application\FP\OnboardingRevision::enabled())->placeholder('Tidak tercatat'), TextEntry::make('approved_at')->label('Tanggal persetujuan')->dateTime('d/m/Y H:i'),
                TextEntry::make('exit.exit_date')->label('Tanggal keluar')->date('d/m/Y'), TextEntry::make('exit.duration_days')->label('Durasi (hari)'), TextEntry::make('exit.type')->label('Jenis keluar'), TextEntry::make('exit.reason')->label('Alasan keluar'),
            ])->columns(2),
            \Filament\Infolists\Components\Section::make('Riwayat identitas')->schema([
                \Filament\Infolists\Components\RepeatableEntry::make('identity.memberships')->label('Riwayat membership')->schema([
                    TextEntry::make('nip')->label('NIP'), TextEntry::make('reference_date')->label('Tanggal referensi')->date('d/m/Y'), TextEntry::make('team.display_name')->label('Team'), TextEntry::make('recruitment_source')->label('Sumber Rekrutmen')->state(fn (\App\Models\FpMembership $record) => \App\Application\FP\RecruitmentPresentation::label($record)), TextEntry::make('exit.exit_date')->label('Tanggal keluar')->date('d/m/Y'), TextEntry::make('exit.reason')->label('Alasan keluar'),
                ])->columns(2),
            ])->collapsible(),
            ...(config('fp.organization_enabled') ? [\Filament\Infolists\Components\Section::make('Riwayat organisasi dan perubahan biodata')->schema([
                TextEntry::make('organization_history')->label('Penempatan Team')->state(fn (FpMembership $record) => \Illuminate\Support\Facades\DB::table('fp_team_assignments as a')->join('team_profiles as t', 't.team_id', '=', 'a.team_id')->where('a.membership_id', $record->id)->orderBy('a.id')->limit(100)->get(['t.display_name', 'a.starts_at', 'a.ends_at', 'a.evidence_kind'])->map(fn ($row) => $row->display_name.' · '.($row->starts_at ?? 'Awal historis tidak diketahui').' — '.($row->ends_at ?? 'Saat ini'))->all())->listWithLineBreaks(),
                TextEntry::make('amendment_history')->label('Audit biodata')->state(fn (FpMembership $record) => \Illuminate\Support\Facades\DB::table('fp_membership_amendments')->where('membership_id', $record->id)->orderByDesc('revision')->limit(100)->get(['revision', 'actor_id', 'occurred_at'])->map(fn ($row) => 'Revisi '.$row->revision.' · Actor #'.$row->actor_id.' · '.$row->occurred_at)->all())->listWithLineBreaks(),
            ])->collapsible()] : []),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => FpMembershipResource\Pages\ListFpMemberships::route('/'), 'create' => FpMembershipResource\Pages\CreateFpMembership::route('/create'), 'active' => FpMembershipResource\Pages\ListActiveFp::route('/active'), 'exited' => FpMembershipResource\Pages\ListExitedFp::route('/exited'), 'normal' => FpMembershipResource\Pages\ListNormalExitedFp::route('/exited-normal'), 'abnormal' => FpMembershipResource\Pages\ListAbnormalExitedFp::route('/exited-abnormal'), 'edit' => FpMembershipResource\Pages\EditFpBiodata::route('/{record}/edit'), 'view' => FpMembershipResource\Pages\ViewFpMembership::route('/{record}')];
    }
}
