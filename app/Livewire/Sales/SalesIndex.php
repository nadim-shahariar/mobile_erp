<?php

declare(strict_types=1);

namespace App\Livewire\Sales;

use App\Models\BusinessUnit;
use App\Models\Sale;
use App\Models\Setting;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SalesIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'unit')]
    public string $unitFilter = 'all';

    #[Url]
    public string $from = '';

    #[Url]
    public string $to = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Sale::class);
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

        $query = Sale::query()
            ->with(['customer', 'businessUnit'])
            ->withCount('items')
            ->whereIn('business_unit_id', $unitIds);

        if ($this->unitFilter !== 'all') {
            $query->where('business_unit_id', (int) $this->unitFilter);
        }

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('invoice_no', 'like', $term)
                    ->orWhereHas('customer', fn ($customer) => $customer->where('name', 'like', $term)
                        ->orWhere('business_name', 'like', $term)
                        ->orWhere('phone', 'like', $term));
            });
        }

        if ($this->from !== '') {
            $query->whereDate('posted_at', '>=', $this->from);
        }

        if ($this->to !== '') {
            $query->whereDate('posted_at', '<=', $this->to);
        }

        return view('livewire.sales.index', [
            'sales' => $query->latest('posted_at')->latest('id')->paginate(25),
            'units' => BusinessUnit::query()->whereIn('id', $unitIds)->ordered()->get(),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
