<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Adapter / cable / other item bundled with a handset at a recorded price.
 * These prices are informational bundle values from the shop sheet; they do
 * not silently change the handset's acquisition cost basis.
 */
class SerializedUnitAccessory extends Model
{
    public const TYPE_ADAPTER = 'ADAPTER';

    public const TYPE_CABLE = 'CABLE';

    public const TYPE_OTHER = 'OTHER';

    protected $fillable = [
        'serialized_unit_id',
        'inventory_cycle_id',
        'accessory_type',
        'name',
        'price',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<SerializedUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }

    /** @return BelongsTo<InventoryCycle, $this> */
    public function cycle(): BelongsTo
    {
        return $this->belongsTo(InventoryCycle::class, 'inventory_cycle_id');
    }
}
