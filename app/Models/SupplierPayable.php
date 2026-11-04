<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierPayable extends Model
{
    protected $fillable = [
        'purchase_id', 'supplier_id', 'amount_due', 'amount_paid', 'due_date', 'status',
    ];

    protected function casts(): array
    {
        return [
            'amount_due'  => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date'    => 'date',
        ];
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
