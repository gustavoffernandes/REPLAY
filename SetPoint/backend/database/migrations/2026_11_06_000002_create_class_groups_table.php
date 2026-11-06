<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('class_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 80);
            $table->enum('sport', ['volei', 'futvolei', 'beachtenis']);
            $table->string('instructor', 80)->nullable();
            $table->unsignedSmallInteger('capacity')->default(12);
            $table->decimal('monthly_fee', 8, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('class_group_customer', function (Blueprint $table) {
            $table->foreignId('class_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->primary(['class_group_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_group_customer');
        Schema::dropIfExists('class_groups');
    }
};
