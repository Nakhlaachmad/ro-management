<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
public function up(): void
{
    Schema::create('fps', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // nama
        $table->string('no_tlfn'); // no tlfn
        $table->string('no_rekening'); // no rekening
        $table->string('no_ktp'); // no ktp
        $table->text('alamat_ktp'); // alamat ktp
        $table->text('alamat'); // alamat tinggal
        $table->string('tempat_lahir'); // tempat lahir
        $table->date('tanggal_lahir'); // tanggal lahir
        $table->string('lulusan'); // lulusan
        $table->enum('grade', ['A', 'B']); // grade A / B
        $table->foreignId('team_id')->constrained()->cascadeOnDelete(); // team
        $table->enum('jalur', ['RO', 'MGM']); // jalur
        $table->date('tanggal_masuk');
        $table->date('tanggal_keluar')->nullable();
        $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->text('comment')->nullable();
        $table->timestamps();
    });
}


    public function down(): void
    {
        Schema::dropIfExists('fps');
    }
};
