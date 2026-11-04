<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receivables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->unique()->constrained('sales')->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->decimal('amount_due', 12, 2);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->date('due_date');
            $table->string('status', 16)->default('unpaid');   // unpaid, partial, paid, overdue
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'due_date']);
            $table->index('customer_id');
        });

        DB::statement("ALTER TABLE receivables ADD CONSTRAINT receivables_status_valid CHECK (status IN ('unpaid','partial','paid','overdue'))");
        DB::statement('ALTER TABLE receivables ADD CONSTRAINT receivables_amount_valid CHECK (amount_due >= 0 AND amount_paid >= 0 AND amount_paid <= amount_due)');
    }

    public function down(): void
    {
        Schema::dropIfExists('receivables');
    }
};
