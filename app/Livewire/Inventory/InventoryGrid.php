<?php

declare(strict_types=1);

namespace App\Livewire\Inventory;

use App\Domain\Inventory\InventoryGridQuery;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Inventory\UnitAccessoryService;
use App\Domain\Inventory\UnitDetailsService;
use App\Enums\Custody;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Models\Brand;
use App\Models\BusinessUnit;
use App\Models\SerializedUnit;
use App\Models\Setting;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class InventoryGrid extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'unit')]
    public string $unitFilter = 'all';

    #[Url]
    public string $custody = 'all';

    #[Url]
    public string $sellability = 'all';

    #[Url]
    public string $brand = 'all';

    #[Url]
    public string $condition = 'all';

    #[Url]
    public string $sort = 'recent';

    #[Url]
    public string $direction = 'desc';

    #[Url]
    public int $perPage = 25;

    public ?int $editingId = null;

    /** @var array<string, mixed> */
    public array $edit = [];

    public ?int $identifierUnitId = null;

    /** @var array<string, mixed> */
    public array $identifierForm = [];

    public ?int $extrasUnitId = null;

    /** @var array<string, mixed> */
    public array $extrasForm = [];

    public function mount(): void
    {
        $this->authorize('viewAny', SerializedUnit::class);
        if (! request()->has('unit') && (int) Setting::get('units.default_id', 0) > 0) {
            $this->unitFilter = (string) app(UnitContext::class)->requireCurrent(auth()->user())->id;
        }

        if (! in_array($this->perPage, [10, 25, 50, 100], true)) {
            $this->perPage = 25;
        }
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'unitFilter', 'custody', 'sellability', 'brand', 'condition', 'perPage'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'unitFilter', 'custody', 'sellability', 'brand', 'condition']);
        $this->resetPage();
    }

    public function startEdit(int $unitId): void
    {
        $unit = SerializedUnit::query()->findOrFail($unitId);
        $this->authorize('update', $unit);

        $this->editingId = $unit->getKey();
        $this->edit = [
            'battery_health' => $unit->battery_health,
            'cycle_count' => $unit->cycle_count,
            'condition_grade' => $unit->condition_grade,
            'box_kit' => $unit->box_kit,
            'expected_selling_price' => $unit->expected_selling_price,
            'status_note' => $unit->status_note,
            'notes' => $unit->notes,
        ];
        $this->resetErrorBag();
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->edit = [];
        $this->resetErrorBag();
    }

    public function saveEdit(UnitDetailsService $details): void
    {
        if ($this->editingId === null) {
            return;
        }

        $unit = SerializedUnit::query()->findOrFail($this->editingId);
        $this->authorize('update', $unit);

        $validated = $this->validate([
            'edit.battery_health' => ['nullable', 'integer', 'between:0,100'],
            'edit.cycle_count' => ['nullable', 'integer', 'between:0,100000'],
            'edit.condition_grade' => ['nullable', 'string', 'max:20'],
            'edit.box_kit' => ['nullable', 'string', 'max:60'],
            'edit.expected_selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'edit.status_note' => ['nullable', 'string', 'max:255'],
            'edit.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $details->update($unit, $validated['edit'], auth()->user());
        } catch (ErpException $exception) {
            $this->addError('grid', $exception->getMessage());

            return;
        }

        $this->editingId = null;
        $this->edit = [];
        $this->dispatch('notify', message: 'Handset profile updated.');
        session()->flash('success', 'Handset profile updated with an audit entry.');
    }

    public function openIdentifiers(int $unitId): void
    {
        $unit = SerializedUnit::query()->findOrFail($unitId);
        $this->authorize('update', $unit);

        $this->identifierUnitId = $unit->getKey();
        $this->identifierForm = [
            'primary_imei' => $unit->primary_imei,
            'secondary_imei' => $unit->secondary_imei,
            'allow_legacy' => false,
            'flag_reason' => $unit->imei_flag_reason,
        ];
        $this->resetErrorBag();
    }

    public function closeIdentifiers(): void
    {
        $this->identifierUnitId = null;
        $this->identifierForm = [];
        $this->resetErrorBag();
    }

    public function saveIdentifiers(SerialIdentityService $identities): void
    {
        if ($this->identifierUnitId === null) {
            return;
        }

        $unit = SerializedUnit::query()->findOrFail($this->identifierUnitId);
        $this->authorize('update', $unit);

        $validated = $this->validate([
            'identifierForm.primary_imei' => ['nullable', 'string', 'max:32'],
            'identifierForm.secondary_imei' => ['nullable', 'string', 'max:32'],
            'identifierForm.allow_legacy' => ['boolean'],
            'identifierForm.flag_reason' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $identities->updateIdentifiers(
                unit: $unit,
                primaryRaw: $validated['identifierForm']['primary_imei'] ?? null,
                secondaryRaw: $validated['identifierForm']['secondary_imei'] ?? null,
                actor: auth()->user(),
                allowLegacy: (bool) ($validated['identifierForm']['allow_legacy'] ?? false),
                flagReason: $validated['identifierForm']['flag_reason'] ?? null,
            );
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError("identifierForm.{$field}", $message);
            }

            $this->addError('identifierForm', $exception->getMessage());

            return;
        }

        $this->closeIdentifiers();
        session()->flash('success', 'IMEI identifiers corrected. The previous values remain in the audit log.');
    }

    public function openExtras(int $unitId, UnitAccessoryService $accessories): void
    {
        $unit = SerializedUnit::query()->with('accessories')->findOrFail($unitId);
        $this->authorize('update', $unit);

        $this->extrasUnitId = $unit->getKey();
        $this->extrasForm = $accessories->bundle($unit);
        $this->resetErrorBag();
    }

    public function closeExtras(): void
    {
        $this->extrasUnitId = null;
        $this->extrasForm = [];
        $this->resetErrorBag();
    }

    public function saveExtras(UnitAccessoryService $accessories): void
    {
        if ($this->extrasUnitId === null) {
            return;
        }

        $unit = SerializedUnit::query()->findOrFail($this->extrasUnitId);
        $this->authorize('update', $unit);

        $validated = $this->validate([
            'extrasForm.adapter_name' => ['nullable', 'string', 'max:120'],
            'extrasForm.adapter_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'extrasForm.cable_name' => ['nullable', 'string', 'max:120'],
            'extrasForm.cable_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
        ]);

        try {
            $accessories->syncBundle($unit, $validated['extrasForm'], $unit->activeCycle, auth()->user());
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError("extrasForm.{$field}", $message);
            }

            $this->addError('extrasForm', $exception->getMessage());

            return;
        }

        $this->closeExtras();
        session()->flash('success', 'Adapter/cable bundle updated with an audit entry.');
    }

    public function render(InventoryGridQuery $grid): View
    {
        $user = auth()->user();

        $units = $grid->build($user, [
            'search' => $this->search,
            'unit_id' => $this->unitFilter === 'all' ? null : (int) $this->unitFilter,
            'custody' => $this->custody,
            'sellability' => $this->sellability,
            'brand_id' => $this->brand,
            'condition' => $this->condition,
            'sort' => $this->sort,
            'direction' => $this->direction,
        ])->paginate($this->perPage);

        return view('livewire.inventory.grid', [
            'units' => $units,
            'brands' => Brand::query()->orderBy('name')->get(),
            'businessUnits' => BusinessUnit::query()->whereIn('id', $user->accessibleUnitIds())->ordered()->get(),
            'canSeeCost' => $user->can('inventory.cost.view'),
            'canUpdate' => $user->can('inventory.update'),
            'custodyCases' => Custody::cases(),
            'sellabilityCases' => Sellability::cases(),
            'conditions' => SerializedUnit::query()
                ->whereNotNull('condition_grade')
                ->distinct()
                ->orderBy('condition_grade')
                ->pluck('condition_grade'),
        ]);
    }
}
