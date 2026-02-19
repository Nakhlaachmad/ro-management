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
            $table->text('alamat');
            $table->string('no_tlfn');
            $table->string('lulusan');
            $table->date('tanggal_masuk');
            $table->date('tanggal_keluar')->nullable();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->text('comment')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fps');
    }
};
