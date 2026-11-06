<?php

namespace Tests\Feature;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockServiceTest extends TestCase
{
    use RefreshDatabase;

    private function product(): Product
    {
        return Product::create(['sku' => 'SKU-'.uniqid(), 'name' => 'Barang', 'cost_price' => 1000, 'sell_price' => 2000]);
    }

    public function test_stok_masuk_menambah_dan_mencatat_movement(): void
    {
        $p = $this->product();

        $m = app(StockService::class)->recordIn($p, StockMovementType::AdjustmentIn, 10, null, 'saldo awal');

        $this->assertSame(10, $p->stock_current);          // objek pemanggil ikut ter-update
        $this->assertSame(10, $p->fresh()->stock_current);
        $this->assertSame(0, $m->stock_before);
        $this->assertSame(10, $m->stock_after);
        $this->assertSame(1, StockMovement::count());
    }

    public function test_stok_keluar_mengurangi_dan_menyimpan_referensi(): void
    {
        $p = $this->product();
        $supplier = Supplier::create(['name' => 'S']);
        $purchase = Purchase::create(['purchase_no' => 'PO-X', 'supplier_id' => $supplier->id]);
        $service = app(StockService::class);

        $service->recordIn($p, StockMovementType::PurchaseIn, 10, $purchase);
        $m = $service->recordOut($p, StockMovementType::AdjustmentOut, 4, null, 'rusak');

        $this->assertSame(6, $p->fresh()->stock_current);
        $this->assertSame(10, $m->stock_before);
        $this->assertSame(6, $m->stock_after);
        $this->assertSame('purchase', StockMovement::where('type', 'purchase_in')->first()->reference_type);
        $this->assertSame($purchase->id, StockMovement::where('type', 'purchase_in')->first()->reference_id);
    }

    public function test_stok_tidak_boleh_negatif_dan_tidak_meninggalkan_jejak(): void
    {
        $p = $this->product();
        app(StockService::class)->recordIn($p, StockMovementType::AdjustmentIn, 3);

        try {
            app(StockService::class)->recordOut($p, StockMovementType::SaleOut, 5);
            $this->fail('Seharusnya ditolak.');
        } catch (BusinessException) {
            // diharapkan
        }

        $this->assertSame(3, $p->fresh()->stock_current);
        $this->assertSame(1, StockMovement::count()); // hanya movement masuk awal
    }

    public function test_arah_jenis_yang_salah_ditolak(): void
    {
        $p = $this->product();

        $this->expectException(BusinessException::class);
        app(StockService::class)->recordIn($p, StockMovementType::SaleOut, 1);
    }

    public function test_qty_nol_ditolak(): void
    {
        $p = $this->product();

        $this->expectException(BusinessException::class);
        app(StockService::class)->recordIn($p, StockMovementType::PurchaseIn, 0);
    }
}
