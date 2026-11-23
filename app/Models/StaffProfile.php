<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffProfile extends Model
{
    protected $fillable = [
        'user_id',
        'code',
        'designation',
        'joined_at',
        'left_at',
        'monthly_salary',
        'salary_payment_method',
        'salary_config',
        'emergency_contact_name',
        'emergency_contact_phone',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'left_at' => 'date',
            'monthly_salary' => 'decimal:2',
            'salary_config' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
