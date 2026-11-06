<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\StockService;
use App\Support\DocumentNumber;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    /**
     * Buat pembelian berstatus draft. Satu pembelian boleh berisi produk dari kategori apa pun.
     *
     * @param array<int, array{product_id:int, qty:int, unit_cost:int|float|string}> $items
     */
    public function create(Supplier $supplier, array $items): Purchase
    {
        if (! $supplier->is_active) {
            throw new BusinessException('Supplier nonaktif.');
        }
        if ($items === []) {
            throw new BusinessException('Pembelian harus memiliki minimal satu barang.');
        }

        $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id);

        if ($productIds->count() !== $productIds->unique()->count()) {
            throw new BusinessException('Produk yang sama tidak boleh muncul dua kali dalam satu pembelian.');
        }

        return DB::transaction(function () use ($supplier, $items, $productIds) {
            $found = Product::whereIn('id', $productIds)->pluck('id');

            if ($found->count() !== $productIds->count()) {
                throw new BusinessException('Ada produk yang tidak ditemukan.');
            }

            $purchase = Purchase::create([
                'purchase_no' => DocumentNumber::make('PO'),
                'supplier_id' => $supplier->id,
                'status'      => 'draft',
                'total'       => 0,
            ]);

            $total = '0.00';

            foreach ($items as $item) {
                $qty      = (int) $item['qty'];
                $unitCost = (string) $item['unit_cost'];

                if ($qty < 1) {
                    throw new BusinessException('Qty harus lebih dari nol.');
                }
                if (! is_numeric($unitCost) || bccomp($unitCost, '0', 2) < 0) {
                    throw new BusinessException('Harga beli tidak valid.');
                }

                $lineTotal = bcmul($unitCost, (string) $qty, 2);
                $total     = bcadd($total, $lineTotal, 2);

                $purchase->items()->create([
                    'product_id' => (int) $item['product_id'],
                    'qty'        => $qty,
                    'unit_cost'  => $unitCost,
                    'line_total' => $lineTotal,
                ]);
            }

            $purchase->update(['total' => $total]);

            return $purchase->load('items');
        });
    }

    /**
     * Aksi "Terima Barang".
     * $dueDate diisi  -> pembelian tempo: dibuat baris supplier_payables.
     * $dueDate null   -> dibayar lunas saat terima: paid_amount = total, tanpa utang.
     */
    public function receive(Purchase $purchase, ?CarbonInterface $dueDate = null): Purchase
    {
        if ($dueDate !== null && $dueDate->lt(today())) {
            throw new BusinessException('Tanggal jatuh tempo tidak boleh di masa lalu.');
        }

        return DB::transaction(function () use ($purchase, $dueDate) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->with('items.product')->firstOrFail();

            if ($locked->status !== 'draft') {
                throw new BusinessException('Hanya pembelian berstatus draft yang bisa diterima.');
            }
            if ($locked->items->isEmpty()) {
                throw new BusinessException('Pembelian tidak memiliki barang.');
            }

            // Urutan product_id tetap agar dua penerimaan paralel tidak saling mengunci (deadlock)
            foreach ($locked->items->sortBy('product_id') as $item) {
                $this->stock->recordIn(
                    $item->product,
                    StockMovementType::PurchaseIn,
                    $item->qty,
                    $locked,
                    "Penerimaan {$locked->purchase_no}",
                );

                // cost_price = harga modal terakhir (sesuai brief)
                $item->product->update(['cost_price' => $item->unit_cost]);
            }

            if ($dueDate !== null) {
                $locked->payable()->create([
                    'supplier_id' => $locked->supplier_id,
                    'amount_due'  => $locked->total,
                    'due_date'    => $dueDate->toDateString(),
                    'status'      => 'unpaid',
                ]);
                $paid = '0.00';
            } else {
                $paid = $locked->total;
            }

            $locked->update([
                'status'      => 'received',
                'received_at' => now(),
                'paid_amount' => $paid,
            ]);

            return $locked->refresh();
        });
    }
}
