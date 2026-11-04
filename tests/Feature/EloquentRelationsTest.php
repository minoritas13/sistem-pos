<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class EloquentRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_relasi_pembelian_dan_retur_supplier(): void
    {
        $supplier = Supplier::create(['name' => 'PT Sumber']);
        $category = Category::create(['name' => 'Minuman']);
        $product  = Product::create([
            'sku' => 'SKU-1', 'name' => 'Teh Botol', 'category_id' => $category->id,
            'cost_price' => 5000, 'sell_price' => 7000, 'stock_min' => 3,
        ]);

        $purchase = Purchase::create([
            'purchase_no' => 'PO-1', 'supplier_id' => $supplier->id,
            'status' => 'received', 'total' => 50000, 'received_at' => now(),
        ]);
        $item = $purchase->items()->create([
            'product_id' => $product->id, 'qty' => 10, 'unit_cost' => 5000, 'line_total' => 50000,
        ]);
        $purchase->payable()->create([
            'supplier_id' => $supplier->id, 'amount_due' => 50000, 'due_date' => now()->addDays(14),
        ]);
        $return = $purchase->returns()->create([
            'return_no' => 'RT-1', 'supplier_id' => $supplier->id,
            'reason' => 'damaged', 'total_refund' => 10000,
        ]);
        $return->items()->create([
            'purchase_item_id' => $item->id, 'product_id' => $product->id,
            'qty_returned' => 2, 'unit_cost' => 5000, 'line_refund' => 10000,
        ]);

        $this->assertSame($category->id, $product->category->id);
        $this->assertSame($supplier->id, $purchase->supplier->id);
        $this->assertCount(1, $supplier->purchases);
        $this->assertCount(1, $supplier->purchaseReturns);
        $this->assertCount(1, $supplier->payables);
        $this->assertSame($purchase->id, $purchase->payable->purchase->id);
        $this->assertSame($return->id, $item->returnItems->first()->purchaseReturn->id);
        $this->assertSame($item->id, $return->items->first()->purchaseItem->id);
        $this->assertSame(10, $item->returnableQty());
        $this->assertSame('50000.00', $purchase->fresh()->total);
    }

    public function test_relasi_penjualan_kredit_dan_pembayaran(): void
    {
        $cashier  = User::factory()->create();
        $customer = Customer::create(['name' => 'Budi', 'credit_limit' => 100000]);
        $product  = Product::create([
            'sku' => 'SKU-2', 'name' => 'Roti', 'cost_price' => 3000, 'sell_price' => 5000,
        ]);

        $sale = Sale::create([
            'invoice_no' => 'INV-1', 'customer_id' => $customer->id, 'cashier_id' => $cashier->id,
            'subtotal' => 10000, 'discount' => 0, 'total' => 10000,
            'payment_status' => 'unpaid', 'sale_type' => 'credit',
        ]);
        $sale->items()->create([
            'product_id' => $product->id, 'qty' => 2, 'unit_price' => 5000,
            'cost_price_snapshot' => 3000, 'line_total' => 10000,
        ]);
        $sale->payments()->create([
            'method' => 'qris', 'amount' => 4000, 'reference_no' => 'QR-1', 'paid_at' => now(),
        ]);
        $sale->receivable()->create([
            'customer_id' => $customer->id, 'amount_due' => 6000, 'due_date' => now()->addDays(7),
        ]);

        $this->assertSame($cashier->id, $sale->cashier->id);
        $this->assertSame($customer->id, $sale->customer->id);
        $this->assertCount(1, $cashier->sales);
        $this->assertCount(1, $customer->sales);
        $this->assertCount(1, $customer->receivables);
        $this->assertCount(1, $product->saleItems);
        $this->assertSame($sale->id, $sale->payments->first()->sale->id);
        $this->assertSame($sale->id, $sale->receivable->sale->id);
    }

    public function test_stock_current_tidak_bisa_diisi_massal(): void
    {
        $product = Product::create([
            'sku' => 'SKU-3', 'name' => 'Gula', 'cost_price' => 1000,
            'sell_price' => 2000, 'stock_current' => 99,
        ]);

        $this->assertSame(0, $product->fresh()->stock_current);
    }

    public function test_stock_movement_append_only(): void
    {
        $product = Product::create(['sku' => 'SKU-4', 'name' => 'Kopi', 'cost_price' => 1, 'sell_price' => 2]);

        $movement = $product->stockMovements()->create([
            'type' => 'purchase_in', 'qty' => 5, 'stock_before' => 0, 'stock_after' => 5,
        ]);

        $this->assertSame($product->id, $movement->product->id);

        $this->expectException(LogicException::class);
        $movement->update(['note' => 'diubah']);
    }

    public function test_stock_movement_tidak_bisa_dihapus(): void
    {
        $product = Product::create(['sku' => 'SKU-5', 'name' => 'Teh', 'cost_price' => 1, 'sell_price' => 2]);

        $movement = $product->stockMovements()->create([
            'type' => 'purchase_in', 'qty' => 5, 'stock_before' => 0, 'stock_after' => 5,
        ]);

        $this->expectException(LogicException::class);
        $movement->delete();
    }
}
