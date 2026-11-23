<?php

declare(strict_types=1);

namespace App\Livewire\Suppliers;

use App\Domain\Purchases\SupplierPaymentService;
use App\Enums\PaymentMethod;
use App\Models\Purchase;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Support\AuditLogger;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class SupplierIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $modalOpen = false;

    public ?int $editingId = null;

    public string $name = '';

    public string $type = 'BUSINESS';

    public string $phone = '';

    public string $address = '';

    public string $contactPerson = '';

    public string $notes = '';

    public bool $isActive = true;

    public ?int $paymentSupplier = null;

    public string $paymentAmount = '';

    public string $paymentMethod = 'CASH';

    public string $paymentReference = '';

    #[Locked]
    public string $paymentKey = '';

    public function openPayment(int $id): void
    {
        $this->authorize('purchase.approve');
        abort_unless(Setting::get('features.supplier_accounts', true), 403);
        Supplier::findOrFail($id);
        $this->paymentSupplier = $id;
        $this->paymentAmount = '';
        $this->paymentMethod = 'CASH';
        $this->paymentReference = '';
        $this->paymentKey = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function paySupplier(): void
    {
        $this->authorize('purchase.approve');
        abort_unless(Setting::get('features.supplier_accounts', true), 403);
        $this->validate(['paymentSupplier' => 'required|exists:suppliers,id', 'paymentAmount' => 'required|numeric|min:0.01|max:999999999', 'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)], 'paymentReference' => 'nullable|string|max:255']);
        app(SupplierPaymentService::class)->pay($this->paymentSupplier, $this->paymentAmount, $this->paymentMethod, $this->paymentKey, auth()->user(), $this->paymentReference);
        $this->paymentSupplier = null;
        $this->paymentAmount = '';
        session()->flash('success', 'Supplier payment saved and allocated to oldest unpaid purchases in this branch.');
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Supplier::class);
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function openModal(?int $supplierId = null): void
    {
        $this->authorize('create', Supplier::class);
        $this->resetErrorBag();
        $this->editingId = $supplierId;

        if ($supplierId !== null) {
            $supplier = Supplier::query()->findOrFail($supplierId);
            $this->name = $supplier->name;
            $this->type = $supplier->type;
            $this->phone = (string) $supplier->phone;
            $this->address = (string) $supplier->address;
            $this->contactPerson = (string) $supplier->contact_person;
            $this->notes = (string) $supplier->notes;
            $this->isActive = $supplier->is_active;
        } else {
            $this->name = '';
            $this->type = 'BUSINESS';
            $this->phone = '';
            $this->address = '';
            $this->contactPerson = '';
            $this->notes = '';
            $this->isActive = true;
        }

        $this->modalOpen = true;
    }

    public function save(): void
    {
        $this->authorize('create', Supplier::class);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:180'],
            'type' => ['required', 'in:PERSON,BUSINESS'],
            'phone' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:255'],
            'contactPerson' => ['nullable', 'string', 'max:180'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['boolean'],
        ]);

        $attributes = [
            'name' => $validated['name'],
            'type' => $validated['type'],
            'phone' => $validated['phone'] ?: null,
            'address' => $validated['address'] ?: null,
            'contact_person' => $validated['contactPerson'] ?: null,
            'notes' => $validated['notes'] ?: null,
            'is_active' => $validated['isActive'],
        ];

        if ($this->editingId !== null) {
            $supplier = Supplier::query()->findOrFail($this->editingId);
            $old = $supplier->only(array_keys($attributes));
            $supplier->forceFill($attributes)->save();
            AuditLogger::record('supplier.updated', $supplier, $old, $attributes, null, null, auth()->user());
        } else {
            $supplier = Supplier::create($attributes);
            AuditLogger::record('supplier.created', $supplier, [], $attributes, null, null, auth()->user());
        }

        $this->modalOpen = false;
        session()->flash('success', "Supplier {$supplier->name} saved.");
    }

    public function render(): View
    {
        $unit = app(UnitContext::class)->requireCurrent(auth()->user());
        $scope = fn ($q) => $q->where('business_unit_id', $unit->id)->where('status', 'RECEIVED');
        $query = Supplier::query()->withCount(['purchases' => $scope])
            ->withSum(['purchases as purchased_total' => $scope], 'total')
            ->withSum(['purchases as paid_total' => $scope], 'paid_amount')
            ->withSum(['purchases as due_total' => $scope], 'due_amount');

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(fn ($builder) => $builder->where('name', 'like', $term)->orWhere('phone', 'like', $term));
        }

        return view('livewire.suppliers.index', [
            'suppliers' => $query->orderBy('name')->paginate(25),
            'accountsEnabled' => Setting::get('features.supplier_accounts', true),
            'payments' => SupplierPayment::where('business_unit_id', $unit->id)->latest()->limit(30)->get(),
            'purchaseHistory' => Purchase::where('business_unit_id', $unit->id)->where('status', 'RECEIVED')->with('supplier')->latest()->limit(50)->get(),
            'canManage' => auth()->user()->can('supplier.manage'),
        ]);
    }
}
