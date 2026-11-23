<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Domain\Reporting\InventoryMetrics;
use App\Domain\Reporting\SalesMetrics;
use App\Models\BusinessUnit;
use App\Models\Setting;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class Dashboard extends Component
{
    #[Url(as: 'unit')]
    public string $unitFilter = 'all';

    public function mount(): void
    {
        if (! request()->has('unit') && (int) Setting::get('units.default_id', 0) > 0) {
            $this->unitFilter = (string) app(UnitContext::class)->requireCurrent(auth()->user())->id;
        }
    }

    public function render(InventoryMetrics $metrics, SalesMetrics $salesMetrics): View
    {
        $user = auth()->user();
        $unitId = $this->unitFilter === 'all' ? null : (int) $this->unitFilter;

        $summary = $metrics->summary($user, $unitId);
        $salesSummary = $salesMetrics->summary($user, $unitId);
        $recent = $metrics->recentEvents($user, $unitId, 8);
        $lowStock = $metrics->lowStockVariants($user->accessibleUnitIds(), 5);

        return view('livewire.dashboard', [
            'summary' => $summary,
            'salesSummary' => $salesSummary,
            'recent' => $recent,
            'lowStock' => $lowStock,
            'canSeeCost' => $user->can('inventory.cost.view'),
            'units' => BusinessUnit::query()->whereIn('id', $user->accessibleUnitIds())->ordered()->get(),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
