<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class StockService
{
    /** Nilai reference_type sesuai brief: purchase, sale, purchase_return. */
    private const REFERENCE_TYPES = [
        Purchase::class       => 'purchase',
        Sale::class           => 'sale',
        PurchaseReturn::class => 'purchase_return',
    ];

    /** Untuk purchase_in dan adjustment_in. */
    public function recordIn(Product $product, StockMovementType $type, int $qty, ?Model $ref = null, ?string $note = null): StockMovement
    {
        if (! $type->isIncoming()) {
            throw new BusinessException("Jenis {$type->value} adalah stok keluar, gunakan recordOut().");
        }

        return $this->apply($product, $type, $qty, $ref, $note);
    }

    /** Untuk sale_out, purchase_return_out, dan adjustment_out. */
    public function recordOut(Product $product, StockMovementType $type, int $qty, ?Model $ref = null, ?string $note = null): StockMovement
    {
        if ($type->isIncoming()) {
            throw new BusinessException("Jenis {$type->value} adalah stok masuk, gunakan recordIn().");
        }

        return $this->apply($product, $type, $qty, $ref, $note);
    }

    private function apply(Product $product, StockMovementType $type, int $qty, ?Model $ref, ?string $note): StockMovement
    {
        if ($qty < 1) {
            throw new BusinessException('Jumlah stok harus lebih dari nol.');
        }

        $referenceType = null;
        if ($ref !== null) {
            $referenceType = self::REFERENCE_TYPES[$ref::class]
                ?? throw new BusinessException('Tipe referensi stok tidak dikenal: '.$ref::class);
        }

        return DB::transaction(function () use ($product, $type, $qty, $ref, $referenceType, $note) {
            // Baca saldo terbaru di bawah kunci baris, bukan dari objek yang mungkin sudah basi
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $before = $locked->stock_current;
            $after  = $type->isIncoming() ? $before + $qty : $before - $qty;

            if ($after < 0) {
                throw new BusinessException(
                    "Stok {$locked->name} tidak mencukupi (tersisa {$before}, diminta {$qty})."
                );
            }

            // stock_current tidak ada di $fillable, jadi hanya bisa diubah lewat forceFill di sini
            $locked->forceFill(['stock_current' => $after])->save();

            $movement = StockMovement::create([
                'product_id'     => $locked->id,
                'type'           => $type->value,
                'qty'            => $qty,
                'reference_type' => $referenceType,
                'reference_id'   => $ref?->getKey(),
                'stock_before'   => $before,
                'stock_after'    => $after,
                'note'           => $note,
            ]);

            $product->refresh(); // objek milik pemanggil ikut menampilkan saldo terbaru

            return $movement;
        });
    }
}
