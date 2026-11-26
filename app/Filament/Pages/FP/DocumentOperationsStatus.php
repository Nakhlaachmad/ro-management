<?php

declare(strict_types=1);

namespace App\Filament\Pages\FP;

use App\Application\FP\DocumentOperationDiagnostics;
use Filament\Pages\Page;
use Livewire\WithPagination;

final class DocumentOperationsStatus extends Page
{
    use WithPagination;

    protected static ?string $slug = 'document-operations';

    protected static bool $isDiscovered = false;

    protected static ?string $title = 'Operasi Dokumen';

    protected static string $view = 'filament.fp.document-operations-status';

    public static function canAccess(): bool
    {
        return DocumentOperationDiagnostics::allowed(auth()->user());
    }

    public function operations(): array
    {
        abort_unless(self::canAccess(), 403);
        $page = filter_var($this->getPage(), FILTER_VALIDATE_INT);
        abort_if($page === false, 422);

        return app(DocumentOperationDiagnostics::class)->snapshot(auth()->user(), $page);
    }
}
