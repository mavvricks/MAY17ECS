<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE packages ALTER COLUMN type TYPE VARCHAR(255) USING type::text');
        DB::table('packages')->where('type', 'wedding')->update(['type' => 'formal-wedding']);
        DB::table('packages')->where('type', 'corporate')->update(['type' => 'corporate-seminar']);
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE packages ALTER COLUMN type TYPE VARCHAR(255) USING CASE WHEN type IN ('wedding', 'corporate', 'social', 'other') THEN type ELSE 'other' END");
    }
};
