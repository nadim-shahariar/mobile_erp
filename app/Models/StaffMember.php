<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A branch staff recipient, independent of ERP login accounts. */
class StaffMember extends Model
{
    protected $fillable = ['business_unit_id', 'name', 'normalized_name', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
