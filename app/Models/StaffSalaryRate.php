<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffSalaryRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['monthly_salary' => 'decimal:2'];
    }
}
