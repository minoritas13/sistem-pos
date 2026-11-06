<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $user = config('database.connections.pgsql.username');
        DB::statement("REVOKE UPDATE, DELETE ON stock_movements FROM \"{$user}\"");
    }

    public function down(): void
    {
        $user = config('database.connections.pgsql.username');
        DB::statement("GRANT UPDATE, DELETE ON stock_movements TO \"{$user}\"");
    }
};
