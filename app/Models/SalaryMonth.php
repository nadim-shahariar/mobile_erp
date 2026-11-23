<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalaryMonth extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['salary_amount' => 'decimal:2', 'finalized_at' => 'datetime'];
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }
}
