<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseReturnServiceTest extends TestCase
{
    use RefreshDatabase;

    /** Pembelian received: A (10 x 5.000) + B (5 x 3.000) = 65.000, beda kategori. */
    private function receivedPurchase(string $prefix = 'X', bool $tempo = true): array
    {
        $supplier = Supplier::create(['name' => 'PT Serba Ada '.$prefix])->fresh();
        $a = Product::create([
            'sku' => "{$prefix}-A", 'name' => 'A', 'cost_price' => 1, 'sell_price' => 99999,
            'category_id' => Category::firstOrCreate(['name' => 'Minuman'])->id,
        ]);
        $b = Product::create([
            'sku' => "{$prefix}-B", 'name' => 'B', 'cost_price' => 1, 'sell_price' => 99999,
            'category_id' => Category::firstOrCreate(['name' => 'Makanan'])->id,
        ]);

        $service = app(PurchaseService::class);
        $purchase = $service->create($supplier, [
            ['product_id' => $a->id, 'qty' => 10, 'unit_cost' => 5000],
            ['product_id' => $b->id, 'qty' => 5,  'unit_cost' => 3000],
        ]);
        $purchase = $service->receive($purchase, $tempo ? now()->addDays(14) : null)->load('items');

        return [
            $purchase, $a, $b,
            $purchase->items->firstWhere('product_id', $a->id),
            $purchase->items->firstWhere('product_id', $b->id),
        ];
    }

    public function test_create_menyimpan_draft_tanpa_mengubah_stok(): void
    {
        [$purchase, $a, , $itemA] = $this->receivedPurchase();

        $return = app(PurchaseReturnService::class)->create(
            $purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 4]]
        );

        $this->assertSame('draft', $return->status);
        $this->assertSame('20000.00', $return->total_refund);
        $this->assertSame($purchase->supplier_id, $return->supplier_id);
        $this->assertSame(10, $a->fresh()->stock_current);
        $this->assertSame(0, $itemA->fresh()->qty_returned);
        $this->assertSame('65000.00', $purchase->payable->fresh()->amount_due);
    }

    public function test_confirm_retur_sebagian(): void
    {
        [$purchase, $a, $b, $itemA] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);

        $return = $service->create($purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 4]]);
        $confirmed = $service->confirm($return);

        $this->assertSame('settled', $confirmed->status);
        $this->assertSame(6, $a->fresh()->stock_current);
        $this->assertSame(5, $b->fresh()->stock_current);
        $this->assertSame(4, $itemA->fresh()->qty_returned);
        $this->assertSame('partial_return', $purchase->fresh()->status);
        $this->assertSame('45000.00', $purchase->payable->fresh()->amount_due);

        $movement = StockMovement::where('type', 'purchase_return_out')->sole();
        $this->assertSame(4, $movement->qty);
        $this->assertSame('purchase_return', $movement->reference_type);
        $this->assertSame($return->id, $movement->reference_id);
    }

    public function test_retur_semua_item_menutup_pembelian_dan_melunasi_utang(): void
    {
        [$purchase, $a, $b, $itemA, $itemB] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);

        $return = $service->create($purchase, 'expired', [
            ['purchase_item_id' => $itemA->id, 'qty_returned' => 10],
            ['purchase_item_id' => $itemB->id, 'qty_returned' => 5],
        ]);
        $service->confirm($return);

        $this->assertSame('closed', $purchase->fresh()->status);
        $this->assertSame(0, $a->fresh()->stock_current);
        $this->assertSame(0, $b->fresh()->stock_current);
        $this->assertSame('0.00', $purchase->payable->fresh()->amount_due);
        $this->assertSame('paid', $purchase->payable->fresh()->status);
    }

    public function test_qty_melebihi_sisa_ditolak_termasuk_setelah_retur_sebelumnya(): void
    {
        [$purchase, , , $itemA] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);

        $this->assertThrows(
            fn () => $service->create($purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 11]]),
            BusinessException::class
        );

        $service->confirm($service->create($purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 6]]));

        $this->assertThrows(
            fn () => $service->create($purchase->fresh(), 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 5]]),
            BusinessException::class
        );
    }

    public function test_item_milik_pembelian_lain_ditolak(): void
    {
        [$purchase] = $this->receivedPurchase('P1');
        [, , , $otherItem] = $this->receivedPurchase('P2');

        $this->assertThrows(
            fn () => app(PurchaseReturnService::class)->create(
                $purchase, 'damaged', [['purchase_item_id' => $otherItem->id, 'qty_returned' => 1]]
            ),
            BusinessException::class
        );
    }

    public function test_pembelian_draft_tidak_bisa_diretur(): void
    {
        $supplier = Supplier::create(['name' => 'S'])->fresh();
        $p = Product::create(['sku' => 'D-A', 'name' => 'A', 'cost_price' => 1, 'sell_price' => 2]);
        $purchase = app(PurchaseService::class)->create($supplier, [['product_id' => $p->id, 'qty' => 3, 'unit_cost' => 1000]]);
        $item = PurchaseItem::where('purchase_id', $purchase->id)->sole();

        $this->assertThrows(
            fn () => app(PurchaseReturnService::class)->create(
                $purchase, 'damaged', [['purchase_item_id' => $item->id, 'qty_returned' => 1]]
            ),
            BusinessException::class
        );
    }

    public function test_item_duplikat_dan_alasan_tidak_valid_ditolak(): void
    {
        [$purchase, , , $itemA] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);

        $this->assertThrows(
            fn () => $service->create($purchase, 'damaged', [
                ['purchase_item_id' => $itemA->id, 'qty_returned' => 1],
                ['purchase_item_id' => $itemA->id, 'qty_returned' => 1],
            ]),
            BusinessException::class
        );

        $this->assertThrows(
            fn () => $service->create($purchase, 'bebas', [['purchase_item_id' => $itemA->id, 'qty_returned' => 1]]),
            BusinessException::class
        );
    }

    public function test_confirm_dua_kali_ditolak(): void
    {
        [$purchase, $a, , $itemA] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);

        $return = $service->create($purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 4]]);
        $service->confirm($return);

        $this->assertThrows(fn () => $service->confirm($return), BusinessException::class);
        $this->assertSame(6, $a->fresh()->stock_current);
    }

    public function test_stok_tidak_cukup_membatalkan_seluruh_konfirmasi(): void
    {
        [$purchase, $a, , $itemA] = $this->receivedPurchase();
        $service = app(PurchaseReturnService::class);

        $return = $service->create($purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 4]]);

        // Seluruh stok A sudah terjual sebelum retur dikonfirmasi
        app(StockService::class)->recordOut($a, StockMovementType::SaleOut, 10);

        $this->assertThrows(fn () => $service->confirm($return), BusinessException::class);

        $this->assertSame('draft', $return->fresh()->status);
        $this->assertSame(0, $itemA->fresh()->qty_returned);
        $this->assertSame('received', $purchase->fresh()->status);
        $this->assertSame('65000.00', $purchase->payable->fresh()->amount_due);
        $this->assertSame(0, StockMovement::where('type', 'purchase_return_out')->count());
    }

    public function test_pengurang_utang_dibatasi_sebesar_yang_belum_dibayar(): void
    {
        [$purchase, , , $itemA] = $this->receivedPurchase();
        $purchase->payable->update(['amount_paid' => 50000]); // sisa utang 15.000
        $service = app(PurchaseReturnService::class);

        $return = $service->create($purchase, 'damaged', [['purchase_item_id' => $itemA->id, 'qty_returned' => 4]]); // 20.000
        $confirmed = $service->confirm($return);

        $payable = $purchase->payable->fresh();
        $this->assertSame('50000.00', $payable->amount_due);   // 65.000 - 15.000
        $this->assertSame('paid', $payable->status);
        $this->assertSame('confirmed', $confirmed->status);    // sisa klaim 5.000 ke supplier
    }

    public function test_pembelian_lunas_tanpa_utang_tetap_bisa_diretur(): void
    {
        [$purchase, $a, , $itemA] = $this->receivedPurchase('L', false);
        $service = app(PurchaseReturnService::class);

        $return = $service->create($purchase, 'wrong_item', [['purchase_item_id' => $itemA->id, 'qty_returned' => 4]]);
        $confirmed = $service->confirm($return);

        $this->assertNull($purchase->fresh()->payable);
        $this->assertSame('confirmed', $confirmed->status);
        $this->assertSame(6, $a->fresh()->stock_current);
    }
}
