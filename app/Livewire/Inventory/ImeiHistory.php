<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Inventory\SerialIdentityService;
use App\Models\PurchaseLine;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class ImeiHistory extends Component
{
    #[Url(as: 'q')]
    public string $imei = '';

    public function mount(?string $imei = null): void
    {
        $this->imei = $imei ?? (string) request()->query('q', '');
    }

    public function search(): void
    {
        $validated = $this->validate([
            'imei' => ['required', 'string', 'max:64'],
        ]);

        $this->redirect(route('imei.history', $validated['imei']), navigate: true);
    }

    public function render(SerialIdentityService $identities): View
    {
        $unit = null;
        $notFound = false;

        if (trim($this->imei) !== '') {
            $unit = $identities->findByImei($this->imei);

            if ($unit === null) {
                $notFound = true;
            } else {
                $this->authorize('timeline', $unit);

                $unit->load([
                    'variant.product.brand',
                    'identifiers',
                    'cycles.source' => fn ($morph) => $morph->morphWith([
                        PurchaseLine::class => ['purchase.supplier'],
                    ]),
                    'events' => fn ($query) => $query->orderBy('id'),
                    'events.actor',
                    'events.fromUnit',
                    'events.toUnit',
                    'events.reference',
                ]);
            }
        }

        return view('livewire.inventory.imei-history', [
            'unit' => $unit,
            'notFound' => $notFound,
            'canSeeCost' => auth()->user()->can('inventory.cost.view'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
