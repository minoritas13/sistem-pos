<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('type', 32);
            $table->integer('qty');                              // selalu positif; arah ditentukan type
            $table->string('reference_type', 64)->nullable();    // purchase, sale, purchase_return
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->integer('stock_before');
            $table->integer('stock_after');
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();       // append-only: tanpa updated_at

            $table->index(['product_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_stock_non_negative CHECK (stock_before >= 0 AND stock_after >= 0)');

        // Memvalidasi jenis sekaligus arah: type masuk menambah, type keluar mengurangi
        DB::statement("ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_direction_valid CHECK (
            (type IN ('purchase_in','adjustment_in') AND stock_after = stock_before + qty)
            OR
            (type IN ('sale_out','purchase_return_out','adjustment_out') AND stock_after = stock_before - qty)
        )");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
