<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Setting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'group',
        'is_encrypted',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'array',
            'is_encrypted' => 'boolean',
        ];
    }

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$memo === null) {
            self::$memo = DB::table('settings')->pluck('value', 'key')
                ->map(fn ($value) => json_decode((string) $value, true))
                ->all();
        }

        return self::$memo[$key] ?? $default;
    }

    public static function put(string $key, mixed $value, string $group = 'general', ?int $userId = null): self
    {
        $setting = static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'updated_by' => $userId],
        );

        self::$memo = null;

        return $setting;
    }

    public static function flushMemo(): void
    {
        self::$memo = null;
    }
}
