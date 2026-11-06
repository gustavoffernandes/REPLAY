<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('court_id')->constrained();
            $table->foreignId('customer_id')->constrained();
            $table->enum('sport', ['volei', 'futvolei', 'beachtenis']);
            $table->date('booking_date');
            $table->unsignedTinyInteger('start_hour');
            $table->unsignedTinyInteger('end_hour');
            $table->enum('status', ['confirmed', 'pending', 'cancelled', 'completed'])->default('confirmed');
            $table->decimal('price', 8, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index(['court_id', 'booking_date']);
            $table->index('booking_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
