<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE enquiries DROP FOREIGN KEY enquiries_customer_id_foreign'
        );

        DB::statement(
            'ALTER TABLE enquiries MODIFY customer_id BIGINT UNSIGNED NULL'
        );

        DB::statement(
            'ALTER TABLE enquiries ADD CONSTRAINT enquiries_customer_id_foreign
             FOREIGN KEY (customer_id) REFERENCES users(id)'
        );
    }

    public function down(): void
    {
        // Keep customer_id nullable to preserve manually created enquiries.
    }
};
