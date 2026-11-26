<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Domain\FP\Biodata;
use App\Domain\FP\ExitRule;
use App\Domain\FP\NikNormalizer;
use App\Domain\FP\PublicReceipt;
use App\Jobs\FP\DeleteRejectedDocuments;
use App\Jobs\FP\PublishApprovedFpDocuments;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class FpEngine
{
    private function database(): void
    {
        FeatureAccess::requireEnabled();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('FP requires PostgreSQL');
        }
    }

    private function time(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.uP');
    }

    private function requirePending(object $sub): void
    {
        if ($sub->status !== 'PENDING') {
            throw ValidationException::withMessages(['submission' => 'Submission is no longer editable']);
        }
    }

    private function secret(): string
    {
        $key = (string) config('fp.nik_lookup_key');
        if (strlen($key) < 32 || ! (string) config('fp.nik_lookup_key_id')) {
            throw new RuntimeException('FP identity lookup configuration incomplete');
        }

        return $key;
    }

    private function transaction(callable $work): mixed
    {
        $this->database();
        try {
            return DB::transaction($work, 1);
        } catch (\Illuminate\Database\QueryException) {
            throw new \App\Domain\FP\FpPersistenceException('FP persistence outcome not confirmed; refresh before another action');
        }
    }

    private function validate(array $data): array
    {
        if (isset($data['email']) && is_string($data['email'])) {
            $data['email'] = Str::lower($data['email']);
        }
        if (! config('fp.annual_nip_email_enabled')) {
            unset($data['email']);
        }

        if (OnboardingRevision::enabled()) {
            $data['bank_account'] = OnboardingRevision::bank($data['bank_account'] ?? null);
        }

        return Validator::make($data, Biodata::rules())->validate();
    }

    private function snapshot(array $data): array
    {
        $snapshot = array_intersect_key($data, array_flip(array_diff(Biodata::FIELDS, ['nik', 'bank_account'])));
        $snapshot['phone_normalized'] = preg_replace('/[^+0-9]/', '', $data['phone']);
        $snapshot['bank_account_encrypted'] = ($data['bank_account'] ?? null) === null ? null : Crypt::encryptString($data['bank_account']);

        return $snapshot;
    }

    private function identityInside(string $nik): int
    {
        $hash = NikNormalizer::lookup($nik, $this->secret());
        $time = $this->time();
        DB::table('person_identities')->insertOrIgnore(['uuid' => (string) Str::uuid(), 'nik_encrypted' => Crypt::encryptString(NikNormalizer::normalize($nik)), 'nik_lookup_hash' => $hash, 'lookup_key_id' => config('fp.nik_lookup_key_id'), 'reconciliation_state' => 'RESOLVED', 'created_at' => $time, 'updated_at' => $time]);

        return (int) DB::table('person_identities')->where('nik_lookup_hash', $hash)->value('id');
    }

    private function identities(array $ids): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $row = DB::table('person_identities')->where('id', $id)->lockForUpdate()->first();
            if (! $row || $row->reconciliation_state !== 'RESOLVED') {
                throw ValidationException::withMessages(['nik' => 'Identity requires HRD reconciliation']);
            }
        }
    }

    private function profiles(array $ids, bool $active = true): void
    {
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $row = DB::table('team_profiles')->where('team_id', $id)->lockForUpdate()->first();
            if (! $row || ($active && ! $row->is_active)) {
                throw ValidationException::withMessages(['team_id' => 'Team unavailable']);
            }
        }
    }

    private function submission(int $id, array $extraIdentities = [], array $extraTeams = []): object
    {
        $peek = DB::table('fp_submissions')->where('id', $id)->first();
        if (! $peek) {
            throw ValidationException::withMessages(['submission' => 'Submission unavailable']);
        }
        $this->identities([$peek->identity_id, ...$extraIdentities]);
        $this->profiles([$peek->team_id, ...$extraTeams], false);
        $sub = DB::table('fp_submissions')->where('id', $id)->lockForUpdate()->first();
        if ($sub->identity_id !== $peek->identity_id || $sub->team_id !== $peek->team_id) {
            throw ValidationException::withMessages(['submission' => 'Submission changed; refresh review']);
        }

        return $sub;
    }

    private function audit(string $event, ?int $actor, string $time, ?int $sid = null, ?int $mid = null, ?int $revision = null, ?int $team = null, array $context = []): void
    {
        DB::table('fp_audit_events')->insert(['uuid' => (string) Str::uuid(), 'event' => $event, 'actor_id' => $actor, 'occurred_at' => $time, 'submission_id' => $sid, 'membership_id' => $mid, 'revision' => $revision, 'team_id' => $team, 'context' => json_encode((object) $context, JSON_THROW_ON_ERROR)]);
    }

    private function scheduleNextcloudPublication(int $submission, int $membership): void
    {
        DB::afterCommit(static function () use ($submission, $membership): void {
            try {
                PublishApprovedFpDocuments::dispatch($submission, $membership);
            } catch (\Throwable $error) {
                Log::warning('FP Nextcloud publication dispatch failed', [
                    'submission_id' => $submission,
                    'membership_id' => $membership,
                    'error' => get_class($error),
                ]);
                report(new RuntimeException('FP Nextcloud publication pending dispatch'));
            }
        });
    }

    public function documentAudit(int $document, int $submission, int $revision, int $actor, string $time): void
    {
        $this->database();
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Document audit requires its mutation transaction');
        }
        DB::table('fp_audit_events')->insert(['event' => 'DOCUMENT_CHANGED', 'submission_id' => $submission, 'document_id' => $document, 'revision' => $revision, 'actor_id' => $actor, 'occurred_at' => $time, 'context' => '{}']);
    }

    public function resolvePersonIdentity(string $nik): int
    {
        return $this->transaction(function () use ($nik) {
            $id = $this->identityInside($nik);
            $this->identities([$id]);

            return $id;
        });
    }

    public function submitFpApplication(array $input, array $documents, string $idempotency): array
    {
        $data = $this->validate($input);
        if (! Str::isUuid($idempotency)) {
            throw ValidationException::withMessages(['idempotency_key' => 'Invalid receipt key']);
        }
        if (OnboardingRevision::enabled() ? (count($documents) > 2 || count(array_unique(array_column($documents, 'type'))) !== count($documents) || array_diff(array_column($documents, 'type'), ['KTP', 'IJAZAH'])) : (count($documents) !== 3 || array_column($documents, 'type') !== ['PROFILE_PHOTO', 'KTP', 'IJAZAH'])) {
            throw ValidationException::withMessages(['documents' => 'Three validated documents required']);
        }
        $canonical = $data;
        ksort($canonical);
        $hash = hash_hmac('sha256', json_encode([$canonical, array_column($documents, 'sha256')], JSON_THROW_ON_ERROR), $this->secret());

        return $this->transaction(function () use ($data, $documents, $idempotency, $hash) {
            // Identity is locked before idempotency/pending lookup, so same-person duplicates serialize.
            $identity = $this->identityInside($data['nik']);
            $this->identities([$identity]);
            $this->profiles([(int) $data['team_id']]);
            $old = DB::table('fp_submissions')->where('idempotency_key', $idempotency)->first();
            if ($old) {
                if (! hash_equals($old->request_payload_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Receipt key already used']);
                }

                return [...PublicReceipt::make($old->uuid), 'replayed' => true];
            }
            if (DB::table('fp_identity_claims')->where('identity_id', $identity)->exists()) {
                throw ValidationException::withMessages(['submission' => 'Registration cannot be accepted; contact HRD']);
            }
            $tz = (string) config('fp.business_timezone');
            if ($tz === '' || ! in_array($tz, timezone_identifiers_list(), true)) {
                throw new RuntimeException('Business timezone required');
            }
            $time = $this->time();
            $uuid = (string) Str::uuid();
            $sid = DB::table('fp_submissions')->insertGetId([...$this->snapshot($data), 'uuid' => $uuid, 'identity_id' => $identity, 'idempotency_key' => $idempotency, 'request_payload_hash' => $hash, ...(OnboardingRevision::enabled() ? ['onboarding_contract_version' => 2] : []), 'status' => 'PENDING', 'submitted_at' => $time, 'business_timezone' => $tz, 'reference_date' => CarbonImmutable::parse($time)->setTimezone($tz)->format('Y-m-d'), 'created_at' => $time, 'updated_at' => $time]);
            foreach ($documents as $document) {
                DB::table('fp_documents')->insert([...$document, 'uuid' => (string) Str::uuid(), 'submission_id' => $sid, 'revision' => 1, 'processing_state' => 'READY', 'created_at' => $time, 'updated_at' => $time]);
            }
            $this->audit('SUBMITTED', null, $time, $sid);

            return PublicReceipt::make($uuid);
        });
    }

    public function updateSubmissionBiodata(User $actor, int $id, array $input): void
    {
        FeatureAccess::require($actor, 'f2.biodata');
        FeatureAccess::require($actor, 'f2.pii');
        $data = $this->validate($input);
        $this->transaction(function () use ($actor, $id, $data) {
            $newIdentity = $this->identityInside($data['nik']);
            $sub = $this->submission($id, [$newIdentity], [(int) $data['team_id']]);
            $this->requirePending($sub);
            $this->profiles([(int) $data['team_id']]);
            $time = $this->time();
            $snapshot = $this->snapshot($data);
            // Preserve raw ciphertext if the bank account did not change.
            if (($sub->bank_account_encrypted === null ? null : Crypt::decryptString($sub->bank_account_encrypted)) === ($data['bank_account'] ?? null)) {
                $snapshot['bank_account_encrypted'] = $sub->bank_account_encrypted;
            }
            $changed = false;
            foreach ([...$snapshot, 'identity_id' => $newIdentity] as $key => $value) {
                if ((string) $sub->$key !== (string) $value) {
                    $changed = true;
                }
            }
            if (! $changed) {
                return;
            }
            DB::table('fp_submissions')->where('id', $id)->update([...$snapshot, 'identity_id' => $newIdentity, 'biodata_changed_at' => $time, 'biodata_changed_actor_id' => $actor->id, 'updated_at' => $time]);
            $new = DB::table('fp_submissions')->where('id', $id)->first();
            if ($new->document_revision !== $sub->document_revision) {
                foreach (DB::table('fp_documents')->where('submission_id', $id)->orderBy('id')->lockForUpdate()->get() as $doc) {
                    DB::table('fp_documents')->where('id', $doc->id)->update(['revision' => $new->document_revision, 'updated_at' => $time]);
                    $this->documentAudit($doc->id, $id, $new->document_revision, $actor->id, $time);
                }
            }
            $this->audit('BIODATA_CHANGED', $actor->id, $time, $id, null, $new->biodata_revision);
        });
    }

    public function verifySubmissionBiodata(User $actor, int $id): void
    {
        abort_if(OnboardingRevision::enabled(), 403);
        FeatureAccess::require($actor, 'f2.biodata');
        FeatureAccess::require($actor, 'f2.pii');
        $this->transaction(function () use ($actor, $id) {
            $sub = $this->submission($id);
            $this->requirePending($sub);
            $time = $this->time();
            DB::table('fp_submissions')->where('id', $id)->update(['biodata_verified_revision' => $sub->biodata_revision, 'biodata_verified_by' => $actor->id, 'biodata_verified_actor_id' => $actor->id, 'biodata_verified_at' => $time, 'updated_at' => $time]);
            $this->audit('BIODATA_VERIFIED', $actor->id, $time, $id, null, $sub->biodata_revision);
        });
    }

    private function ready(object $sub): void
    {
        $documents = DB::table('fp_documents')->where('submission_id', $sub->id)->orderBy('id')->lockForUpdate()->get();
        if (OnboardingRevision::enabled()) {
            $documents = $documents->whereIn('type', ['KTP', 'IJAZAH']);
        }
        if (! OnboardingRevision::enabled() && ($documents->count() !== 3 || $documents->pluck('type')->sort()->values()->all() !== ['IJAZAH', 'KTP', 'PROFILE_PHOTO'])) {
            throw ValidationException::withMessages(['documents' => 'Three documents required']);
        }
        foreach ($documents as $doc) {
            if ($doc->revision !== $sub->document_revision || $doc->processing_state !== 'READY' || $doc->deletion_state !== 'NONE') {
                throw ValidationException::withMessages(['documents' => 'Documents not ready at current revision']);
            }
        }
    }

    public function verifySubmissionDocuments(User $actor, int $id): void
    {
        abort_if(OnboardingRevision::enabled(), 403);
        FeatureAccess::require($actor, 'f2.documents');
        $this->transaction(function () use ($actor, $id) {
            $sub = $this->submission($id);
            $this->requirePending($sub);
            if (OnboardingRevision::enabled()) {
                $profile = (array) $sub;
                $profile['nik'] = Crypt::decryptString(DB::table('person_identities')->where('id', $sub->identity_id)->value('nik_encrypted'));
                $profile['bank_account'] = $sub->bank_account_encrypted === null ? null : Crypt::decryptString($sub->bank_account_encrypted);
                Validator::make($profile, [...Biodata::rules(), 'email' => 'required|string|email:rfc|max:254'])->validate();
            }
            $this->ready($sub);
            $time = $this->time();
            DB::table('fp_submissions')->where('id', $id)->update(['documents_verified_revision' => $sub->document_revision, 'documents_verified_by' => $actor->id, 'documents_verified_actor_id' => $actor->id, 'documents_verified_at' => $time, 'updated_at' => $time]);
            $this->audit('DOCUMENTS_VERIFIED', $actor->id, $time, $id, null, $sub->document_revision);
        });
    }

    public function allocateNip(?string $approvalTime = null): int
    {
        $this->database();
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('NIP allocation requires approval transaction');
        }
        if (! config('fp.annual_nip_email_enabled')) {
            $counter = DB::table('nip_sequences')->where('id', 1)->lockForUpdate()->first();
            if (! $counter || $counter->next_value < 1001 || $counter->next_value >= PHP_INT_MAX) {
                throw new RuntimeException('NIP counter unavailable');
            }

            return (int) $counter->next_value;
        }
        $year = \App\Domain\FP\AnnualNip::year(CarbonImmutable::parse($approvalTime ?? $this->time()));
        DB::table('fp_nip_year_counters')->insertOrIgnore(['year' => $year, 'next_serial' => 1001]);
        $counter = DB::table('fp_nip_year_counters')->where('year', $year)->lockForUpdate()->first();
        if (! $counter) {
            throw new RuntimeException('Annual NIP counter unavailable');
        }
        try {
            $nip = \App\Domain\FP\AnnualNip::number($year, (int) $counter->next_serial);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['nip' => $e->getMessage()]);
        }
        if (DB::table('financial_planners')->where('nip', $nip)->exists()) {
            throw ValidationException::withMessages(['nip' => 'NIP tahunan berbenturan dengan NIP historis. Hubungi administrator; tidak dilakukan penomoran ulang.']);
        }

        return $nip;
    }

    public function approveFpSubmission(User $actor, int $id, array $source): int
    {
        FeatureAccess::require($actor, 'f2.approve');

        return $this->transaction(function () use ($actor, $id, $source) {
            $already = DB::table('fp_submissions')->where('id', $id)->first();
            if ($already?->status === 'APPROVED') {
                $mid = (int) DB::table('financial_planners')->where('submission_id', $id)->value('id');
                $this->scheduleNextcloudPublication($id, $mid);

                return $mid;
            }
            $manualMm = OnboardingRevision::enabled() ? OnboardingRevision::mmName($source) : null;
            if (config('fp.recruitment_team_enabled')) {
                $source = RecruitmentTeamSource::normalize($source);
            }
            $type = $source['source_type'] ?? '';
            if (! in_array($type, ['HRD', 'TEAM', 'FP'], true)) {
                throw ValidationException::withMessages(['source_type' => 'Typed source required']);
            }
            $sourceFp = $type === 'FP' ? DB::table('financial_planners')->where('id', (int) ($source['source_fp_id'] ?? 0))->first() : null;
            $sourceTeam = $type === 'TEAM' ? (int) ($source['source_team_id'] ?? 0) : null;
            if ($type === 'FP' && ! $sourceFp) {
                throw ValidationException::withMessages(['source_fp_id' => 'Recruiter unavailable']);
            }
            $peek = DB::table('fp_submissions')->where('id', $id)->first();
            if (! $peek) {
                throw ValidationException::withMessages(['submission' => 'Submission unavailable']);
            }
            $this->identities([$peek->identity_id, $sourceFp?->identity_id]);
            $this->profiles([$peek->team_id, $sourceTeam, $source['recruitment_team_id'] ?? null]);
            if ($sourceFp) {
                $sourceFp = DB::table('financial_planners')->where('id', $sourceFp->id)->lockForUpdate()->first();
                if ($sourceFp->lifecycle_state !== 'ACTIVE') {
                    throw ValidationException::withMessages(['source_fp_id' => 'Recruiter must be ACTIVE']);
                }
            }
            $sub = DB::table('fp_submissions')->where('id', $id)->lockForUpdate()->first();
            if ($sub->identity_id !== $peek->identity_id || $sub->team_id !== $peek->team_id) {
                throw ValidationException::withMessages(['submission' => 'Refresh changed submission']);
            }
            if ($sub->status === 'APPROVED') {
                $mid = (int) DB::table('financial_planners')->where('submission_id', $id)->value('id');
                $this->scheduleNextcloudPublication($id, $mid);

                return $mid;
            }
            $this->requirePending($sub);
            if (OnboardingRevision::enabled()) {
                $profile = (array) $sub;
                $profile['nik'] = Crypt::decryptString(DB::table('person_identities')->where('id', $sub->identity_id)->value('nik_encrypted'));
                $profile['bank_account'] = $sub->bank_account_encrypted === null ? null : Crypt::decryptString($sub->bank_account_encrypted);
                Validator::make($profile, [...Biodata::rules(), 'email' => 'required|string|email:rfc|max:254'])->validate();
            }
            $this->ready($sub);
            if (! OnboardingRevision::enabled() && ($sub->biodata_verified_revision !== $sub->biodata_revision || $sub->documents_verified_revision !== $sub->document_revision)) {
                throw ValidationException::withMessages(['submission' => 'Current reviews required']);
            }
            $name = match ($type) {
                'HRD' => 'HRD','TEAM' => (string) DB::table('team_profiles')->where('team_id', $sourceTeam)->value('display_name'),'FP' => $sourceFp->full_name
            };
            if ($type === 'FP' && config('fp.organization_enabled')) {
                $name = DB::table('fp_current_memberships')->where('id', $sourceFp->id)->value('full_name');
            }
            $recruitment = [];
            if (config('fp.recruitment_team_enabled')) {
                $profile = DB::table('team_profiles')->where('team_id', $source['recruitment_team_id'])->where('is_active', true)->first();
                if (! $profile) {
                    throw ValidationException::withMessages(['recruitment_team_id' => 'Pilih Team sumber rekrutmen yang aktif.']);
                }
                $path = null;
                $recruitment = ['recruitment_contract_version' => 2, 'recruitment_team_id' => $profile->team_id, 'recruitment_team_name_snapshot' => $profile->display_name];
            } else {
                $path = RecruitmentPaths::activeForApproval($source['recruitment_path_id'] ?? null);
            }
            $time = $this->time();
            $year = \App\Domain\FP\AnnualNip::year(CarbonImmutable::parse($time));
            $nip = $this->allocateNip($time);
            DB::table('fp_submissions')->where('id', $id)->update([...(OnboardingRevision::enabled() ? ['onboarding_contract_version' => 2] : []), 'status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_actor_id' => $actor->id, 'approved_at' => $time, 'updated_at' => $time]);
            $fields = ['identity_id', 'full_name', 'email', 'phone', 'phone_normalized', 'bank_account_encrypted', 'address_ktp', 'address_current', 'birth_place', 'birth_date', 'education_level', 'education_institution', 'graduation_year', 'team_id', 'reference_date', 'business_timezone'];
            $snapshot = array_intersect_key((array) $sub, array_flip($fields));
            $mid = DB::table('financial_planners')->insertGetId([...$snapshot, 'uuid' => (string) Str::uuid(), 'origin' => 'NEW', ...(OnboardingRevision::enabled() ? ['approval_mm_name_snapshot' => $manualMm] : []), 'submission_id' => $id, 'nip' => $nip, ...(config('fp.annual_nip_email_enabled') ? ['nip_year' => $year, 'nip_serial' => $nip % 10000] : []), 'lifecycle_state' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_actor_id' => $actor->id, 'approved_at' => $time, 'source_type' => $type, 'source_team_id' => $sourceTeam, 'source_fp_id' => $sourceFp?->id, 'source_name_snapshot' => $name, ...$recruitment, 'recruitment_path_id' => $path?->id, 'recruitment_path_code_snapshot' => $path?->code, 'recruitment_path_name_snapshot' => $path?->name, 'created_at' => $time, 'updated_at' => $time]);
            DB::table('fp_documents')->where('submission_id', $id)->update(['membership_id' => $mid, 'updated_at' => $time]);
            if (config('fp.annual_nip_email_enabled')) {
                DB::table('fp_nip_year_counters')->where('year', $year)->update(['next_serial' => ($nip % 10000) + 1]);
            } else {
                DB::table('nip_sequences')->where('id', 1)->update(['next_value' => $nip + 1]);
            }
            $this->audit('APPROVED', $actor->id, $time, $id, $mid);
            $this->scheduleNextcloudPublication($id, $mid);

            return $mid;
        });
    }

    public function rejectFpSubmission(User $actor, int $id, string $reason): void
    {
        FeatureAccess::require($actor, 'f2.reject');
        if (trim($reason) === '' || strlen($reason) > 4000) {
            throw ValidationException::withMessages(['reason' => 'Rejection reason required']);
        }
        $this->transaction(function () use ($actor, $id, $reason) {
            $sub = $this->submission($id);
            $this->requirePending($sub);
            DB::table('fp_documents')->where('submission_id', $id)->orderBy('id')->lockForUpdate()->get();
            $time = $this->time();
            DB::table('fp_submissions')->where('id', $id)->update(['status' => 'REJECTED', 'rejected_by' => $actor->id, 'rejected_actor_id' => $actor->id, 'rejected_at' => $time, 'rejection_reason' => $reason, 'updated_at' => $time]);
            foreach (DB::table('fp_documents')->where('submission_id', $id)->get() as $doc) {
                DB::table('fp_documents')->where('id', $doc->id)->update(['deletion_state' => 'REQUESTED', 'updated_at' => $time]);
                $this->documentAudit($doc->id, $id, $doc->revision, $actor->id, $time);
            }$this->audit('REJECTED', $actor->id, $time, $id);
            DB::afterCommit(static function () use ($id) {
                try {
                    DeleteRejectedDocuments::dispatch($id);
                } catch (\Throwable) {
                    report(new RuntimeException('FP deletion intent pending dispatch'));
                }
            });
        });
    }

    public function exitFp(User $actor, int $id, string $date, string $reason): void
    {
        FeatureAccess::require($actor, 'f2.exit');
        if (trim($reason) === '' || strlen($reason) > 4000) {
            throw ValidationException::withMessages(['reason' => 'Exit reason required']);
        }
        $this->transaction(function () use ($actor, $id, $date, $reason) {
            $peek = DB::table('financial_planners')->where('id', $id)->first();
            if (! $peek || ! $peek->identity_id || ! $peek->reference_date) {
                throw ValidationException::withMessages(['membership' => 'Validated membership required']);
            }
            $this->identities([$peek->identity_id]);
            $teams = [$peek->team_id];
            if (config('fp.organization_enabled')) {
                $teams = [...$teams, ...DB::table('fp_team_assignments')->where('membership_id', $id)->whereNull('ends_at')->pluck('team_id'),
                    ...DB::table('fp_team_offices')->where('membership_id', $id)->whereNull('ends_at')->pluck('team_id')];
            }
            $this->profiles($teams, false);
            $member = DB::table('financial_planners')->where('id', $id)->lockForUpdate()->first();
            if ($member->lifecycle_state !== 'ACTIVE') {
                throw ValidationException::withMessages(['membership' => 'ACTIVE required']);
            }
            try {
                $exit = ExitRule::classify($member->reference_date, $date);
            } catch (\InvalidArgumentException) {
                throw ValidationException::withMessages(['exit_date' => 'Tanggal keluar harus valid dan tidak sebelum tanggal referensi']);
            }
            $time = $this->time();
            DB::table('fp_exits')->insert([...$exit, 'membership_id' => $id, 'reference_date' => $member->reference_date, 'exit_date' => $date, 'reason' => $reason, 'actor_id' => $actor->id, 'processed_at' => $time]);
            $profile = DB::table('team_profiles')->where('team_id', $member->team_id)->first();
            if ($profile->leader_fp_id === $id) {
                DB::table('team_profiles')->where('team_id', $member->team_id)->update(['leader_fp_id' => null, 'updated_at' => $time]);
                $this->audit('TEAM_CHANGED', $actor->id, $time, null, null, null, $member->team_id, ['leader_fp_id' => null, 'is_active' => (bool) $profile->is_active]);
            }
            DB::table('financial_planners')->where('id', $id)->update(['lifecycle_state' => 'EXITED', 'updated_at' => $time]);
            $this->audit('EXITED', $actor->id, $time, $member->submission_id, $id);
        });
    }

    public function assignTeamLeader(User $actor, int $team, ?int $leader): void
    {
        FeatureAccess::require($actor, 'f2.teams');
        if (config('fp.organization_enabled')) {
            app(Organization::class)->command($actor, 'LEADER', ['team_id' => $team, 'membership_id' => $leader]);

            return;
        }
        $this->transaction(function () use ($actor, $team, $leader) {
            $peek = $leader ? DB::table('financial_planners')->where('id', $leader)->first() : null;
            if ($leader && ! $peek) {
                throw ValidationException::withMessages(['leader' => 'Leader unavailable']);
            }
            if ($peek) {
                $this->identities([$peek->identity_id]);
            }$this->profiles([$team], false);
            if ($leader) {
                $candidate = DB::table('financial_planners')->where('id', $leader)->lockForUpdate()->first();
                if ($candidate->lifecycle_state !== 'ACTIVE' || $candidate->team_id !== $team) {
                    throw ValidationException::withMessages(['leader' => 'Leader must be ACTIVE in this Team']);
                }
            }
            $profile = DB::table('team_profiles')->where('team_id', $team)->first();
            $time = $this->time();
            DB::table('team_profiles')->where('team_id', $team)->update(['leader_fp_id' => $leader, 'updated_at' => $time]);
            $this->audit('TEAM_CHANGED', $actor->id, $time, null, null, null, $team, ['leader_fp_id' => $leader, 'is_active' => (bool) $profile->is_active]);
        });
    }

    public function createTeam(User $actor, string $managerName, string $displayName): int
    {
        FeatureAccess::require($actor, 'f2.teams');
        if (config('fp.organization_enabled')) {
            return app(Organization::class)->command($actor, 'CREATE_TEAM', ['mm_fp_id' => $managerName, 'display_name' => $displayName]);
        }
        if (trim($managerName) === '' || trim($displayName) === '' || strlen($managerName) > 255 || strlen($displayName) > 255) {
            throw ValidationException::withMessages(['team' => 'Nama Team dan Nama MM wajib diisi']);
        }

        return $this->transaction(function () use ($actor, $managerName, $displayName) {
            $time = $this->time();
            $team = DB::table('teams')->insertGetId(['name' => $managerName, 'team' => $displayName, 'created_at' => $time, 'updated_at' => $time]);
            DB::table('team_profiles')->insert(['team_id' => $team, 'display_name' => $displayName, 'is_active' => true, 'created_at' => $time, 'updated_at' => $time]);
            $this->audit('TEAM_CHANGED', $actor->id, $time, null, null, null, $team, ['leader_fp_id' => null, 'is_active' => true]);

            return $team;
        });
    }

    public function saveTeamProfile(User $actor, int $team, string $name, bool $active): void
    {
        FeatureAccess::require($actor, 'f2.teams');
        if (config('fp.organization_enabled') && ! $active && DB::table('team_profiles')->where('team_id', $team)->value('is_active')) {
            throw ValidationException::withMessages(['is_active' => 'Gunakan wizard Bubarkan Team agar jabatan dan seluruh perpindahan tercatat.']);
        }
        if (config('fp.organization_enabled') && $active && DB::table('fp_team_dissolutions')->where('source_team_id', $team)->exists()) {
            throw ValidationException::withMessages(['is_active' => 'Team yang sudah dibubarkan tetap menjadi riwayat.']);
        }
        if (trim($name) === '' || strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'Team display name required']);
        }
        $this->transaction(function () use ($actor, $team, $name, $active) {
            // The FK protects legacy Team existence; the canonical profile below carries the mutation lock.
            if (! DB::table('teams')->where('id', $team)->exists()) {
                throw ValidationException::withMessages(['team' => 'Existing Team required']);
            }$old = DB::table('team_profiles')->where('team_id', $team)->lockForUpdate()->first();
            if (! $active && DB::table(config('fp.organization_enabled') ? 'fp_current_memberships' : 'financial_planners')->where('team_id', $team)->where('lifecycle_state', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages(['is_active' => 'Tim masih memiliki anggota aktif. Pembubaran memerlukan pemindahan seluruh anggota melalui workflow organisasi yang ber-audit.']);
            }
            $time = $this->time();
            DB::table('team_profiles')->updateOrInsert(['team_id' => $team], ['display_name' => $name, 'is_active' => $active, 'updated_at' => $time]);
            $this->audit('TEAM_CHANGED', $actor->id, $time, null, null, null, $team, ['leader_fp_id' => $old?->leader_fp_id, 'is_active' => $active]);
        });
    }
}
