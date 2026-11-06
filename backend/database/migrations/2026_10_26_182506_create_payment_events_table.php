
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();

         $table->foreignId('payment_id')
    ->nullable()
    ->constrained('payments')
    ->restrictOnDelete();

         $table->foreignId('payment_attempt_id')
    ->nullable()
    ->constrained('payment_attempts')
    ->restrictOnDelete();

            $table->string('provider', 50);
            $table->string('provider_event_id', 191);
            $table->string('event_type', 100);

            $table->string('processing_status', 30)->default('received');

            $table->timestamp('occurred_at')->nullable();
            $table->timestamp('processed_at')->nullable();

            $table->unsignedSmallInteger('processing_attempts')->default(0);
            $table->text('processing_error')->nullable();

            $table->json('payload')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['payment_id', 'created_at']);
            $table->index(['payment_attempt_id', 'created_at']);
            $table->index(['processing_status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
    }
};
