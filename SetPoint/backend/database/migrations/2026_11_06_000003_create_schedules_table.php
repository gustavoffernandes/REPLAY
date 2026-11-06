<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Regra de recorrência semanal: de turma (class_group_id) ou de cliente fixo (customer_id)
        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('court_id')->constrained();
            $table->enum('sport', ['volei', 'futvolei', 'beachtenis']);
            $table->unsignedTinyInteger('weekday'); // 0 = domingo ... 6 = sábado
            $table->unsignedTinyInteger('start_hour');
            $table->unsignedTinyInteger('end_hour');
            $table->decimal('price', 8, 2)->default(0); // valor de cada ocorrência
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
