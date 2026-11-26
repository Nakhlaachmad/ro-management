<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpMembershipResource\Pages;

final class ViewFpMembership extends \Filament\Resources\Pages\ViewRecord
{
    use \App\Filament\FP\GuardsVictoryActions;

    protected static string $resource = \App\Filament\Resources\FpMembershipResource::class;

    protected static string $view = 'filament.fp.membership-view';

    public function getTitle(): string
    {
        return 'Detail Financial Planner';
    }

    public function getInlineDocument(string $type): ?\App\Models\FpDocument
    {
        if (! \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.documents.view')) {
            return null;
        }

        return $this->getRecord()->documents->sortByDesc('id')->first(fn ($document) => $document->type->value === $type
            && (int) $document->membership_id === (int) $this->getRecord()->id
            && $document->processing_state->value === 'READY'
            && $document->deletion_state->value === 'NONE');
    }

    /** Shared document card is read-only here; amendment stays in the header action. */
    public function canManageInlineDocuments(): bool
    {
        return false;
    }

    private function documentAmendmentActions(): array
    {
        if (! \App\Application\FP\OnboardingRevision::enabled()) {
            return [];
        }

        return [\Filament\Actions\Action::make('amendDocument')->label('Edit Dokumen')->icon('heroicon-o-pencil')
            ->visible(fn () => \App\Application\FP\VictoryAccess::canManageDocuments(auth()->user()))
            ->form([
                \Filament\Forms\Components\Select::make('type')->label('Jenis dokumen')->options(['KTP' => 'KTP', 'IJAZAH' => 'Ijazah'])->required()->live()->afterStateUpdated(function ($state, \Filament\Forms\Set $set) {
                    $set('expected_revision', in_array($state, ['KTP', 'IJAZAH'], true) ? \App\Application\FP\AmendFpDocument::revision($this->getRecord()->id, $state) : null);
                }),
                \Filament\Forms\Components\Hidden::make('expected_revision')->required(),
                \Filament\Forms\Components\FileUpload::make('document')->label('Dokumen pengganti')->required()->acceptedFileTypes(['image/jpeg', 'image/png'])->maxSize(8192)->storeFiles(false)->disk('local')->visibility('private'),
            ])->requiresConfirmation()->modalDescription('Dokumen lama tetap tersimpan sebagai riwayat privat. Perubahan dicatat dengan actor dan revisi.')
            ->action(function (array $data) {
                abort_unless(\App\Application\FP\VictoryAccess::canManageDocuments(auth()->user()), 403);
                app(\App\Application\FP\AmendFpDocument::class)->execute(auth()->user(), $this->getRecord()->id, $data['type'], (int) $data['expected_revision'], $data['document']);
                $this->getRecord()->unsetRelation('documents')->refresh();
                \Filament\Notifications\Notification::make()->success()->title('Dokumen diperbarui; histori tetap privat')->send();
            })];
    }

    protected function getHeaderActions(): array
    {
        return [...$this->documentAmendmentActions(), \Filament\Actions\Action::make('documents')->label('Dokumen')->url(fn () => route('fp.viewer', ['submission' => $this->getRecord()->submission?->uuid ?? 'missing']))->visible(fn () => \App\Application\FP\FeatureAccess::allowed(auth()->user(), 'f2.documents.view') && $this->getRecord()->submission?->uuid !== null)];
    }
}
