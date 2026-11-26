<?php

declare(strict_types=1);

namespace App\Http\Controllers\FP;

use App\Application\FP\FpReports;
use App\Application\FP\ReportExport;
use App\Application\FP\ReportFilters;
use Illuminate\Http\Request;

final class ReportController
{
    public function download(Request $request): \Illuminate\Http\Response
    {
        abort_unless(FpReports::allowed($request->user(), true), 403);
        $filters = ReportFilters::validate($request->query() + ['status' => 'all', 'date_field' => 'reference_date']);
        $format = $request->query('format');
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
        $columns = \App\Application\FP\ReportColumns::selected($request->query('columns', \App\Application\FP\ReportColumns::DEFAULTS));
        $bytes = \Illuminate\Support\Facades\DB::transaction(function () use ($filters, $format, $columns) {
            \Illuminate\Support\Facades\DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $rows = \App\Application\FP\ReportColumns::prepare(FpReports::query($filters), $columns)->orderBy('financial_planners.id')->limit(ReportExport::MAX_ROWS + 1)->get();
            if ($rows->count() > ReportExport::MAX_ROWS) {
                throw \Illuminate\Validation\ValidationException::withMessages(['export' => 'Maksimal 5.000 membership. Persempit filter laporan.']);
            }

            return ReportExport::render($rows->map(fn ($row) => \App\Application\FP\ReportColumns::row($row, $columns))->all(), $format, \App\Application\FP\ReportColumns::headers($columns));
        });
        // Domain audit has no export event; never fabricate a lifecycle event.
        \Illuminate\Support\Facades\Log::info('fp_report_export', ['actor_id' => $request->user()->id, 'format' => $format, 'bytes' => strlen($bytes), 'columns' => $columns, 'filters' => array_diff_key($filters, ['name' => true, 'nip' => true])]);

        return response($bytes, 200, ['Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="victory-fp-report.'.$format.'"', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
