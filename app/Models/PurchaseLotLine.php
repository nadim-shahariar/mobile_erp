<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseLotLine extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'unit_cost' => 'decimal:2'];
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function purchase()
    {
        return $this->belongsTo(Purchase::class);
    }
}
