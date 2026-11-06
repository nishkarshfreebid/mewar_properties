
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained('properties')
                ->restrictOnDelete();

            $table->foreignId('customer_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('customer_name', 150);
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 30)->nullable();

            $table->text('message')->nullable();

            $table->string('status', 30)->default('new');
            $table->string('source', 30)->default('website');

            $table->timestamp('last_contacted_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('closing_notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['property_id', 'status']);
            $table->index(['customer_id', 'created_at']);
            $table->index(['assigned_agent_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiries');
    }
};
