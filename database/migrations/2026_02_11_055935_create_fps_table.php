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
        $table->string('name');
        $table->string('no_tlfn'); 
        $table->string('no_rekening');
        $table->string('no_ktp'); 
        $table->text('alamat_ktp');
        $table->text('alamat');
        $table->string('tempat_lahir');
        $table->date('tanggal_lahir');
        $table->string('lulusan');
        $table->enum('grade', ['A', 'B']);
        $table->foreignId('team_id')->constrained()->cascadeOnDelete();
        $table->enum('jalur', ['RO', 'MGM']);
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
