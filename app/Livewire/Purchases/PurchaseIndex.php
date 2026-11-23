<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Models\BusinessUnit;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Supplier;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PurchaseIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'unit')]
    public string $unitFilter = 'all';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Purchase::class);
        if (! request()->has('unit') && (int) Setting::get('units.default_id', 0) > 0) {
            $this->unitFilter = (string) app(UnitContext::class)->requireCurrent(auth()->user())->id;
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $user = auth()->user();
        $unitIds = $user->accessibleUnitIds();

        $query = Purchase::query()
            ->with(['supplier', 'businessUnit', 'lines'])
            ->whereIn('business_unit_id', $unitIds);

        if ($this->unitFilter !== 'all') {
            $query->where('business_unit_id', (int) $this->unitFilter);
        }

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if (trim($this->search) !== '') {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($this->search)).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('document_no', 'like', $term)
                    ->orWhere('reference', 'like', $term)
                    ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', $term));
            });
        }

        if ($this->from !== '') {
            $query->whereDate('purchase_date', '>=', $this->from);
        }

        if ($this->to !== '') {
            $query->whereDate('purchase_date', '<=', $this->to);
        }

        return view('livewire.purchases.index', [
            'purchases' => $query->latest('purchase_date')->latest('id')->paginate(25),
            'units' => BusinessUnit::query()->whereIn('id', $unitIds)->ordered()->get(),
            'currency' => config('erp.currency.symbol'),
            'canCreate' => $user->can('purchase.create'),
            'supplierCount' => Supplier::query()->count(),
        ]);
    }
}
