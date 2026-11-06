<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Papéis e permissões editáveis. Os 4 papéis do sistema nascem com os padrões de Permissions::systemRoles(). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 60)->unique();        // valor gravado em users.role
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            // all = toda a plataforma | company = todas as filiais da empresa | branches = só as filiais atribuídas
            $table->enum('scope', ['all', 'company', 'branches']);
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->string('permission', 60);
            $table->primary(['role_id', 'permission']);
        });

        foreach (Permissions::systemRoles() as $key => [$name, $description, $scope, $permissions]) {
            $id = DB::table('roles')->insertGetId([
                'key' => $key, 'name' => $name, 'description' => $description, 'scope' => $scope,
                'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('role_permissions')->insert(array_map(fn ($p) => ['role_id' => $id, 'permission' => $p], $permissions));
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }
};
