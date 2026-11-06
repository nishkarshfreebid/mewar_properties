<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('idempotency_key', 100)
                ->nullable()
                ->after('payment_reference');

            $table->unique(
                ['customer_id', 'idempotency_key'],
                'payments_customer_idempotency_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_customer_idempotency_unique');
            $table->dropColumn('idempotency_key');
        });
    }
};