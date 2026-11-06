<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Exclusão de turmas (lógica, para preservar o histórico de aulas) e a permissão classes.delete. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->softDeletes();
        });

        // Proprietário e Gerente passam a poder excluir turmas (o administrador já tem tudo).
        foreach (DB::table('roles')->whereIn('key', ['owner', 'manager'])->pluck('id') as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(['role_id' => $roleId, 'permission' => 'classes.delete']);
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'classes.delete')->delete();
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
