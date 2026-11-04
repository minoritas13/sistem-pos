<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 64)->unique();
            $table->string('name', 255);
            $table->foreignId('category_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->decimal('cost_price', 12, 2)->default(0);   // harga modal terakhir
            $table->decimal('sell_price', 12, 2)->default(0);
            $table->integer('stock_current')->default(0);       // hanya diubah lewat StockService
            $table->integer('stock_min')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('name');
            $table->index('is_active');
        });

        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_current_non_negative CHECK (stock_current >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_stock_min_non_negative CHECK (stock_min >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_price_non_negative CHECK (cost_price >= 0 AND sell_price >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
