
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('property_id')
                ->nullable()
                ->constrained('properties')
                ->restrictOnDelete();

            $table->foreignId('enquiry_id')
                ->nullable()
                ->constrained('enquiries')
                ->restrictOnDelete();

            $table->string('payment_reference', 100)->unique();

            $table->string('purpose', 50);
            $table->string('payment_method', 30);
            $table->string('payment_gateway', 50)->nullable();

            $table->char('currency', 3)->default('INR');

            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);

            $table->string('status', 30)->default('pending');

            $table->string('provider_order_id', 150)->nullable()->unique();
            $table->string('provider_payment_id', 150)->nullable()->unique();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('refunded_at')->nullable();

            $table->text('description')->nullable();
            $table->text('internal_notes')->nullable();

            $table->foreignId('recorded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['property_id', 'created_at']);
            $table->index(['enquiry_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
