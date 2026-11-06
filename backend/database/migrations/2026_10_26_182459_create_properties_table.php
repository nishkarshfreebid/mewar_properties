
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('properties', function (Blueprint $table) {
            $table->id();

            $table->foreignId('owner_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('assigned_agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('approved_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title', 200);
            $table->string('slug', 220)->unique();
            $table->text('description')->nullable();

            $table->string('property_type', 50);
            $table->string('listing_type', 30);

            $table->decimal('price', 14, 2);
            $table->unsignedSmallInteger('bedrooms')->nullable();
            $table->unsignedSmallInteger('bathrooms')->nullable();
            $table->decimal('area', 12, 2)->nullable();
            $table->string('area_unit', 20)->nullable();

            $table->string('address_line')->nullable();
            $table->string('locality', 150)->nullable();
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 100)->default('India');

            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();

            $table->string('approval_status', 30)->default('pending');
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->string('publication_status', 30)->default('draft');
            $table->timestamp('published_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['approval_status', 'publication_status']);
            $table->index(['city', 'property_type']);
            $table->index(['owner_id', 'created_at']);
            $table->index(['assigned_agent_id', 'publication_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
