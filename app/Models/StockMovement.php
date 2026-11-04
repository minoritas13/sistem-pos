<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'product_id', 'type', 'qty', 'reference_type', 'reference_id',
        'stock_before', 'stock_after', 'note',
    ];

    protected function casts(): array
    {
        return [
            'qty'          => 'integer',
            'stock_before' => 'integer',
            'stock_after'  => 'integer',
            'created_at'   => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('stock_movements bersifat append-only.'));
        static::deleting(fn () => throw new LogicException('stock_movements tidak boleh dihapus.'));
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
