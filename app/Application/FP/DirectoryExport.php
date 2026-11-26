<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

final class DirectoryExport
{
    public function download(User $actor, Builder $filteredQuery, string $format, array $columns): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        FeatureAccess::require($actor, 'f3.reports.export');
        $columns = ReportColumns::selected($columns);
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
        $key = 'fp-directory-export:'.$actor->id;
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429);
        RateLimiter::hit($key, 60);
        $bytes = DB::transaction(function () use ($filteredQuery, $format, $columns) {
            DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            // Use the server-owned filtered/sorted table query, never a client-supplied SQL/query.
            $rows = (clone $filteredQuery)->with(['identity', 'team', 'exit'])->limit(ReportExport::MAX_ROWS + 1)->get();
            if ($rows->count() > ReportExport::MAX_ROWS) {
                throw \Illuminate\Validation\ValidationException::withMessages(['export' => 'Maksimal 5.000 FP. Persempit filter.']);
            }

            return ReportExport::render($rows->map(fn ($row) => ReportColumns::row($row, $columns))->all(), $format, ReportColumns::headers($columns));
        }, 1);
        \Illuminate\Support\Facades\Log::info('fp_directory_export', ['actor_id' => $actor->id, 'format' => $format, 'columns' => $columns, 'bytes' => strlen($bytes)]);

        return response()->streamDownload(static function () use ($bytes) {
            echo $bytes;
        }, 'victory-bank-fp.'.$format, [
            'Content-Type' => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
