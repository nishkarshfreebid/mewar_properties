
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('visit_id')
                ->constrained('visits')
                ->cascadeOnDelete();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('activity_type', 50);
            $table->string('subject', 200)->nullable();
            $table->text('notes')->nullable();

            $table->string('old_status', 30)->nullable();
            $table->string('new_status', 30)->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['visit_id', 'created_at']);
            $table->index(['user_id', 'activity_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_activities');
    }
};
