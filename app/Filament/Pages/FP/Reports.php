<?php

declare(strict_types=1);

namespace App\Filament\Pages\FP;

use App\Application\FP\FpReports;
use App\Application\FP\ReportFilters;
use Livewire\WithPagination;

final class Reports extends \Filament\Pages\Page
{
    use WithPagination;

    protected static ?string $slug = 'reports';

    protected static bool $isDiscovered = false;

    protected static ?string $title = 'Laporan HRD';

    protected static string $view = 'filament.fp.reports';

    public array $filters = ['status' => 'all', 'date_field' => 'reference_date', 'from' => '', 'until' => '', 'team_id' => '', 'path_id' => '', 'period' => 'custom', 'year' => '', 'month' => ''];

    public array $appliedFilters = ['status' => 'all', 'date_field' => 'reference_date'];

    public array $columns = \App\Application\FP\ReportColumns::DEFAULTS;

    public function selectAllColumns(): void { abort_unless(FpReports::allowed(auth()->user(), true), 403); $this->columns = array_keys(\App\Application\FP\ReportColumns::AVAILABLE); }
    public function resetColumns(): void { abort_unless(FpReports::allowed(auth()->user(), true), 403); $this->columns = \App\Application\FP\ReportColumns::DEFAULTS; }

    public static function canAccess(): bool
    {
        return FpReports::allowed(auth()->user());
    }

    public function form(\Filament\Forms\Form $form): \Filament\Forms\Form
    {
        return $form->schema([
            \Filament\Forms\Components\DatePicker::make('from')->label('Dari Tanggal')->visible(fn () => ($this->filters['period'] ?? 'custom') === 'custom')->native(false)->closeOnDateSelection()->locale('id')->displayFormat('d/m/Y')->format('Y-m-d'),
            \Filament\Forms\Components\DatePicker::make('until')->label('Sampai Tanggal')->visible(fn () => ($this->filters['period'] ?? 'custom') === 'custom')->native(false)->closeOnDateSelection()->locale('id')->displayFormat('d/m/Y')->format('Y-m-d'),
        ])->statePath('filters')->columns(2);
    }

    public function applyFilters(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->filters = array_replace($this->filters, $this->form->getState());
        try {
            $validated = ReportFilters::validate($this->filters);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $messages = [];
            foreach ($e->errors() as $field => $errors) {
                $messages['filters.'.$field] = $errors;
            }
            throw \Illuminate\Validation\ValidationException::withMessages($messages);
        }
        $this->resetErrorBag();
        $this->appliedFilters = $validated;
        $this->resetPage();
    }

    public function report(): array
    {
        abort_unless(self::canAccess(), 403);
        $filters = ReportFilters::validate($this->appliedFilters);

        return \Illuminate\Support\Facades\DB::transaction(function () use ($filters) {
            \Illuminate\Support\Facades\DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            $paths = config('fp.recruitment_team_enabled')
                ? \App\Models\FpTeam::orderBy('display_name')->get(['team_id as id', 'display_name as name', 'is_active'])
                : \App\Models\FpRecruitmentPath::orderBy('code')->get(['id', 'code', 'name', 'is_active']);
            return ['counts' => FpReports::counts($filters), 'rows' => FpReports::query($filters)->orderBy('financial_planners.id')->paginate(25), 'teams' => \App\Models\FpTeam::orderBy('display_name')->pluck('display_name', 'team_id'), 'paths' => $paths, 'export' => FpReports::allowed(auth()->user(), true), 'filters' => $filters];
        });
    }
}
