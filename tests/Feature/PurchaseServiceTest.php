<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $sku, string $category): Product
    {
        $cat = Category::firstOrCreate(['name' => $category]);

        return Product::create([
            'sku' => $sku, 'name' => "Produk {$sku}", 'category_id' => $cat->id,
            'cost_price' => 1, 'sell_price' => 99999,
        ]);
    }

    public function test_satu_supplier_satu_pembelian_banyak_kategori(): void
    {
        $supplier = Supplier::create(['name' => 'PT Serba Ada']);
        $a = $this->product('A', 'Minuman');
        $b = $this->product('B', 'Makanan');
        $c = $this->product('C', 'Kebersihan');

        $service  = app(PurchaseService::class);
        $purchase = $service->create($supplier, [
            ['product_id' => $a->id, 'qty' => 10, 'unit_cost' => 5000],
            ['product_id' => $b->id, 'qty' => 5,  'unit_cost' => 3000],
            ['product_id' => $c->id, 'qty' => 2,  'unit_cost' => 10000],
        ]);

        $this->assertSame('draft', $purchase->status);
        $this->assertCount(3, $purchase->items);
        $this->assertSame('85000.00', $purchase->total);
        $this->assertSame(0, $a->fresh()->stock_current); // draft belum mengubah stok

        $received = $service->receive($purchase, now()->addDays(14));

        $this->assertSame('received', $received->status);
        $this->assertNotNull($received->received_at);
        $this->assertSame(10, $a->fresh()->stock_current);
        $this->assertSame(5, $b->fresh()->stock_current);
        $this->assertSame(2, $c->fresh()->stock_current);
        $this->assertSame(3, StockMovement::where('type', 'purchase_in')->count());
        $this->assertSame('5000.00', $a->fresh()->cost_price); // harga modal terakhir

        $this->assertNotNull($received->payable);
        $this->assertSame('85000.00', $received->payable->amount_due);
        $this->assertSame('unpaid', $received->payable->status);
        $this->assertSame('0.00', $received->paid_amount);
    }

    public function test_terima_tanpa_tempo_dianggap_lunas_tanpa_utang(): void
    {
        $supplier = Supplier::create(['name' => 'S']);
        $a = $this->product('A', 'Minuman');

        $service  = app(PurchaseService::class);
        $purchase = $service->create($supplier, [['product_id' => $a->id, 'qty' => 2, 'unit_cost' => 4000]]);
        $received = $service->receive($purchase);

        $this->assertNull($received->payable);
        $this->assertSame('8000.00', $received->paid_amount);
    }

    public function test_pembelian_tidak_bisa_diterima_dua_kali(): void
    {
        $supplier = Supplier::create(['name' => 'S']);
        $a = $this->product('A', 'Minuman');

        $service  = app(PurchaseService::class);
        $purchase = $service->create($supplier, [['product_id' => $a->id, 'qty' => 2, 'unit_cost' => 4000]]);
        $service->receive($purchase);

        try {
            $service->receive($purchase);
            $this->fail('Seharusnya ditolak.');
        } catch (BusinessException) {
            // diharapkan
        }

        $this->assertSame(2, $a->fresh()->stock_current); // stok tidak bertambah dua kali
    }

    public function test_produk_duplikat_dalam_satu_pembelian_ditolak(): void
    {
        $supplier = Supplier::create(['name' => 'S']);
        $a = $this->product('A', 'Minuman');

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->create($supplier, [
            ['product_id' => $a->id, 'qty' => 1, 'unit_cost' => 1000],
            ['product_id' => $a->id, 'qty' => 1, 'unit_cost' => 1000],
        ]);
    }

    public function test_supplier_nonaktif_ditolak(): void
    {
        $supplier = Supplier::create(['name' => 'S', 'is_active' => false]);
        $a = $this->product('A', 'Minuman');

        $this->expectException(BusinessException::class);
        app(PurchaseService::class)->create($supplier, [['product_id' => $a->id, 'qty' => 1, 'unit_cost' => 1000]]);
    }

    public function test_jatuh_tempo_masa_lalu_ditolak_dan_stok_tidak_berubah(): void
    {
        $supplier = Supplier::create(['name' => 'S']);
        $a = $this->product('A', 'Minuman');

        $service  = app(PurchaseService::class);
        $purchase = $service->create($supplier, [['product_id' => $a->id, 'qty' => 2, 'unit_cost' => 4000]]);

        try {
            $service->receive($purchase, now()->subDay());
            $this->fail('Seharusnya ditolak.');
        } catch (BusinessException) {
            // diharapkan
        }

        $this->assertSame(0, $a->fresh()->stock_current);
        $this->assertSame('draft', $purchase->fresh()->status);
    }
}
