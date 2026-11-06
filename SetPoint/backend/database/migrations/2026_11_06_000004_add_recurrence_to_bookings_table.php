<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->foreignId('class_group_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->after('class_group_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('schedule_id');
            $table->dropConstrainedForeignId('class_group_id');
        });
    }
};
