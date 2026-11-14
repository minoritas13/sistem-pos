<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Purchase;
use App\Models\SupplierPayable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class SupplierPayableService
{
    /** Catat pembayaran utang supplier (penuh atau sebagian). */
    public function pay(SupplierPayable $payable, string|int|float $amount): SupplierPayable
    {
        $amount = (string) $amount;

        if (! preg_match('/^\d+(\.\d{1,2})?$/', $amount) || bccomp($amount, '0', 2) <= 0) {
            throw new BusinessException('Nominal pembayaran tidak valid.');
        }
        $amount = bcadd($amount, '0', 2);

        return DB::transaction(function () use ($payable, $amount) {
            $locked = SupplierPayable::whereKey($payable->id)->lockForUpdate()->firstOrFail();

            $outstanding = bcsub($locked->amount_due, $locked->amount_paid, 2);

            if (bccomp($outstanding, '0', 2) <= 0) {
                throw new BusinessException('Utang ini sudah lunas.');
            }
            if (bccomp($amount, $outstanding, 2) > 0) {
                throw new BusinessException("Nominal melebihi sisa utang ({$outstanding}).");
            }

            $newPaid = bcadd($locked->amount_paid, $amount, 2);

            $locked->update([
                'amount_paid' => $newPaid,
                'status'      => self::resolveStatus($locked->amount_due, $newPaid, $locked->due_date),
            ]);

            $purchase = Purchase::whereKey($locked->purchase_id)->lockForUpdate()->firstOrFail();
            $purchase->update(['paid_amount' => bcadd($purchase->paid_amount, $amount, 2)]);

            return $locked->refresh();
        });
    }

    public static function resolveStatus(string $amountDue, string $amountPaid, CarbonInterface $dueDate): string
    {
        return match (true) {
            bccomp($amountPaid, $amountDue, 2) >= 0 => 'paid',
            $dueDate->lt(today())                   => 'overdue',
            bccomp($amountPaid, '0', 2) > 0         => 'partial',
            default                                 => 'unpaid',
        };
    }
}
