<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'product_id', 'qty', 'unit_cost', 'line_total', 'qty_returned',
    ];

    protected function casts(): array
    {
        return [
            'qty'          => 'integer',
            'qty_returned' => 'integer',
            'unit_cost'    => 'decimal:2',
            'line_total'   => 'decimal:2',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    /** Sisa qty yang masih boleh diretur ke supplier. */
    public function returnableQty(): int
    {
        return $this->qty - $this->qty_returned;
    }
}
