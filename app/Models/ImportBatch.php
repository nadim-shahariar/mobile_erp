<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $fillable = [
        'batch_no',
        'business_unit_id',
        'user_id',
        'original_filename',
        'stored_path',
        'status',
        'headers',
        'mapping',
        'total_rows',
        'valid_rows',
        'invalid_rows',
        'committed_rows',
        'error_report',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'mapping' => 'array',
            'error_report' => 'array',
            'committed_at' => 'datetime',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'committed_rows' => 'integer',
        ];
    }
}
