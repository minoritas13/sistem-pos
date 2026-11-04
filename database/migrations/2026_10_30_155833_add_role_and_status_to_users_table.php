<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Menambahkan kolom role (misal: admin, cashier, staff) dengan default 'cashier'
            $table->string('role')->default('cashier')->after('email');

            // Menambahkan kolom status (misal: active, inactive) dengan default 'active'
            $table->string('status')->default('active')->after('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Wajib mendefinisikan cara rollback-nya jika perintah migrate:rollback dijalankan
            $table->dropColumn(['role', 'status']);
        });
    }
};
