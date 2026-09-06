<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class MigrateMariaDbToPostgres extends Command
{
    /**
     * Tabel identitas dan data bisnis yang aman dipindahkan ke PostgreSQL.
     * Data reset-password, token API, dan log gagal tidak ikut dipindahkan.
     *
     * @var array<string, string|null>
     */
    private const TABLES = [
        'teams' => 'id',
        'users' => 'id',
        'permissions' => 'id',
        'roles' => 'id',
        'role_has_permissions' => null,
        'model_has_permissions' => null,
        'model_has_roles' => null,
        'fps' => 'id',
    ];

    protected $signature = 'ro:migrate-mariadb-to-postgres
        {--source=legacy_mysql : Nama koneksi MariaDB sumber}
        {--target=pgsql : Nama koneksi PostgreSQL tujuan}
        {--replace : Kosongkan tabel tujuan sebelum import}
        {--force : Jalankan --replace tanpa pertanyaan konfirmasi}';

    protected $description = 'Pindahkan data inti RO dari MariaDB ke PostgreSQL secara aman';

    public function handle(): int
    {
        $sourceName = (string) $this->option('source');
        $targetName = (string) $this->option('target');

        if ($sourceName === $targetName) {
            $this->error('Sumber dan tujuan tidak boleh memakai koneksi yang sama.');

            return self::FAILURE;
        }

        try {
            $source = DB::connection($sourceName);
            $target = DB::connection($targetName);

            $this->assertConnectionDrivers($source, $target);
            $this->assertRequiredTablesExist($sourceName, 'sumber');
            $this->assertRequiredTablesExist($targetName, 'tujuan');

            $occupiedTables = $this->occupiedTargetTables($target);

            if ($occupiedTables !== []) {
                if (! $this->option('replace')) {
                    $this->error('Tujuan sudah berisi data: ' . implode(', ', $occupiedTables) . '.');
                    $this->line('Gunakan database PostgreSQL kosong, atau jalankan kembali dengan --replace --force setelah backup diverifikasi.');

                    return self::FAILURE;
                }

                if (! $this->option('force') && ! $this->confirm('Data pada PostgreSQL tujuan akan dikosongkan. Lanjutkan?')) {
                    $this->warn('Tidak ada data yang diubah.');

                    return self::FAILURE;
                }
            }

            $this->info('Memeriksa jumlah data sumber (tanpa menampilkan data pribadi).');

            $target->transaction(function () use ($source, $target, $occupiedTables): void {
                if ($occupiedTables !== []) {
                    $this->truncateTargetTables($target);
                }

                foreach (self::TABLES as $table => $sortColumn) {
                    $this->copyTable($source, $target, $table, $sortColumn);
                }

                foreach (self::TABLES as $table => $sortColumn) {
                    if ($sortColumn !== null) {
                        $this->resetPostgresSequence($target, $table, $sortColumn);
                    }
                }

                $this->assertCopiedCountsMatch($source, $target);
            }, 1);
        } catch (Throwable) {
            $this->error('Migrasi gagal dan perubahan pada PostgreSQL tujuan telah di-rollback. Periksa koneksi serta lakukan preflight sesuai dokumentasi.');

            return self::FAILURE;
        }

        $this->info('Migrasi data inti RO ke PostgreSQL selesai dan jumlah setiap tabel sudah cocok.');
        $this->warn('Token API dan tautan reset password lama sengaja tidak dipindahkan; buat ulang bila diperlukan.');

        return self::SUCCESS;
    }

    private function assertConnectionDrivers(ConnectionInterface $source, ConnectionInterface $target): void
    {
        if ($source->getDriverName() !== 'mysql') {
            throw new RuntimeException('Koneksi sumber harus MariaDB/MySQL.');
        }

        if ($target->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Koneksi tujuan harus PostgreSQL.');
        }
    }

    private function assertRequiredTablesExist(string $connection, string $label): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                throw new RuntimeException("Tabel {$table} tidak ditemukan pada {$label}.");
            }
        }
    }

    /**
     * @return list<string>
     */
    private function occupiedTargetTables(ConnectionInterface $target): array
    {
        $occupied = [];

        foreach (array_keys(self::TABLES) as $table) {
            if ($target->table($table)->exists()) {
                $occupied[] = $table;
            }
        }

        return $occupied;
    }

    private function truncateTargetTables(ConnectionInterface $target): void
    {
        $tables = implode(', ', array_map(
            fn (string $table): string => $target->getQueryGrammar()->wrapTable($table),
            array_keys(self::TABLES),
        ));

        $target->statement("TRUNCATE TABLE {$tables} RESTART IDENTITY CASCADE");
    }

    private function copyTable(ConnectionInterface $source, ConnectionInterface $target, string $table, ?string $sortColumn): void
    {
        $sourceCount = $source->table($table)->count();
        $this->line("- {$table}: {$sourceCount} baris");

        if ($sourceCount === 0) {
            return;
        }

        $query = $source->table($table);

        if ($sortColumn !== null) {
            $query->orderBy($sortColumn);
        }

        foreach ($query->cursor() as $record) {
            $target->table($table)->insert((array) $record);
        }
    }

    private function resetPostgresSequence(ConnectionInterface $target, string $table, string $column): void
    {
        $maximum = $target->table($table)->max($column);

        if ($maximum === null) {
            return;
        }

        $sequence = $target->selectOne(
            'SELECT pg_get_serial_sequence(?, ?) AS sequence_name',
            [$table, $column],
        )->sequence_name;

        if ($sequence !== null) {
            $target->statement('SELECT setval(?::regclass, ?, true)', [$sequence, $maximum]);
        }
    }

    private function assertCopiedCountsMatch(ConnectionInterface $source, ConnectionInterface $target): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            $sourceCount = $source->table($table)->count();
            $targetCount = $target->table($table)->count();

            if ($sourceCount !== $targetCount) {
                throw new RuntimeException("Jumlah {$table} pada tujuan tidak sesuai.");
            }
        }
    }
}
