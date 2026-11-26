<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\FpSubmissionResource;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ReceiptSearchTest extends TestCase
{
    public function test_validation_table_exposes_searchable_receipt_code(): void
    {
        $table = FpSubmissionResource::table(Table::make($this->createMock(HasTable::class)));
        $column = collect($table->getColumns())->first(fn ($column) => $column->getName() === 'uuid');

        self::assertNotNull($column);
        self::assertSame('Kode penerimaan', $column->getLabel());
        self::assertTrue($column->isSearchable());
    }

    public function test_receipt_search_is_not_hidden_by_a_default_status_filter(): void
    {
        $table = FpSubmissionResource::table(Table::make($this->createMock(HasTable::class)));
        $filter = collect($table->getFilters())->first(fn ($filter) => $filter->getName() === 'status');

        self::assertNotNull($filter);
        self::assertNull($filter->getDefaultState());
    }

    public function test_global_search_includes_receipt_code(): void
    {
        self::assertContains('uuid', FpSubmissionResource::getGloballySearchableAttributes());
    }

    public function test_rendered_receipt_has_copy_control_and_validation_hint(): void
    {
        $this->withoutVite();
        Route::post('/synthetic-onboarding', fn () => null)->name('fp.submit');
        Route::getRoutes()->refreshNameLookups();
        $html = view('fp.onboarding', ['teams' => collect(), 'uploadLimits' => ['file_bytes' => 8388608, 'total_bytes' => 16777216, 'file_label' => '8 MB']])->render();

        self::assertStringContainsString('id="receipt-code"', $html);
        self::assertStringContainsString('id="copy-receipt"', $html);
        self::assertStringContainsString('Validasi Pendaftar', $html);
    }

    public function test_onboarding_exposes_local_draft_controls(): void
    {
        $this->withoutVite();
        Route::post('/synthetic-onboarding', fn () => null)->name('fp.submit');
        Route::getRoutes()->refreshNameLookups();
        $html = view('fp.onboarding', ['teams' => collect(), 'uploadLimits' => ['file_bytes' => 8388608, 'total_bytes' => 16777216, 'file_label' => '8 MB']])->render();

        self::assertStringContainsString('id="draft-status"', $html);
        self::assertStringContainsString('id="clear-draft"', $html);
        self::assertStringContainsString('Draf dipulihkan dari browser ini.', $html);
        self::assertStringContainsString('onboarding.css?v=f3-7n-logo-center', $html);
    }
}
