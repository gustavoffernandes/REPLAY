<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('replays', function (Blueprint $table) {
            $table->id();
            $table->string('replay_id')->unique();     // id gerado pelo serviço de captura
            $table->string('quadra_id')->index();
            $table->string('origem');                  // site | botoeira | botoeira-simulada
            $table->timestamp('clicado_em')->nullable();
            $table->string('arquivo');                 // caminho no disco "public"
            $table->unsignedBigInteger('tamanho_bytes')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('replays');
    }
};
