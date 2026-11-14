<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseReturn;
use App\Models\SupplierPayable;
use App\Support\DocumentNumber;
use Illuminate\Support\Facades\DB;

class PurchaseReturnService
{
    private const REASONS = ['damaged', 'wrong_item', 'expired', 'other'];
    private const RETURNABLE_STATUSES = ['received', 'partial_return'];

    public function __construct(private StockService $stock) {}

    /**
     * Simpan retur berstatus draft. Stok dan utang belum berubah.
     *
     * @param array<int, array{purchase_item_id:int, qty_returned:int}> $items
     */
    public function create(Purchase $purchase, string $reason, array $items): PurchaseReturn
    {
        if (! in_array($reason, self::REASONS, true)) {
            throw new BusinessException('Alasan retur tidak valid.');
        }
        if ($items === []) {
            throw new BusinessException('Retur harus memiliki minimal satu barang.');
        }

        $itemIds = collect($items)->pluck('purchase_item_id')->map(fn ($id) => (int) $id);

        if ($itemIds->count() !== $itemIds->unique()->count()) {
            throw new BusinessException('Item pembelian yang sama tidak boleh muncul dua kali dalam satu retur.');
        }

        return DB::transaction(function () use ($purchase, $reason, $items, $itemIds) {
            $locked = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, self::RETURNABLE_STATUSES, true)) {
                throw new BusinessException('Retur hanya untuk pembelian berstatus received atau partial_return.');
            }

            $purchaseItems = PurchaseItem::where('purchase_id', $locked->id)
                ->whereIn('id', $itemIds)
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            if ($purchaseItems->count() !== $itemIds->count()) {
                throw new BusinessException('Ada item yang bukan bagian dari pembelian ini.');
            }

            $return = PurchaseReturn::create([
                'return_no'    => DocumentNumber::make('RT'),
                'purchase_id'  => $locked->id,
                'supplier_id'  => $locked->supplier_id,
                'reason'       => $reason,
                'total_refund' => 0,
                'status'       => 'draft',
                'returned_at'  => now(),
            ]);

            $total = '0.00';

            foreach ($items as $row) {
                $purchaseItem = $purchaseItems[(int) $row['purchase_item_id']];
                $qty          = (int) $row['qty_returned'];

                if ($qty < 1) {
                    throw new BusinessException('Qty retur harus lebih dari nol.');
                }
                if ($qty > $purchaseItem->returnableQty()) {
                    throw new BusinessException(
                        "Qty retur melebihi sisa yang bisa diretur ({$purchaseItem->returnableQty()})."
                    );
                }

                $lineRefund = bcmul($purchaseItem->unit_cost, (string) $qty, 2);
                $total      = bcadd($total, $lineRefund, 2);

                $return->items()->create([
                    'purchase_item_id' => $purchaseItem->id,
                    'product_id'       => $purchaseItem->product_id,
                    'qty_returned'     => $qty,
                    'unit_cost'        => $purchaseItem->unit_cost,
                    'line_refund'      => $lineRefund,
                ]);
            }

            $return->update(['total_refund' => $total]);

            return $return->load('items');
        });
    }

    /**
     * Aksi "Konfirmasi Retur": stok keluar, qty_returned bertambah,
     * status pembelian diperbarui, utang supplier dikurangi.
     */
    public function confirm(PurchaseReturn $purchaseReturn): PurchaseReturn
    {
        return DB::transaction(function () use ($purchaseReturn) {
            $return = PurchaseReturn::whereKey($purchaseReturn->id)
                ->lockForUpdate()->with('items')->firstOrFail();

            if ($return->status !== 'draft') {
                throw new BusinessException('Hanya retur berstatus draft yang bisa dikonfirmasi.');
            }

            $purchase = Purchase::whereKey($return->purchase_id)->lockForUpdate()->firstOrFail();

            if (! in_array($purchase->status, self::RETURNABLE_STATUSES, true)) {
                throw new BusinessException('Status pembelian tidak mengizinkan retur.');
            }

            $purchaseItems = PurchaseItem::where('purchase_id', $purchase->id)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            // Urutan product_id tetap agar konfirmasi paralel tidak saling mengunci (deadlock)
            foreach ($return->items->sortBy('product_id') as $line) {
                $purchaseItem = $purchaseItems[$line->purchase_item_id];

                if ($line->qty_returned > $purchaseItem->returnableQty()) {
                    throw new BusinessException(
                        "Qty retur melebihi sisa yang bisa diretur ({$purchaseItem->returnableQty()})."
                    );
                }

                // Jika stok tidak cukup (barang sudah terjual), seluruh konfirmasi dibatalkan
                $this->stock->recordOut(
                    $line->product,
                    StockMovementType::PurchaseReturnOut,
                    $line->qty_returned,
                    $return,
                    "Retur {$return->return_no}",
                );

                $purchaseItem->increment('qty_returned', $line->qty_returned);
            }

            $allReturned = $purchaseItems->every(fn ($i) => $i->qty_returned >= $i->qty);
            $purchase->update(['status' => $allReturned ? 'closed' : 'partial_return']);

            $offset = $this->offsetPayable($purchase, $return->total_refund);

            $return->update([
                'status'      => bccomp($offset, $return->total_refund, 2) === 0 ? 'settled' : 'confirmed',
                'returned_at' => now(),
            ]);

            return $return->refresh();
        });
    }

    /** Kurangi amount_due sebesar refund, maksimal sebesar utang yang belum dibayar. */
    private function offsetPayable(Purchase $purchase, string $totalRefund): string
    {
        $payable = SupplierPayable::where('purchase_id', $purchase->id)->lockForUpdate()->first();

        if (! $payable) {
            return '0.00';
        }

        $unpaid = bcsub($payable->amount_due, $payable->amount_paid, 2);
        if (bccomp($unpaid, '0', 2) < 0) {
            $unpaid = '0.00';
        }

        $offset = bccomp($totalRefund, $unpaid, 2) <= 0 ? $totalRefund : $unpaid;
        $newDue = bcsub($payable->amount_due, $offset, 2);

        $status = match (true) {
            bccomp($newDue, $payable->amount_paid, 2) <= 0   => 'paid',
            $payable->status === 'overdue'                   => 'overdue',
            bccomp($payable->amount_paid, '0', 2) > 0        => 'partial',
            default                                          => 'unpaid',
        };

        $payable->update(['amount_due' => $newDue, 'status' => $status]);

        return $offset;
    }
}
