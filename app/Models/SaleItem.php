<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id', 'product_id', 'qty', 'unit_price', 'cost_price_snapshot', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty'                 => 'integer',
            'unit_price'          => 'decimal:2',
            'cost_price_snapshot' => 'decimal:2',
            'line_total'          => 'decimal:2',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
