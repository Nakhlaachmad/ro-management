<?php

declare(strict_types=1);

namespace App\Filament\Resources\FpSubmissionResource\Pages;

use App\Application\FP\AmendFpDocument;
use App\Application\FP\FeatureAccess;
use App\Application\FP\FillSubmissionDocument;
use App\Domain\FP\FpPersistenceException;
use App\Filament\Resources\FpSubmissionResource;
use App\Models\FpDocument;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

final class ViewFpSubmission extends \Filament\Resources\Pages\ViewRecord
{
    use \App\Filament\FP\GuardsVictoryActions;
    use WithFileUploads;

    public array $documentFiles = [];

    protected static string $resource = FpSubmissionResource::class;

    protected static string $view = 'filament.fp.submission-review';

    public function getTitle(): string
    {
        return 'Detail Pengajuan FP';
    }

    public function getSubheading(): ?string
    {
        return match ($this->getRecord()->status->value) {
            'PENDING' => 'Menunggu Validasi',
            'APPROVED' => 'Pendaftaran Disetujui',
            'REJECTED' => 'Pendaftaran Ditolak',
        };
    }

    protected function getHeaderActions(): array
    {
        // Document intake happens inside the supporting-document cards on this page.
        return array_values(array_filter(
            array_map(fn ($action) => $action->record($this->getRecord())->after(fn () => $this->getRecord()->refresh()), FpSubmissionResource::reviewActions(true)),
            fn ($action) => $action->getName() !== 'amendDocument',
        ));
    }

    public function getCachedHeaderActions(): array
    {
        return array_values(array_filter(parent::getCachedHeaderActions(), fn ($action) => ! in_array($action->getName(), ['documents', 'approve', 'reject'], true)));
    }

    public function getApprovalBarActions(): array
    {
        // Render the same registered actions; mount/call guards and callbacks are unchanged.
        return array_values(array_map(function ($action) {
            $action->color($action->getName() === 'reject' ? 'danger' : 'primary');

            return $action;
        }, array_filter(parent::getCachedHeaderActions(), fn ($action) => in_array($action->getName(), ['approve', 'reject'], true) && $action->isVisible())));
    }

    public function getInlineDocument(string $type): ?FpDocument
    {
        if (! FeatureAccess::allowed(auth()->user(), 'f2.documents.view')) {
            return null;
        }

        return $this->getRecord()->documents->sortByDesc('id')->first(fn ($document) => $document->type->value === $type
            && $document->processing_state->value === 'READY'
            && $document->deletion_state->value === 'NONE'
            && $document->revision === $this->getRecord()->document_revision);
    }

    public function canManageInlineDocuments(): bool
    {
        return FpSubmissionResource::canManageInlineDocuments(auth()->user(), $this->getRecord());
    }

    public function uploadInlineDocument(string $type): void
    {
        $record = $this->getRecord();
        abort_unless(FpSubmissionResource::canManageInlineDocuments(auth()->user(), $record), 403);
        abort_unless(in_array($type, ['KTP', 'IJAZAH'], true), 404);

        $field = 'documentFiles.'.$type;
        $this->validate([$field => ['required', 'file', 'mimes:jpeg,jpg,png', 'mimetypes:image/jpeg,image/png', 'max:2048']], [
            $field.'.required' => 'Pilih berkas '.($type === 'KTP' ? 'KTP' : 'ijazah').' terlebih dahulu.',
        ]);

        try {
            if ($record->status->value === 'PENDING') {
                app(FillSubmissionDocument::class)->execute(auth()->user(), (int) $record->id, $type, $this->documentFiles[$type]);
            } else {
                $membership = (int) ($record->membership?->id ?? $record->membership()->value('id'));
                app(AmendFpDocument::class)->execute(auth()->user(), $membership, $type, AmendFpDocument::revision($membership, $type), $this->documentFiles[$type]);
            }
        } catch (ValidationException) {
            $this->addError($field, 'Berkas ditolak. Gunakan JPEG/PNG yang aman dan belum terlalu besar.');

            return;
        } catch (FpPersistenceException) {
            $this->addError($field, 'Status penyimpanan belum dapat dipastikan. Muat ulang halaman sebelum mengulang.');

            return;
        }

        $record->unsetRelation('documents')->refresh();
        unset($this->documentFiles[$type]);
        Notification::make()->success()->title('Dokumen disimpan; histori tetap privat')->send();
    }
}
