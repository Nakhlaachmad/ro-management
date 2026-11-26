<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Infrastructure\FP\Uploads;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

final class RegisterFpFromPanel
{
    public function execute(User $actor, array $input, array $files, string $key): array
    {
        FeatureAccess::require($actor, 'f2.biodata');
        FeatureAccess::require($actor, 'f2.documents');
        abort_unless(config('fp.recruitment_team_enabled'), 403);
        $input['email'] = isset($input['email']) ? mb_strtolower(trim($input['email'])) : null;
        if (isset($input['phone']) && is_string($input['phone'])) {
            $input['phone'] = '+62'.preg_replace('/\A(?:\+62|0062)/', '', $input['phone'], 1);
        }
        if (! empty($input['address_same_as_ktp'])) {
            $input['address_current'] = $input['address_ktp'] ?? null;
        }
        $rules = (new \App\Http\Requests\FpApplicationRequest)->rules();
        unset($rules['profile_photo'], $rules['ktp'], $rules['ijazah']);
        $input['idempotency_key'] = $key;
        $data = Validator::make($input, $rules)->validate();
        $uploads = app(Uploads::class);
        $documents = $uploads->stage($files);
        try {
            $result = \Illuminate\Support\Facades\DB::transaction(function () use ($actor, $data, $documents, $key) {
                $receipt = app(FpEngine::class)->submitFpApplication($data, $documents, $key);
                $id = \Illuminate\Support\Facades\DB::table('fp_submissions')->where('uuid', $receipt['receipt'])->value('id');
                \Illuminate\Support\Facades\DB::selectOne('SELECT public.fp_record_panel_intake(?,?)', [$actor->id, $id]);

                return $receipt;
            }, 1);
        } catch (\Illuminate\Validation\ValidationException $error) {
            $uploads->discard($documents);
            throw $error;
        }
        if ($result['replayed'] ?? false) {
            $uploads->discard($documents);
        }

        return $result;
    }
}
