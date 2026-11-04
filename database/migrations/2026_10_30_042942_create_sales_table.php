<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('invoice_no', 64)->unique();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->restrictOnDelete();   // hanya untuk kredit
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('payment_status', 16);   // paid, partial, unpaid
            $table->string('sale_type', 16);        // cash, credit
            $table->timestamps();

            $table->index('created_at');
            $table->index(['sale_type', 'payment_status']);
            $table->index('cashier_id');
        });

        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_payment_status_valid CHECK (payment_status IN ('paid','partial','unpaid'))");
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_sale_type_valid CHECK (sale_type IN ('cash','credit'))");
        DB::statement('ALTER TABLE sales ADD CONSTRAINT sales_amount_valid CHECK (subtotal >= 0 AND discount >= 0 AND discount <= subtotal AND total >= 0)');
        // customer_id terisi jika dan hanya jika penjualan kredit
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_customer_credit_rule CHECK ((sale_type = 'credit') = (customer_id IS NOT NULL))");

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('qty');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('cost_price_snapshot', 12, 2);
            $table->decimal('line_total', 12, 2);
            $table->timestamps();

            $table->index('sale_id');
            $table->index('product_id');
        });

        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_qty_positive CHECK (qty > 0)');
        DB::statement('ALTER TABLE sale_items ADD CONSTRAINT sale_items_amount_non_negative CHECK (unit_price >= 0 AND cost_price_snapshot >= 0 AND line_total >= 0)');

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained('sales')->cascadeOnDelete();
            $table->string('method', 16);                        // cash, qris, transfer
            $table->decimal('amount', 12, 2);
            $table->string('reference_no', 128)->nullable();     // nomor referensi QR
            $table->timestamp('paid_at');
            $table->timestamps();

            $table->index('sale_id');
            $table->index(['method', 'paid_at']);
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_valid CHECK (method IN ('cash','qris','transfer'))");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_positive CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
