<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SerializedIdentifier extends Model
{
    public const TYPE_IMEI1 = 'IMEI1';

    public const TYPE_IMEI2 = 'IMEI2';

    public const TYPE_SERIAL = 'SERIAL';

    protected $fillable = [
        'serialized_unit_id',
        'identifier_type',
        'raw_value',
        'normalized_value',
    ];

    /** @return BelongsTo<SerializedUnit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(SerializedUnit::class, 'serialized_unit_id');
    }
}
