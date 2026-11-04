<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Receivable extends Model
{
    protected $fillable = [
        'sale_id', 'customer_id', 'amount_due', 'amount_paid',
        'due_date', 'status', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_due'  => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'due_date'    => 'date',
            'paid_at'     => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
