<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Organization
{
    public function command(User $actor, string $action, array $input): int
    {
        FeatureAccess::require($actor, $action === 'AMEND' ? 'f2.biodata' : 'f2.teams');
        abort_unless(config('fp.organization_enabled'), 403);
        if (! in_array($action, ['AMEND', 'CREATE_TEAM', 'LEADER', 'DISSOLVE'], true)) {
            throw ValidationException::withMessages(['organization' => 'Operasi tidak tersedia.']);
        }
        try {
            return DB::transaction(fn () => (int) DB::selectOne('SELECT public.fp_organization_command(?,?,?::jsonb) AS id', [$actor->id, $action, json_encode($input, JSON_THROW_ON_ERROR)])->id, 1);
        } catch (\Illuminate\Database\QueryException $exception) {
            if (in_array($exception->errorInfo[0] ?? '', ['23514', '23505', '40001', '42501'], true)) {
                throw ValidationException::withMessages(['organization' => 'Perubahan ditolak: periksa status FP/Team, jabatan yang sudah terisi, kelengkapan tujuan, atau muat ulang data terbaru.']);
            }
            // Never expose SQL bindings containing biodata or encrypted evidence.
            throw new \App\Domain\FP\FpPersistenceException('Status operasi organisasi belum terkonfirmasi; periksa sebelum mengulangi.');
        }
    }
}
