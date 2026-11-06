
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enquiry_activities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('enquiry_id')
                ->constrained('enquiries')
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

            $table->timestamp('follow_up_at')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['enquiry_id', 'created_at']);
            $table->index(['user_id', 'activity_type']);
            $table->index('follow_up_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enquiry_activities');
    }
};
