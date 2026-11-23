<?php

declare(strict_types=1);

namespace App\Livewire\Transfers;

use App\Models\BusinessUnit;
use App\Models\StockTransfer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class TransferIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    public function mount(): void
    {
        $this->authorize('viewAny', StockTransfer::class);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $user = auth()->user();
        $unitIds = $user->accessibleUnitIds();

        $query = StockTransfer::query()
            ->with(['fromUnit', 'toUnit', 'requester', 'lines'])
            ->visibleTo($unitIds);

        if ($this->status !== 'all') {
            $query->where('status', $this->status);
        }

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('transfer_no', 'like', $term)
                    ->orWhereHas('lines.serializedUnit', fn ($unit) => $unit
                        ->where('primary_imei', 'like', $term)
                        ->orWhere('secondary_imei', 'like', $term));
            });
        }

        return view('livewire.transfers.index', [
            'transfers' => $query->latest('id')->paginate(25),
            'currency' => config('erp.currency.symbol'),
            'canCreate' => $user->can('stock.transfer.create'),
            'unitCount' => BusinessUnit::query()->whereIn('id', $unitIds)->count(),
        ]);
    }
}
