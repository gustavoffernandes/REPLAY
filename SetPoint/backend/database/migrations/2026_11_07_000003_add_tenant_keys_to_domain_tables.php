<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Isolamento por empresa/filial:
 *  - quadras e turmas pertencem a uma filial;
 *  - clientes pertencem a uma empresa (compartilhados entre as filiais dela);
 *  - reservas e horários herdam a filial pela quadra.
 * Requer banco vazio (use `php artisan migrate:fresh --seed`).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            $table->foreignId('branch_id')->after('id')->constrained();
        });

        Schema::table('class_groups', function (Blueprint $table) {
            $table->foreignId('branch_id')->after('id')->constrained();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('company_id')->after('id')->constrained();
            $table->dropUnique(['phone']);
            $table->unique(['company_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'phone']);
            $table->dropConstrainedForeignId('company_id');
            $table->unique('phone');
        });
        Schema::table('class_groups', fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
        Schema::table('courts', fn (Blueprint $t) => $t->dropConstrainedForeignId('branch_id'));
    }
};
