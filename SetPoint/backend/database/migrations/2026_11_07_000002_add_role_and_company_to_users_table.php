<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // super_admin (sem empresa) | owner | manager | staff
            $table->string('role', 20)->default('staff')->after('password');
            $table->foreignId('company_id')->nullable()->after('role')->constrained()->cascadeOnDelete();
            $table->boolean('active')->default(true)->after('company_id');
        });

        // Filiais que gerentes e atendentes podem acessar (donos acessam todas da empresa)
        Schema::create('branch_user', function (Blueprint $table) {
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['branch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'active']);
        });
    }
};
