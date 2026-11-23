<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PhoneRepair extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['cost' => 'decimal:2', 'sent_at' => 'datetime', 'received_at' => 'datetime'];
    }

    public function phone()
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    public function businessUnit()
    {
        return $this->belongsTo(BusinessUnit::class);
    }
}
