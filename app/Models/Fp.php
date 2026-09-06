<?php

namespace App\Models;
use Illuminate\Validation\ValidationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

class Fp extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'alamat',
        'alamat_ktp',
        'no_tlfn',
        'no_rekening',
        'no_ktp',
        'tempat_lahir',
        'tanggal_lahir',
        'lulusan',
        'grade',
        'team_id',
        'jalur',
        'tanggal_masuk',
        'tanggal_keluar',
        'status',
        'created_by',
        'comment',
    ];


    protected $casts = [
        'tanggal_masuk' => 'date',
        'tanggal_keluar' => 'date', // ✅ tambahkan
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Filter FP keluar berdasarkan batas dua bulan dari tanggal masuk.
     *
     * Aturan bisnisnya sama pada MariaDB dan PostgreSQL; hanya sintaks
     * penambahan tanggal yang berbeda di kedua database.
     */
    public function scopeKeluarNormal(Builder $query): Builder
    {
        return $query
            ->whereNotNull('tanggal_keluar')
            ->whereNotNull('tanggal_masuk')
            ->whereRaw('tanggal_keluar >= ' . $this->batasKeluarNormalSql($query));
    }

    public function scopeKeluarTidakNormal(Builder $query): Builder
    {
        return $query
            ->whereNotNull('tanggal_keluar')
            ->whereNotNull('tanggal_masuk')
            ->whereRaw('tanggal_keluar < ' . $this->batasKeluarNormalSql($query));
    }

    private function batasKeluarNormalSql(Builder $query): string
    {
        return $query->getQuery()->getConnection()->getDriverName() === 'pgsql'
            ? "tanggal_masuk + INTERVAL '2 months'"
            : 'DATE_ADD(tanggal_masuk, INTERVAL 2 MONTH)';
    }

    protected static function booted()
    {
        static::creating(function ($fp) {
            if ($fp->tanggal_masuk && Carbon::parse($fp->tanggal_masuk)->isWeekend()) {
                throw ValidationException::withMessages([
                    'tanggal_masuk' => 'Tanggal masuk tidak boleh hari Sabtu atau Minggu.',
                ]);
            }
        });

        static::updating(function ($fp) {

            if ($fp->isDirty('tanggal_masuk') && Carbon::parse($fp->tanggal_masuk)->isWeekend()) {
                throw ValidationException::withMessages([
                    'tanggal_masuk' => 'Tanggal masuk tidak boleh hari Sabtu atau Minggu.',
                ]);
            }

            if ($fp->isDirty('status') && $fp->status === 'nonaktif') {
                $fp->tanggal_keluar = now();
            }
        });
    }
    
}
