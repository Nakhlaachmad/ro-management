<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Fp extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'alamat',
        'no_tlfn',
        'lulusan',
        'tanggal_masuk',
        'tanggal_keluar', // ✅ tambahkan
        'team_id',
        'status',
        'created_by',
        'comment', // kalau pakai comment
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

    protected static function booted()
    {
        static::updating(function ($fp) {

            // ✅ Jika status berubah ke nonaktif → isi tanggal keluar
            if ($fp->isDirty('status') && $fp->status === 'nonaktif') {
                $fp->tanggal_keluar = now();
            }

            // ✅ Jika status balik ke aktif → hapus tanggal keluar
            if ($fp->isDirty('status') && $fp->status === 'aktif') {
                $fp->tanggal_keluar = null;
            }
        });
    }
}
