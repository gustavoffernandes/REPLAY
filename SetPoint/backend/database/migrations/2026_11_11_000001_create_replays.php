<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replays instantâneos: cada quadra pode ter uma câmera (serviço de captura local) e
 * cada clique na botoeira (física ou virtual) gera um vídeo dos últimos 30 segundos.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('courts', function (Blueprint $table) {
            // Endereço do serviço de captura desta quadra (ex.: http://127.0.0.1:8765)
            $table->string('replay_url', 255)->nullable()->after('active');
            // Token compartilhado com o serviço de captura: criptografado (o back precisa dele para disparar)
            // e em hash (para achar a quadra quando o serviço envia um vídeo).
            $table->text('replay_token')->nullable()->after('replay_url');
            $table->char('replay_token_hash', 64)->nullable()->unique()->after('replay_token');
        });

        Schema::create('replays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained()->cascadeOnDelete();
            $table->string('capture_id', 100);              // id gerado pelo serviço de captura
            $table->string('source', 30);                   // site | botoeira | botoeira-simulada
            $table->timestamp('triggered_at')->nullable();  // momento do clique
            $table->string('path');                         // arquivo no disco "local" (privado)
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->timestamps();

            $table->unique(['court_id', 'capture_id']);
            $table->index(['branch_id', 'triggered_at']);
        });

        // Papéis do sistema que já existem recebem as permissões novas.
        $grants = [
            'owner' => ['replays.view', 'replays.create', 'replays.delete'],
            'manager' => ['replays.view', 'replays.create', 'replays.delete'],
            'staff' => ['replays.view', 'replays.create'],
        ];
        foreach ($grants as $key => $permissions) {
            if ($roleId = DB::table('roles')->where('key', $key)->value('id')) {
                DB::table('role_permissions')->insertOrIgnore(
                    array_map(fn ($p) => ['role_id' => $roleId, 'permission' => $p], $permissions)
                );
            }
        }
    }

    public function down(): void
    {
        DB::table('role_permissions')->where('permission', 'like', 'replays.%')->delete();
        Schema::dropIfExists('replays');
        Schema::table('courts', function (Blueprint $table) {
            $table->dropUnique(['replay_token_hash']);
            $table->dropColumn(['replay_url', 'replay_token', 'replay_token_hash']);
        });
    }
};
