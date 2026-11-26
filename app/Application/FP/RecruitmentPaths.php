<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\FpRecruitmentPath;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class RecruitmentPaths
{
    public function save(User $actor, ?int $id, array $data): FpRecruitmentPath
    {
        FeatureAccess::require($actor, 'f3.jalur.manage');
        abort_if(config('fp.recruitment_team_enabled'), 403);
        $rules = ['name' => 'required|string|max:120', 'description' => 'nullable|string|max:2000', 'is_active' => 'required|boolean'];
        if ($id === null) {
            $data['code'] = $data['code'] ?? self::internalCode();
            $rules['code'] = 'required|string|regex:/^[A-Z][A-Z0-9_]{0,19}$/|unique:fp_recruitment_paths,code';
        }
        $valid = Validator::make($data, $rules)->validate();
        $valid['description'] = $valid['description'] ?? '';

        return DB::transaction(function () use ($id, $valid) {
            if ($id !== null) {
                $path = FpRecruitmentPath::whereKey($id)->lockForUpdate()->firstOrFail();
                $path->fill($valid);
                $path->save();

                return $path;
            }
            $path = new FpRecruitmentPath;
            $path->forceFill($valid);
            $path->save();

            return $path;
        }, 1);
    }

    public static function internalCode(): string
    {
        return 'R'.strtoupper(bin2hex(random_bytes(8)));
    }

    public static function activeForApproval(mixed $id): object
    {
        if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
            throw ValidationException::withMessages(['recruitment_path_id' => 'Pilih Sumber Rekrutmen aktif.']);
        }
        $path = DB::table('fp_recruitment_paths')->where('id', (int) $id)->where('is_active', true)->sharedLock()->first();
        if (! $path) {
            throw ValidationException::withMessages(['recruitment_path_id' => 'Sumber Rekrutmen tidak tersedia atau sudah nonaktif.']);
        }

        return $path;
    }
}
