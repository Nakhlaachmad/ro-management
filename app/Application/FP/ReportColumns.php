<?php

declare(strict_types=1);

namespace App\Application\FP;

use App\Models\FpMembership;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ReportColumns
{
    public const DEFAULTS = ['id', 'name', 'nip', 'team', 'source', 'status', 'reference_date', 'exit_date', 'duration'];

    public const AVAILABLE = ['id' => 'Membership ID', 'name' => 'Nama FP', 'nip' => 'NIP', 'team' => 'Team', 'source' => 'Sumber Rekrutmen', 'status' => 'Status', 'reference_date' => 'Tanggal referensi pendaftaran', 'exit_date' => 'Tanggal keluar', 'duration' => 'Durasi (hari)', 'email' => 'Email', 'exit_reason' => 'Alasan keluar', 'nik' => 'NIK', 'bank_account' => 'Nomor rekening', 'phone' => 'Nomor telepon', 'address_ktp' => 'Alamat KTP', 'address' => 'Alamat domisili'];

    public static function selected(mixed $input): array
    {
        if (! is_array($input) || count($input) < 1 || count($input) > count(self::AVAILABLE)) {
            throw ValidationException::withMessages(['columns' => 'Pilih minimal satu kolom ekspor yang tersedia.']);
        }
        foreach ($input as $key) {
            if (! is_string($key)) {
                throw ValidationException::withMessages(['columns' => 'Pilihan kolom tidak valid.']);
            }
            if (! array_key_exists($key, self::AVAILABLE)) {
                throw ValidationException::withMessages(['columns' => 'Kolom ekspor tidak tersedia.']);
            }
        }

        return array_values(array_intersect(array_keys(self::AVAILABLE), $input));
    }

    public static function headers(array $columns): array
    {
        return array_map(fn ($key) => self::AVAILABLE[$key], $columns);
    }

    public static function prepare(Builder $query, array $columns): Builder
    {
        // Fetch/decrypt sensitive values only for explicitly selected columns.
        foreach (['bank_account' => 'bank_account_encrypted', 'phone' => 'phone', 'address_ktp' => 'address_ktp', 'address' => 'address_current'] as $key => $field) {
            if (in_array($key, $columns, true)) {
                $query->addSelect('financial_planners.'.$field);
            }
        }
        if (in_array('nik', $columns, true)) {
            $query->addSelect('financial_planners.identity_id')->with('identity:id,nik_encrypted');
        }
        if (in_array('email', $columns, true)) {
            $query->addSelect('financial_planners.email');
        }
        if (in_array('exit_reason', $columns, true)) {
            $query->with(['exit:membership_id,exit_date,duration_days,type,reason']);
        }

        return $query;
    }

    public static function row(FpMembership $fp, array $columns): array
    {
        $values = array_combine(self::DEFAULTS, FpReports::row($fp));
        $values['email'] = $fp->email ?? '';
        $values['exit_reason'] = $fp->exit?->reason ?? '';

        foreach (['bank_account' => 'bank_account_encrypted', 'phone' => 'phone', 'address_ktp' => 'address_ktp', 'address' => 'address_current'] as $key => $field) {
            if (in_array($key, $columns, true)) {
                $raw = $fp->getRawOriginal($field);
                $values[$key] = $key === 'bank_account' && $raw !== null ? \Illuminate\Support\Facades\Crypt::decryptString($raw) : ($raw ?? '');
            }
        }
        if (in_array('nik', $columns, true)) {
            $raw = $fp->identity?->getRawOriginal('nik_encrypted');
            $values['nik'] = $raw !== null ? \Illuminate\Support\Facades\Crypt::decryptString($raw) : '';
        }

        return array_map(fn ($key) => $values[$key], $columns);
    }
}
