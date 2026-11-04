<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('phone', 32)->nullable()->unique();
            $table->decimal('credit_limit', 12, 2)->default(0);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_credit_limit_non_negative CHECK (credit_limit >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
