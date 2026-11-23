<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseLot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['order_date' => 'date'];
    }

    public function lines()
    {
        return $this->hasMany(PurchaseLotLine::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }
}
