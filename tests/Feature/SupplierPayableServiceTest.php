<?php

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\SupplierPayable;
use App\Services\PurchaseReturnService;
use App\Services\PurchaseService;
use App\Services\SupplierPayableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPayableServiceTest extends TestCase
{
    use RefreshDatabase;

    private function payable(int $due = 65000, ?string $dueDate = null, string $status = 'unpaid'): SupplierPayable
    {
        $supplier = Supplier::create(['name' => 'S']);
        $purchase = Purchase::create([
            'purchase_no' => 'PO-'.uniqid(), 'supplier_id' => $supplier->id,
            'status' => 'received', 'total' => $due, 'received_at' => now(),
        ]);

        return SupplierPayable::create([
            'purchase_id' => $purchase->id, 'supplier_id' => $supplier->id,
            'amount_due' => $due,
            'due_date' => $dueDate ?? now()->addDays(14)->toDateString(),
            'status' => $status,
        ]);
    }

    public function test_bayar_sebagian_lalu_lunas(): void
    {
        $payable = $this->payable(65000);
        $service = app(SupplierPayableService::class);

        $after = $service->pay($payable, 25000);

        $this->assertSame('25000.00', $after->amount_paid);
        $this->assertSame('partial', $after->status);
        $this->assertSame('25000.00', $payable->purchase->fresh()->paid_amount);

        $after = $service->pay($payable, '40000.00');

        $this->assertSame('65000.00', $after->amount_paid);
        $this->assertSame('paid', $after->status);
        $this->assertSame('65000.00', $payable->purchase->fresh()->paid_amount);
    }

    public function test_bayar_melebihi_sisa_ditolak_tanpa_mengubah_data(): void
    {
        $payable = $this->payable(65000);
        app(SupplierPayableService::class)->pay($payable, 60000);

        $this->assertThrows(
            fn () => app(SupplierPayableService::class)->pay($payable, 5000.01),
            BusinessException::class
        );

        $this->assertSame('60000.00', $payable->fresh()->amount_paid);
        $this->assertSame('60000.00', $payable->purchase->fresh()->paid_amount);
    }

    public function test_utang_lunas_tidak_bisa_dibayar_lagi(): void
    {
        $payable = $this->payable(10000);
        $service = app(SupplierPayableService::class);
        $service->pay($payable, 10000);

        $this->assertThrows(fn () => $service->pay($payable, 1), BusinessException::class);
        $this->assertSame('10000.00', $payable->fresh()->amount_paid);
    }

    public function test_nominal_tidak_valid_ditolak(): void
    {
        $payable = $this->payable(10000);
        $service = app(SupplierPayableService::class);

        foreach ([0, '0', '-5', 'abc', '10.123', '', '1e3'] as $bad) {
            $this->assertThrows(fn () => $service->pay($payable, $bad), BusinessException::class);
        }

        $this->assertSame('0.00', $payable->fresh()->amount_paid);
    }

    public function test_utang_overdue_tetap_overdue_sampai_lunas(): void
    {
        $payable = $this->payable(65000, now()->subDay()->toDateString(), 'overdue');
        $service = app(SupplierPayableService::class);

        $this->assertSame('overdue', $service->pay($payable, 10000)->status);
        $this->assertSame('paid', $service->pay($payable, 55000)->status);
    }

    public function test_sisa_utang_setelah_retur_menjadi_batas_bayar(): void
    {
        $supplier = Supplier::create(['name' => 'S'])->fresh();
        $product = Product::create(['sku' => 'R-A', 'name' => 'A', 'cost_price' => 1, 'sell_price' => 99999]);

        $purchase = app(PurchaseService::class)->create($supplier, [
            ['product_id' => $product->id, 'qty' => 10, 'unit_cost' => 5000],
        ]);
        $purchase = app(PurchaseService::class)->receive($purchase, now()->addDays(14))->load('items');

        $returns = app(PurchaseReturnService::class);
        $returns->confirm($returns->create($purchase, 'damaged', [
            ['purchase_item_id' => $purchase->items->first()->id, 'qty_returned' => 4],
        ]));

        $payable = $purchase->payable->fresh();
        $this->assertSame('30000.00', $payable->amount_due); // 50.000 - 20.000

        $service = app(SupplierPayableService::class);
        $this->assertThrows(fn () => $service->pay($payable, '30000.01'), BusinessException::class);
        $this->assertSame('paid', $service->pay($payable, 30000)->status);
    }
}
