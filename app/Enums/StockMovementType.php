<?php

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseIn         = 'purchase_in';
    case AdjustmentIn       = 'adjustment_in';
    case SaleOut            = 'sale_out';
    case PurchaseReturnOut  = 'purchase_return_out';
    case AdjustmentOut      = 'adjustment_out';

    public function isIncoming(): bool
    {
        return match ($this) {
            self::PurchaseIn, self::AdjustmentIn => true,
            default => false,
        };
    }
}
