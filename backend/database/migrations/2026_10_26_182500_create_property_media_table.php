
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_media', function (Blueprint $table) {
            $table->id();

            $table->foreignId('property_id')
                ->constrained('properties')
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('media_type', 30);
            $table->string('disk', 50)->default('public');
            $table->string('file_path', 500);
            $table->string('original_filename', 255)->nullable();
            $table->string('mime_type', 150)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();

            $table->string('caption', 255)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_primary')->default(false);

            $table->timestamps();

            $table->index(['property_id', 'media_type']);
            $table->index(['property_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_media');
    }
};
