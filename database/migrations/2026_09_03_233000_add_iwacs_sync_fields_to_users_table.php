<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('sso_subject', 100)->nullable()->unique()->after('id');
            $table->unsignedInteger('profile_version')->default(1)->after('password');
            $table->boolean('is_active')->default(true)->after('profile_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['sso_subject']);
            $table->dropColumn(['sso_subject', 'profile_version', 'is_active']);
        });
    }
};
