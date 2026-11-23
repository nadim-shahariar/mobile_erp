<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use App\Enums\Custody;
use App\Enums\Sellability;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\PurchaseLine;
use App\Models\SerializedUnit;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Server-side inventory grid query. Filtering, sorting and pagination happen in
 * SQL so 10k+ handsets never enter the browser DOM, and the unit scope is always
 * derived from the authenticated user's pivot — never from request input.
 */
final class InventoryGridQuery
{
    public const SORTABLE = [
        'recent' => 'serialized_units.updated_at',
        'imei' => 'serialized_units.primary_imei',
        'condition' => 'serialized_units.condition_grade',
        'battery' => 'serialized_units.battery_health',
    ];

    /**
     * @param  array{unit_id?: int|null, search?: ?string, custody?: ?string, sellability?: ?string, brand_id?: ?int, condition?: ?string, sort?: ?string, direction?: ?string}  $filters
     * @return Builder<SerializedUnit>
     */
    public function build(User $user, array $filters): Builder
    {
        $unitIds = $user->accessibleUnitIds();

        if ($unitIds === []) {
            throw UnitAccessDeniedException::forUnit(null);
        }

        $unitId = isset($filters['unit_id']) && $filters['unit_id'] !== null
            ? (int) $filters['unit_id']
            : null;

        if ($unitId !== null && ! in_array($unitId, $unitIds, true)) {
            throw UnitAccessDeniedException::forUnit($unitId);
        }

        $query = SerializedUnit::query()
            ->with([
                'variant.product.brand',
                'currentUnit',
                'accessories',
                'activeCycle.source' => fn (MorphTo $morph) => $morph->morphWith([
                    PurchaseLine::class => ['purchase.supplier'],
                ]),
                'lastEvent',
            ]);

        if ($unitId !== null) {
            $query->where('current_unit_id', $unitId);
        } else {
            $query->where(function (Builder $scope) use ($unitIds): void {
                $scope->whereIn('current_unit_id', $unitIds)
                    ->orWhere(function (Builder $custodyless) use ($unitIds): void {
                        $custodyless->whereNull('current_unit_id')
                            ->whereHas('lastEvent', fn (Builder $event) => $event->whereIn('from_unit_id', $unitIds));
                    });
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

            $query->where(function (Builder $term) use ($like): void {
                $term->where('primary_imei', 'like', $like)
                    ->orWhere('secondary_imei', 'like', $like)
                    ->orWhereHas('identifiers', fn (Builder $identifier) => $identifier->where('normalized_value', 'like', $like))
                    ->orWhereHas('variant', fn (Builder $variant) => $variant->where('sku', 'like', $like))
                    ->orWhereHas('variant.product', fn (Builder $product) => $product->where('name', 'like', $like)->orWhere('model', 'like', $like))
                    ->orWhereHas('variant.product.brand', fn (Builder $brand) => $brand->where('name', 'like', $like));
            });
        }

        if (($custody = $filters['custody'] ?? null) !== null && $custody !== '' && $custody !== 'all') {
            $query->where('current_custody', $custody);
        }

        if (($sellability = $filters['sellability'] ?? null) !== null && $sellability !== '' && $sellability !== 'all') {
            $query->where('sellability_status', $sellability);
        }

        if (($brandId = $filters['brand_id'] ?? null) !== null && $brandId !== '' && $brandId !== 'all') {
            $query->whereHas('variant.product', fn (Builder $product) => $product->where('brand_id', (int) $brandId));
        }

        if (($condition = $filters['condition'] ?? null) !== null && $condition !== '' && $condition !== 'all') {
            $query->where('condition_grade', $condition);
        }

        $sortKey = (string) ($filters['sort'] ?? 'recent');
        $direction = strtolower((string) ($filters['direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query->orderBy(self::SORTABLE[$sortKey] ?? self::SORTABLE['recent'], $direction)
            ->orderBy('serialized_units.id', $direction);

        return $query;
    }

    /** @return array<string, int> */
    public function countsByCustody(User $user, ?int $unitId = null): array
    {
        $base = SerializedUnit::query();
        $unitIds = $user->accessibleUnitIds();

        if ($unitId !== null) {
            if (! in_array($unitId, $unitIds, true)) {
                throw UnitAccessDeniedException::forUnit($unitId);
            }

            $base->where('current_unit_id', $unitId);
        } else {
            $base->where(function (Builder $scope) use ($unitIds): void {
                $scope->whereIn('current_unit_id', $unitIds)
                    ->orWhere(function (Builder $custodyless) use ($unitIds): void {
                        $custodyless->whereNull('current_unit_id')
                            ->whereHas('lastEvent', fn (Builder $event) => $event->whereIn('from_unit_id', $unitIds));
                    });
            });
        }

        $counts = (clone $base)
            ->selectRaw('current_custody, count(*) as total')
            ->groupBy('current_custody')
            ->pluck('total', 'current_custody')
            ->all();

        $result = [];

        foreach (Custody::cases() as $custody) {
            $result[$custody->value] = (int) ($counts[$custody->value] ?? 0);
        }

        $result['sellable'] = (int) (clone $base)
            ->where('current_custody', Custody::InUnit->value)
            ->where('sellability_status', Sellability::Available->value)
            ->count();

        return $result;
    }
}
