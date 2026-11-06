<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained('properties')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('enquiry_id')
                ->nullable()
                ->constrained('enquiries')
                ->nullOnDelete();

            $table->foreignId('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(30);

            $table->string('status', 30)->default('scheduled');

            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            $table->text('customer_notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->text('cancellation_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['property_id', 'scheduled_at']);
            $table->index(['customer_id', 'scheduled_at']);
            $table->index(['assigned_agent_id', 'scheduled_at']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
