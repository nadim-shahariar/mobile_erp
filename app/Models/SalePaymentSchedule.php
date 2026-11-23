<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalePaymentSchedule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['due_date' => 'date', 'amount' => 'decimal:2', 'paid_amount' => 'decimal:2'];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function remaining(): Money
    {
        return Money::of($this->amount)->sub($this->paid_amount);
    }
}
