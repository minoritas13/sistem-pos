<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_no', 64)->unique();
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('status', 16)->default('draft');   // draft, received, partial_return, closed
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['supplier_id', 'status']);
        });

        DB::statement("ALTER TABLE purchases ADD CONSTRAINT purchases_status_valid CHECK (status IN ('draft','received','partial_return','closed'))");
        DB::statement('ALTER TABLE purchases ADD CONSTRAINT purchases_amount_non_negative CHECK (total >= 0 AND paid_amount >= 0)');

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained('purchases')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('qty');                              // jumlah diterima
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->integer('qty_returned')->default(0);         // akumulasi yang sudah diretur ke supplier
            $table->timestamps();

            $table->index('purchase_id');
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_amount_non_negative CHECK (unit_cost >= 0 AND line_total >= 0)');
        // Retur tidak boleh melebihi jumlah yang diterima
        DB::statement('ALTER TABLE purchase_items ADD CONSTRAINT purchase_items_qty_returned_valid CHECK (qty_returned >= 0 AND qty_returned <= qty)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
