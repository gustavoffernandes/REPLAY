<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Agendamento online por clientes avulsos: slugs públicos, sinal (pagamento parcial) e pagamentos. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->unique()->after('name');
            $table->unsignedTinyInteger('deposit_percent')->default(50)->after('phone'); // % do valor cobrado como sinal
        });

        Schema::table('branches', function (Blueprint $table) {
            $table->string('slug', 80)->nullable()->after('name');
            $table->unique(['company_id', 'slug']);
        });

        DB::statement("ALTER TABLE bookings MODIFY status ENUM('awaiting_payment','confirmed','pending','cancelled','completed') NOT NULL DEFAULT 'confirmed'");

        Schema::table('bookings', function (Blueprint $table) {
            $table->enum('source', ['counter', 'online'])->default('counter')->after('status');
            $table->decimal('paid_amount', 8, 2)->default(0)->after('price');
            $table->timestamp('expires_at')->nullable()->after('paid_amount'); // fim da reserva provisória (aguardando sinal)
            $table->string('public_code', 12)->nullable()->unique()->after('expires_at'); // código p/ o cliente acompanhar
            $table->index(['status', 'expires_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 30);            // fake | mercadopago | ...
            $table->string('provider_ref', 80)->nullable();
            $table->string('method', 20)->default('pix');
            $table->decimal('amount', 8, 2);
            $table->enum('status', ['pending', 'paid', 'expired', 'cancelled'])->default('pending');
            $table->text('pix_code')->nullable();      // "copia e cola"
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->index('provider_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['status', 'expires_at']);
            $table->dropColumn(['source', 'paid_amount', 'expires_at', 'public_code']);
        });
        DB::statement("ALTER TABLE bookings MODIFY status ENUM('confirmed','pending','cancelled','completed') NOT NULL DEFAULT 'confirmed'");
        Schema::table('branches', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'slug']);
            $table->dropColumn('slug');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['slug', 'deposit_percent']);
        });
    }
};
