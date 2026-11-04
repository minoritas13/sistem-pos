<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_payables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->decimal('amount_due', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->date('due_date');
            $table->string('status', 16)->default('unpaid');   // unpaid, partial, paid, overdue
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('supplier_id');
        });

        DB::statement("ALTER TABLE supplier_payables ADD CONSTRAINT supplier_payables_status_valid CHECK (status IN ('unpaid','partial','paid','overdue'))");
        DB::statement('ALTER TABLE supplier_payables ADD CONSTRAINT supplier_payables_amount_non_negative CHECK (amount_due >= 0 AND amount_paid >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payables');
    }
};
