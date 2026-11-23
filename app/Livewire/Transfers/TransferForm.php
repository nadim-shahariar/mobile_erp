<?php

declare(strict_types=1);

namespace App\Livewire\Transfers;

use App\Domain\Transfers\StockTransferService;
use App\Enums\Custody;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Models\AccessoryStockBalance;
use App\Models\BusinessUnit;
use App\Models\SerializedUnit;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferForm extends Component
{
    #[Url(as: 'unit')]
    public string $fromUnitId = '';

    public string $toUnitId = '';

    public string $phoneSearch = '';

    /** @var list<int> */
    public array $selectedUnitIds = [];

    /** @var list<array{product_variant_id: string, quantity: int|string}> */
    public array $accessoryLines = [];

    public string $notes = '';

    public bool $dispatchNow = false;

    public function mount(UnitContext $unitContext): void
    {
        $this->authorize('stock.transfer.create');

        $current = $unitContext->current(auth()->user());
        $this->fromUnitId = (string) ($current?->getKey() ?? '');

        if (request()->filled('phone')) {
            $unit = SerializedUnit::query()->find((int) request()->query('phone'));

            if ($unit !== null && (int) $unit->current_unit_id === (int) $this->fromUnitId) {
                $this->selectedUnitIds = [$unit->getKey()];
            }
        }
    }

    public function addPhone(int $unitId): void
    {
        $this->authorize('stock.transfer.create');

        if (in_array($unitId, $this->selectedUnitIds, true)) {
            return;
        }

        $serialized = SerializedUnit::query()->findOrFail($unitId);

        if ((int) $serialized->current_unit_id !== (int) $this->fromUnitId) {
            $this->addError('phoneSearch', 'That handset is not in the source unit.');

            return;
        }

        if ($serialized->current_custody !== Custody::InUnit || $serialized->sellability_status !== Sellability::Available) {
            $this->addError('phoneSearch', 'Only available handsets in the source unit can be transferred.');

            return;
        }

        $this->selectedUnitIds[] = $serialized->getKey();
        $this->phoneSearch = '';
        $this->resetErrorBag('phoneSearch');
    }

    public function addPhoneByImei(): void
    {
        $this->authorize('stock.transfer.create');

        $term = trim($this->phoneSearch);

        if ($term === '') {
            return;
        }

        $match = SerializedUnit::query()
            ->where(function ($query) use ($term): void {
                $query->where('primary_imei', 'like', "%{$term}%")
                    ->orWhere('secondary_imei', 'like', "%{$term}%");
            })
            ->where('current_unit_id', (int) $this->fromUnitId)
            ->get()
            ->first(fn (SerializedUnit $unit) => $unit->isSellable());

        if ($match === null) {
            $this->addError('phoneSearch', 'No available handset in the source unit matches that IMEI.');

            return;
        }

        $this->addPhone($match->getKey());
    }

    public function removePhone(int $unitId): void
    {
        $this->selectedUnitIds = array_values(array_filter($this->selectedUnitIds, fn (int $id) => $id !== $unitId));
    }

    public function addAccessoryLine(): void
    {
        $this->accessoryLines[] = ['product_variant_id' => '', 'quantity' => 1];
    }

    public function removeAccessoryLine(int $index): void
    {
        unset($this->accessoryLines[$index]);
        $this->accessoryLines = array_values($this->accessoryLines);
    }

    public function save(StockTransferService $transfers): void
    {
        $this->authorize('stock.transfer.create');

        $user = auth()->user();
        $accessible = $user->accessibleUnitIds();

        $validated = $this->validate([
            'fromUnitId' => ['required', 'integer', Rule::in($accessible)],
            'toUnitId' => ['required', 'integer', Rule::in($accessible), 'different:fromUnitId'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'selectedUnitIds' => ['array'],
            'selectedUnitIds.*' => ['integer', 'exists:serialized_units,id'],
            'accessoryLines' => ['array'],
            'accessoryLines.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'accessoryLines.*.quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        $accessories = [];

        foreach ($validated['accessoryLines'] as $index => $line) {
            if (empty($line['product_variant_id'])) {
                continue;
            }

            $accessories[] = [
                'product_variant_id' => (int) $line['product_variant_id'],
                'quantity' => (int) $line['quantity'],
            ];
        }

        if ($validated['selectedUnitIds'] === [] && $accessories === []) {
            $this->addError('selectedUnitIds', 'Select at least one handset or accessory.');

            return;
        }

        $from = BusinessUnit::query()->findOrFail((int) $validated['fromUnitId']);
        $to = BusinessUnit::query()->findOrFail((int) $validated['toUnitId']);

        try {
            $transfer = $transfers->request(
                from: $from,
                to: $to,
                phoneUnitIds: $validated['selectedUnitIds'],
                accessoryLines: $accessories,
                actor: $user,
                note: $validated['notes'] ?: null,
            );

            if ($this->dispatchNow) {
                if (! $user->can('stock.transfer.approve')) {
                    session()->flash('error', "Transfer {$transfer->transfer_no} requested. You need stock.transfer.approve to dispatch it.");
                } else {
                    $transfers->dispatch($transfer, $user);
                    session()->flash('success', "Transfer {$transfer->transfer_no} dispatched to {$to->name}. Stock is in transit and not sellable.");
                }
            } else {
                session()->flash('success', "Transfer {$transfer->transfer_no} requested. Handsets are reserved until dispatch.");
            }

            $this->redirect(route('transfers.show', $transfer), navigate: true);
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError($field, $message);
            }

            $this->addError('form', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $user = auth()->user();
        $accessible = $user->accessibleUnitIds();

        $selected = SerializedUnit::query()
            ->with('variant.product.brand')
            ->whereIn('id', $this->selectedUnitIds)
            ->get();

        $searchResults = collect();

        if (trim($this->phoneSearch) !== '' && $this->fromUnitId !== '') {
            $term = '%'.trim($this->phoneSearch).'%';

            $searchResults = SerializedUnit::query()
                ->with('variant.product.brand')
                ->where('current_unit_id', (int) $this->fromUnitId)
                ->where('current_custody', Custody::InUnit->value)
                ->where('sellability_status', Sellability::Available->value)
                ->where(function ($query) use ($term): void {
                    $query->where('primary_imei', 'like', $term)->orWhere('secondary_imei', 'like', $term);
                })
                ->limit(8)
                ->get();
        }

        $accessoryBalances = AccessoryStockBalance::query()
            ->with('variant.product.brand')
            ->where('business_unit_id', (int) $this->fromUnitId)
            ->where('qty_on_hand', '>', 0)
            ->get()
            ->sortBy(fn (AccessoryStockBalance $balance) => $balance->variant?->displayName());

        return view('livewire.transfers.form', [
            'units' => BusinessUnit::query()->whereIn('id', $accessible)->active()->ordered()->get(),
            'selected' => $selected,
            'searchResults' => $searchResults,
            'accessoryBalances' => $accessoryBalances,
            'canApprove' => $user->can('stock.transfer.approve'),
        ]);
    }
}
