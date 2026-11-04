<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('return_no', 64)->unique();
            $table->foreignId('purchase_id')->constrained('purchases')->restrictOnDelete();   // wajib terkait pembelian asal
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->string('reason', 32);                         // damaged, wrong_item, expired, other
            $table->decimal('total_refund', 12, 2)->default(0);   // nilai yang diklaim ke supplier
            $table->string('status', 16)->default('draft');       // draft, confirmed, settled
            $table->timestamp('returned_at')->useCurrent();
            $table->timestamps();

            $table->index('purchase_id');
            $table->index(['supplier_id', 'status']);
        });

        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_reason_valid CHECK (reason IN ('damaged','wrong_item','expired','other'))");
        DB::statement("ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_status_valid CHECK (status IN ('draft','confirmed','settled'))");
        DB::statement('ALTER TABLE purchase_returns ADD CONSTRAINT purchase_returns_refund_non_negative CHECK (total_refund >= 0)');

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained('purchase_returns')->cascadeOnDelete();
            $table->foreignId('purchase_item_id')->constrained('purchase_items')->restrictOnDelete();   // wajib dari pembelian asal
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('qty_returned');
            $table->decimal('unit_cost', 12, 2);
            $table->decimal('line_refund', 12, 2);
            $table->timestamps();

            $table->index('purchase_return_id');
            $table->index('purchase_item_id');
        });

        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT purchase_return_items_qty_positive CHECK (qty_returned > 0)');
        DB::statement('ALTER TABLE purchase_return_items ADD CONSTRAINT purchase_return_items_amount_non_negative CHECK (unit_cost >= 0 AND line_refund >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
    }
};
