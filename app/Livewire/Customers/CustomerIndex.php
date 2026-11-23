<?php

declare(strict_types=1);

namespace App\Livewire\Customers;

use App\Domain\Sales\CustomerPaymentService;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Exceptions\ErpException;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Sale;
use App\Support\AuditLogger;
use App\Support\Money;
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
class CustomerIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    public bool $modalOpen = false;

    public ?int $editingId = null;

    public string $type = 'PERSON';

    public string $name = '';

    public string $businessName = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $creditLimit = '0';

    public string $notes = '';

    public bool $isActive = true;

    public bool $collectOpen = false;

    public ?int $collectCustomerId = null;

    public string $collectAmount = '';

    public string $collectMethod = 'CASH';

    public string $collectReference = '';

    public string $collectNotes = '';

    public string $collectSaleId = '';

    #[Locked]
    public string $collectionKey = '';

    #[Locked]
    public string $collectionUnitId = '';

    public function updatedCollectSaleId(): void
    {
        $sale = Sale::where('customer_id', $this->collectCustomerId)->where('business_unit_id', $this->collectionUnitId)
            ->where('due_total', '>', 0)->find((int) $this->collectSaleId);
        if ($sale) {
            $next = $sale->paymentSchedules()->whereColumn('paid_amount', '<', 'amount')->orderBy('due_date')->first();
            $this->collectAmount = $next ? $next->remaining()->amount : $sale->due_total;
        }
    }

    public function mount(): void
    {
        $this->authorize('viewAny', Customer::class);

        $saleId = (int) request()->query('sale', 0);

        if ($saleId > 0 && auth()->user()->can('payment.collect')) {
            $sale = Sale::query()
                ->whereKey($saleId)
                ->where('due_total', '>', 0)
                ->first();

            if ($sale !== null && $sale->customer_id !== null && auth()->user()->hasUnitAccess($sale->business_unit_id)) {
                $this->openCollect((int) $sale->customer_id, $sale->getKey(), (int) $sale->business_unit_id);
            }
        }
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    public function openModal(?int $customerId = null): void
    {
        $this->authorize('create', Customer::class);
        $this->resetErrorBag();
        $this->editingId = $customerId;

        if ($customerId !== null) {
            $customer = Customer::query()->findOrFail($customerId);
            $this->type = $customer->type->value;
            $this->name = $customer->name;
            $this->businessName = (string) $customer->business_name;
            $this->phone = (string) $customer->phone;
            $this->email = (string) $customer->email;
            $this->address = (string) $customer->address;
            $this->creditLimit = (string) $customer->credit_limit;
            $this->notes = (string) $customer->notes;
            $this->isActive = $customer->is_active;
        } else {
            $this->type = 'PERSON';
            $this->name = '';
            $this->businessName = '';
            $this->phone = '';
            $this->email = '';
            $this->address = '';
            $this->creditLimit = '0';
            $this->notes = '';
            $this->isActive = true;
        }

        $this->modalOpen = true;
    }

    public function save(): void
    {
        $this->authorize('create', Customer::class);

        $validated = $this->validate([
            'type' => ['required', 'in:PERSON,SHOP'],
            'name' => ['required', 'string', 'max:180'],
            'businessName' => ['nullable', 'string', 'max:180'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:255'],
            'creditLimit' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'isActive' => ['boolean'],
        ]);

        $attributes = [
            'type' => $validated['type'],
            'name' => $validated['name'],
            'business_name' => $validated['businessName'] ?: null,
            'phone' => $validated['phone'] ?: null,
            'email' => $validated['email'] ?: null,
            'address' => $validated['address'] ?: null,
            'credit_limit' => $validated['creditLimit'],
            'notes' => $validated['notes'] ?: null,
            'is_active' => $validated['isActive'],
        ];

        if ($this->editingId !== null) {
            $customer = Customer::query()->findOrFail($this->editingId);
            $old = $customer->only(array_keys($attributes));
            $customer->forceFill($attributes)->save();
            AuditLogger::record('customer.updated', $customer, $old, $attributes, null, null, auth()->user());
        } else {
            $customer = Customer::create($attributes);
            AuditLogger::record('customer.created', $customer, [], $attributes, null, null, auth()->user());
        }

        $this->modalOpen = false;
        session()->flash('success', "Customer {$customer->displayName()} saved.");
    }

    public function openCollect(int $customerId, ?int $saleId = null, ?int $unitId = null): void
    {
        $this->authorize('payment.collect');
        $customer = Customer::query()->findOrFail($customerId);
        $unit = $unitId !== null
            ? BusinessUnit::query()->findOrFail($unitId)
            : app(UnitContext::class)->requireCurrent(auth()->user());

        if (! auth()->user()->hasUnitAccess($unit->getKey())) {
            abort(403);
        }

        $outstanding = Sale::query()
            ->where('customer_id', $customer->getKey())
            ->where('business_unit_id', $unit->getKey())
            ->where('status', SaleStatus::Posted->value)
            ->when($saleId !== null, fn ($query) => $query->whereKey($saleId))
            ->sum('due_total');

        if ((float) $outstanding <= 0) {
            $this->addError('collection', 'This customer has no outstanding due in the active unit. Switch unit to collect elsewhere.');

            return;
        }

        $this->collectCustomerId = $customer->getKey();
        $this->collectSaleId = $saleId !== null ? (string) $saleId : '';
        $this->collectionUnitId = (string) $unit->id;
        $this->collectionKey = (string) Str::uuid();
        $this->collectAmount = number_format((float) $outstanding, 2, '.', '');
        $this->collectMethod = PaymentMethod::Cash->value;
        $this->collectReference = '';
        $this->collectNotes = '';
        $this->resetErrorBag();
        $this->collectOpen = true;

        if ($saleId !== null) {
            $this->updatedCollectSaleId();
        }
    }

    public function saveCollection(CustomerPaymentService $payments, UnitContext $unitContext): void
    {
        $this->authorize('payment.collect');

        $validated = $this->validate([
            'collectAmount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
            'collectSaleId' => ['nullable', 'integer', Rule::exists('sales', 'id')->where('customer_id', $this->collectCustomerId)->where('business_unit_id', $this->collectionUnitId)],
            'collectMethod' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
            'collectReference' => ['nullable', 'string', 'max:120'],
            'collectNotes' => ['nullable', 'string', 'max:500'],
        ]);

        $customer = Customer::query()->findOrFail($this->collectCustomerId);
        $unit = BusinessUnit::findOrFail((int) $this->collectionUnitId);

        try {
            $payment = $payments->collect(
                customer: $customer,
                unit: $unit,
                method: PaymentMethod::from($validated['collectMethod']),
                amount: Money::of($validated['collectAmount']),
                reference: $validated['collectReference'] ?: null,
                notes: $validated['collectNotes'] ?: null,
                actor: auth()->user(),
                idempotencyKey: $this->collectionKey,
                saleId: filled($this->collectSaleId) ? (int) $this->collectSaleId : null,
            );
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError($field, $message);
            }

            $this->addError('collection', $exception->getMessage());

            return;
        }

        $this->collectOpen = false;
        session()->flash('success', "Collection {$payment->receipt_no} posted for {$customer->displayName()}.");
    }

    public function render(): View
    {
        $query = Customer::query()
            ->withCount(['sales' => fn ($sale) => $sale->whereIn('business_unit_id', auth()->user()->accessibleUnitIds())])
            ->withSum(['sales as due_sum' => fn ($sale) => $sale->where('status', 'POSTED')->whereIn('business_unit_id', auth()->user()->accessibleUnitIds())], 'due_total');

        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $query->where(function ($builder) use ($term): void {
                $builder->where('name', 'like', $term)
                    ->orWhere('business_name', 'like', $term)
                    ->orWhere('phone', 'like', $term)
                    ->orWhere('email', 'like', $term);
            });
        }

        return view('livewire.customers.index', [
            'collectInvoices' => $this->collectOpen ? Sale::with('paymentSchedules')->where('customer_id', $this->collectCustomerId)->where('business_unit_id', $this->collectionUnitId)->where('due_total', '>', 0)->orderBy('posted_at')->get() : collect(),
            'customers' => $query->orderBy('name')->paginate(25),
            'currency' => config('erp.currency.symbol'),
            'canEdit' => auth()->user()->can('customer.edit'),
            'canCollect' => auth()->user()->can('payment.collect'),
            'paymentMethods' => PaymentMethod::cases(),
        ]);
    }
}
