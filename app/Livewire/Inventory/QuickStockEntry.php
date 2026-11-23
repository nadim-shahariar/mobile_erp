<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Inventory\ManualIntakeService;
use App\Domain\Inventory\ProductIntakeService;
use App\Models\BusinessUnit;
use App\Support\UnitContext;
use Livewire\Attributes\Locked;

/** Reuses inventory intake inside POS without synchronizing fields to the page URL. */
class QuickStockEntry extends PhoneIntake
{
    public string $unitId = '';

    public string $mode = 'phone';

    #[Locked]
    public bool $embedded = true;

    public function mount(UnitContext $unitContext, string $entryMode = 'phone', string $receivingUnit = ''): void
    {
        parent::mount($unitContext);
        abort_unless(in_array($entryMode, ['phone', 'product'], true), 422);
        abort_unless(auth()->user()->hasUnitAccess((int) $receivingUnit), 403);
        abort_unless(BusinessUnit::active()->whereKey((int) $receivingUnit)->exists(), 422);
        $this->mode = $entryMode;
        $this->unitId = $receivingUnit;
        foreach (['phoneRows', 'productRows'] as $property) {
            foreach ($this->{$property} as $key => $row) {
                $this->{$property}[$key]['unitId'] = $receivingUnit;
            }
        }
    }

    public function saveRows(ManualIntakeService $intake, ProductIntakeService $products): void
    {
        parent::saveRows($intake, $products);
        $this->dispatch('stock-entry-saved');
    }
}
