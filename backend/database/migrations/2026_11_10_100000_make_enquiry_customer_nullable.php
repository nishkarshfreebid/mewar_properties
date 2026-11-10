<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Manual enquiries may legitimately have no customer login account.
        // Keep this column nullable when rolling back unrelated migrations.
    }
};
