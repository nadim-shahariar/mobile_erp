<?php

namespace App\Livewire\Pos;

use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Repairs\PhoneRepairService;
use App\Domain\Reporting\PosStockSummary;
use App\Enums\PaymentMethod;
use App\Exceptions\ErpException;
use App\Models\BusinessUnit;
use App\Models\PhoneRepair;
use App\Models\SerializedUnit;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

class RepairDesk extends Component
{
    #[Locked]
    public string $unitId = '';

    public bool $sendOpen = false;

    #[Locked]
    public ?int $phoneId = null;

    #[Locked]
    public string $requestKey = '';

    public string $vendor = '';

    public string $notes = '';

    #[Locked]
    public ?int $listUnitId = null;

    #[Locked]
    public ?int $returnId = null;

    public string $scan = '';

    public string $search = '';

    public string $cost = '0';

    public string $method = 'CASH';

    public string $returnNotes = '';

    public function mount(string $unitId): void
    {
        $this->unitId = $unitId;
    }

    #[On('open-phone-repair')]
    public function openSend(int $phoneId): void
    {
        $this->authorize('repair.create');
        $this->checkUnit((int) $this->unitId);
        $phone = SerializedUnit::findOrFail($phoneId);
        abort_unless((int) $phone->current_unit_id === (int) $this->unitId && $phone->isSellable(), 422);
        $this->phoneId = $phoneId;
        $this->sendOpen = true;
        $this->vendor = '';
        $this->notes = '';
        $this->requestKey = (string) Str::uuid();
        $this->resetErrorBag();
    }

    private function checkUnit(int $id): void
    {
        abort_unless(auth()->user()->hasUnitAccess($id), 403);
    }

    public function sendPhone(): void
    {
        $this->validate(['vendor' => 'nullable|string|max:180', 'notes' => 'nullable|string|max:2000']);
        try {
            app(PhoneRepairService::class)->send($this->phoneId, (int) $this->unitId, auth()->user(), $this->requestKey, $this->vendor, $this->notes);
        } catch (ErpException $e) {
            $this->addError('repair', $e->getMessage());

            return;
        }
        $this->sendOpen = false;
        $this->dispatch('unit-phone-moved', phoneId: $this->phoneId);
        session()->flash('repairSuccess', 'Phone sent to repair and removed from available stock.');
    }

    public function openRepairs(int $unitId): void
    {
        $this->authorize('inventory.view');
        $this->checkUnit($unitId);
        $this->listUnitId = $unitId;
        $this->returnId = null;
        $this->scan = '';
        $this->search = '';
        $this->resetErrorBag();
    }

    public function chooseReturn(int $id): void
    {
        $this->authorize('repair.receive');
        $this->checkUnit((int) $this->listUnitId);
        PhoneRepair::where('business_unit_id', $this->listUnitId)->where('status', 'OUT')->findOrFail($id);
        $this->returnId = $id;
        $this->cost = '0';
        $this->method = 'CASH';
        $this->returnNotes = '';
        $this->resetErrorBag();
    }

    public function scanReturn(): void
    {
        $this->authorize('repair.receive');
        $this->checkUnit((int) $this->listUnitId);
        $phone = app(SerialIdentityService::class)->findByImei($this->scan);
        $repair = PhoneRepair::where('business_unit_id', $this->listUnitId)->where('status', 'OUT')->where('serialized_unit_id', $phone?->id)->first();
        if (! $repair) {
            $this->addError('scan', 'No phone at repair in this unit matches that IMEI.');

            return;
        }
        $this->chooseReturn($repair->id);
        $this->scan = '';
    }

    public function receivePhone(): void
    {
        $this->validate(['cost' => 'required|numeric|decimal:0,2|min:0|max:999999999', 'method' => ['required', Rule::enum(PaymentMethod::class)], 'returnNotes' => 'nullable|string|max:2000']);
        $repair = PhoneRepair::findOrFail($this->returnId);
        $this->checkUnit($repair->business_unit_id);
        abort_unless($repair->business_unit_id === $this->listUnitId, 403);
        try {
            app(PhoneRepairService::class)->receive($repair->id, $this->cost, $this->method, auth()->user(), $this->returnNotes);
        } catch (ErpException $e) {
            $this->addError('repair', $e->getMessage());

            return;
        }
        $this->returnId = null;
        $this->dispatch('unit-phone-moved', phoneId: $repair->serialized_unit_id);
        session()->flash('repairSuccess', 'Phone returned to stock. Repair payment added to this phone’s cost.');
    }

    public function render()
    {
        $this->checkUnit((int) $this->unitId);
        $repairs = collect();
        if ($this->listUnitId) {
            $this->checkUnit($this->listUnitId);
            $query = PhoneRepair::where('business_unit_id', $this->listUnitId)->where('status', 'OUT')->with('phone.variant.product.brand');
            if (trim($this->search) !== '') {
                $term = '%'.trim($this->search).'%';
                $query->where(fn ($q) => $q->where('vendor_name', 'like', $term)->orWhereHas('phone', fn ($p) => $p->where('primary_imei', 'like', $term)->orWhere('secondary_imei', 'like', $term)->orWhereHas('variant.product', fn ($m) => $m->where('name', 'like', $term))));
            }
            $repairs = $query->oldest('sent_at')->get();
        }

        return view('livewire.pos.repair-desk', ['stockSummary' => app(PosStockSummary::class)->forUnit(auth()->user(), (int) $this->unitId), 'sourceUnit' => BusinessUnit::find($this->unitId), 'listUnit' => $this->listUnitId ? BusinessUnit::find($this->listUnitId) : null, 'repairs' => $repairs, 'sendingPhone' => $this->sendOpen ? SerializedUnit::find($this->phoneId) : null, 'returnRepair' => $this->returnId ? PhoneRepair::with('phone.variant.product.brand')->where('business_unit_id',$this->listUnitId)->find($this->returnId) : null]);
    }
}
