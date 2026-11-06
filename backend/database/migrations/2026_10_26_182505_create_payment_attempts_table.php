
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payment_id')
                ->constrained('payments')
                ->restrictOnDelete();

            $table->unsignedSmallInteger('attempt_number');

            $table->string('payment_gateway', 50);
            $table->string('provider_order_id', 150)->nullable()->unique();
            $table->string('provider_payment_id', 150)->nullable()->unique();

            $table->string('status', 30)->default('initiated');

$table->decimal('amount', 14, 2);
$table->char('currency', 3)->default('INR');


            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();

            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();

            $table->unique(['payment_id', 'attempt_number']);
            $table->index(['payment_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
