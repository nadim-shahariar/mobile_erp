<?php

declare(strict_types=1);

namespace App\Livewire\Pos;

use App\Domain\Exchanges\ExchangeService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Sales\PaymentScheduleService;
use App\Domain\Sales\SaleService;
use App\Domain\Transfers\PosUnitTransferService;
use App\Enums\Custody;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Enums\Sellability;
use App\Exceptions\ErpException;
use App\Models\AccessoryStockBalance;
use App\Models\Brand;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\Money;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

#[Layout('layouts.pos')]
class PosTerminal extends Component
{
    public string $unitId = '';

    public ?int $customerId = null;

    public string $phoneSearch = '';

    public string $accessorySearch = '';

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    /** @var list<array{method: string, amount: string, reference: string}> */
    public array $payments = [];

    #[Locked]
    public int $paymentVersion = 0;

    public string $invoiceDiscount = '0';

    public string $notes = '';

    public string $idempotencyKey = '';

    public bool $customerModalOpen = false;

    public string $customerName = '';

    public string $customerPhone = '';

    public string $customerType = 'PERSON';

    public string $customerCreditLimit = '0';

    public string $customerAddress = '';

    public bool $stockEntryOpen = false;

    public string $stockEntryMode = 'phone';

    public int $stockEntryVersion = 0;

    public bool $unitTransferOpen = false;

    #[Locked]
    public ?int $transferPhoneId = null;

    public string $transferDestination = '';

    #[Locked]
    public string $transferKey = '';

    #[Locked]
    public ?int $lastUnitTransferId = null;

    public function openUnitTransfer(int $phoneId): void
    {
        $this->authorize('stock.transfer.create');
        $this->authorize('stock.transfer.approve');
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);
        $phone = SerializedUnit::findOrFail($phoneId);
        abort_unless((int) $phone->current_unit_id === (int) $this->unitId && $phone->isSellable(), 422);
        $this->transferPhoneId = $phoneId;
        $this->transferDestination = (string) (BusinessUnit::active()->whereIn('id', auth()->user()->accessibleUnitIds())->where('id', '!=', (int) $this->unitId)->ordered()->value('id') ?? '');
        $this->transferKey = (string) Str::uuid();
        $this->unitTransferOpen = true;
        $this->resetErrorBag('unitTransfer');
    }

    public function transferSelectedPhone(): void
    {
        $this->validate(['transferDestination' => 'required|integer|exists:business_units,id']);
        try {
            $transfer = app(PosUnitTransferService::class)->move((int) $this->unitId, (int) $this->transferDestination, [$this->transferPhoneId], auth()->user(), $this->transferKey, 'Direct transfer from POS');
        } catch (ErpException $e) {
            $this->addError('unitTransfer', $e->getMessage());

            return;
        }
        $this->lastUnitTransferId = $transfer->id;
        $this->unitTransferOpen = false;
        $this->lines = array_values(array_filter($this->lines, fn ($line) => ! ($line['line_type'] === 'PHONE' && (int) $line['reference_id'] === $this->transferPhoneId)));
        $this->syncExchangeSettlement();
        session()->flash('success', 'Phone transferred to '.$transfer->toUnit->name.'. Transfer memo is ready to print.');
    }

    #[On('unit-phone-moved')]
    public function removeTransferredPhone(int $phoneId): void
    {
        $phone = SerializedUnit::find($phoneId);
        if (! $phone || (int) $phone->current_unit_id !== (int) $this->unitId) {
            $this->lines = array_values(array_filter($this->lines, fn ($line) => ! ($line['line_type'] === 'PHONE' && (int) $line['reference_id'] === $phoneId)));
            $this->syncExchangeSettlement();
        }
    }

    public function openStockEntry(string $mode = 'phone'): void
    {
        $this->authorize('create', SerializedUnit::class);
        abort_unless(in_array($mode, ['phone', 'product'], true), 422);
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);
        $this->stockEntryMode = $mode;
        $this->stockEntryVersion++;
        $this->stockEntryOpen = true;
    }

    public function closeStockEntry(): void
    {
        $this->stockEntryOpen = false;
    }

    #[On('stock-entry-saved')]
    public function refreshStock(): void
    {
        // Re-render stock search results while retaining the invoice and tender inputs.
    }

    public bool $exchangeModalOpen = false;

    public bool $exchangeEnabled = false;

    public array $incoming = [
        'kind' => 'PHONE', 'primaryImei' => '', 'secondaryImei' => '',
        'brandName' => '', 'modelName' => '', 'country' => '', 'colour' => '',
        'storage' => '', 'ram' => '', 'batteryHealth' => '', 'cycleCount' => '',
        'boxKit' => '', 'conditionGrade' => 'A', 'category' => 'PRE_OWNED',
        'buyingPrice' => '', 'askingPrice' => '', 'notes' => '', 'statusNote' => '',
        'productSku' => '', 'productName' => '', 'inStock' => true,
    ];

    public string $exchangeDirection = 'CUSTOMER';

    public string $exchangeAmount = '0';

    public string $exchangeMethod = 'CASH';

    public array $exchangePayments = [];

    public function addExchangePayment(): void
    {
        if (count($this->exchangePayments) < 20) {
            $this->exchangePayments[] = ['key' => (string) Str::uuid(), 'method' => 'CASH', 'amount' => '', 'reference' => ''];
        }
    }

    public function removeExchangePayment(int $index): void
    {
        unset($this->exchangePayments[$index]);
        $this->exchangePayments = array_values($this->exchangePayments);
        $this->resetErrorBag();
    }

    private function syncExchangeSettlement(): void
    {
        if (! $this->exchangeEnabled) {
            return;
        }
        $buying = (string) ($this->incoming['buyingPrice'] ?? '');
        $buying = preg_match('/^\d+(\.\d+)?$/', $buying) ? $buying : '0';
        $difference = $this->totals()['total']->sub($buying);
        $required = $difference->isNegative() ? Money::zero()->sub($difference) : $difference;
        $previous = Money::of($this->exchangeAmount);
        $this->exchangeDirection = $difference->isPositive() ? 'CUSTOMER' : ($difference->isNegative() ? 'COUNTER' : 'EVEN');
        $this->exchangeAmount = $required->amount;
        if ($this->exchangePayments === [] && $required->isPositive()) {
            $this->exchangePayments = [['key' => (string) Str::uuid(), 'method' => 'CASH', 'amount' => $required->amount, 'reference' => '']];
        } elseif (count($this->exchangePayments) === 1) {
            $value = (string) ($this->exchangePayments[0]['amount'] ?? '');
            if (preg_match('/^\d+(\.\d+)?$/', $value) && Money::of($value)->equals($previous)) {
                $this->exchangePayments[0]['amount'] = $required->amount;
            }
        }
    }

    public function openExchange(): void
    {
        $this->authorize('exchange.create');
        $this->exchangeModalOpen = true;
        $this->resetErrorBag();
    }

    public function updatedIncoming(mixed $value, ?string $path): void
    {
        if ($path === 'primaryImei' || $path === 'secondaryImei') {
            $unit = app(SerialIdentityService::class)->findByImei((string) $value);
            if ($unit && auth()->user()->can('view', $unit)) {
                $variant = $unit->variant;
                $this->incoming = array_merge($this->incoming, [
                    'primaryImei' => $unit->primary_imei ?? '', 'secondaryImei' => $unit->secondary_imei ?? '',
                    'brandName' => $variant->product->brand?->name ?? '', 'modelName' => $variant->product->name,
                    'country' => $variant->country ?? '', 'colour' => $variant->color ?? '',
                    'storage' => $variant->storage ?? '', 'ram' => $variant->ram ?? '',
                    'batteryHealth' => $unit->battery_health === null ? '' : (string) $unit->battery_health,
                    'cycleCount' => $unit->cycle_count === null ? '' : (string) $unit->cycle_count,
                    'boxKit' => $unit->box_kit ?? '', 'conditionGrade' => $unit->condition_grade ?? '',
                    'askingPrice' => $unit->expected_selling_price ?? '', 'category' => $unit->inventory_category ?? 'PRE_OWNED',
                    'notes' => $unit->notes ?? '', 'statusNote' => $unit->status_note ?? '', 'inStock' => true,
                ]);
            }
        }
        if ($path === 'productSku') {
            $variant = ProductVariant::with('product')->where('sku', trim((string) $value))->first();
            if ($variant?->product?->product_type === ProductType::Accessory) {
                $this->incoming['productName'] = $variant->product->name;
                $this->incoming['askingPrice'] = $variant->default_selling_price ?? '';
            }
        }
    }

    public function useExchange(): void
    {
        $this->authorize('exchange.create');
        $rules = [];
        foreach (ExchangeService::incomingRules() as $key => $rule) {
            $rules['incoming.'.$key] = array_map(fn ($r) => is_string($r) ? str_replace('required_if:kind,', 'required_if:incoming.kind,', $r) : $r, $rule);
        }
        $this->validate($rules);

        if (($this->incoming['kind'] ?? 'PHONE') === 'PHONE'
            && blank($this->incoming['primaryImei'] ?? null)
            && blank($this->incoming['secondaryImei'] ?? null)) {
            $this->addError('incoming.primaryImei', 'Enter IMEI 1 or IMEI 2 for the incoming phone.');

            return;
        }

        $wasEnabled = $this->exchangeEnabled;
        $this->exchangeEnabled = true;
        $this->exchangeModalOpen = false;
        $difference = $this->totals()['total']->sub($this->incoming['buyingPrice']);
        $this->exchangeDirection = $difference->isPositive() ? 'CUSTOMER' : ($difference->isNegative() ? 'COUNTER' : 'EVEN');
        $this->exchangeAmount = $difference->isNegative() ? Money::zero()->sub($difference)->amount : $difference->amount;
        if (! $wasEnabled || $this->exchangePayments === []) {
            $this->exchangePayments = $difference->isZero() ? [] : [['key' => (string) Str::uuid(), 'method' => 'CASH', 'amount' => $this->exchangeAmount, 'reference' => '']];
        }
    }

    public function removeExchange(): void
    {
        $this->exchangeEnabled = false;
        $this->resetErrorBag();
    }

    public string $emiRate = '0';

    public string $saleTerms = 'PARTIAL';

    public string $dueClearanceDate = '';

    public string $firstInstallmentDate = '';

    public string $installmentMonths = '';

    public string $monthlyInstallment = '';

    public function updatedSaleTerms(): void
    {
        $this->resetErrorBag();
        if ($this->saleTerms === 'CREDIT') {
            $this->payments = [];
        }
    }

    public function mount(UnitContext $unitContext): void
    {
        $this->authorize('pos.sell');

        $unit = $unitContext->requireCurrent(auth()->user());
        $this->unitId = (string) $unit->getKey();
        $this->payments = [['method' => PaymentMethod::Cash->value, 'amount' => '', 'reference' => '']];
        $this->idempotencyKey = (string) Str::uuid();
        $this->dueClearanceDate = BusinessClock::today()->addMonthNoOverflow()->toDateString();
        $this->firstInstallmentDate = $this->dueClearanceDate;
    }

    public function updatedUnitId(): void
    {
        $unit = BusinessUnit::query()->find((int) $this->unitId);

        if ($unit === null || ! auth()->user()->hasUnitAccess($unit->getKey())) {
            $this->addError('unitId', 'You do not have access to that unit.');
            $this->unitId = '';

            return;
        }

        $this->unitTransferOpen = false;
        $this->transferPhoneId = null;
        $this->lastUnitTransferId = null;
        $this->stockEntryOpen = false;
        $this->lines = [];
        $this->phoneSearch = '';
        $this->accessorySearch = '';
        $this->payments = [['method' => PaymentMethod::Cash->value, 'amount' => '', 'reference' => '']];
        $this->invoiceDiscount = '0';
        $this->notes = '';
        $this->exchangeEnabled = false;
        $this->exchangeModalOpen = false;
        $this->idempotencyKey = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function addPhone(int $serializedUnitId): void
    {
        $this->authorize('pos.sell');

        $unit = BusinessUnit::query()->find((int) $this->unitId);

        if ($unit === null || ! auth()->user()->hasUnitAccess($unit->id)) {
            $this->addError('phoneSearch', 'Choose a unit first.');

            return;
        }

        $serialized = SerializedUnit::query()
            ->with(['variant.product.brand', 'activeCycle'])
            ->findOrFail($serializedUnitId);

        if (
            (int) $serialized->current_unit_id !== (int) $unit->getKey()
            || $serialized->current_custody !== Custody::InUnit
            || ! $serialized->activeCycle
            || $serialized->sellability_status !== Sellability::Available
        ) {
            $this->addError('phoneSearch', 'That handset is no longer available in this unit.');

            return;
        }

        foreach ($this->lines as $line) {
            if ($line['line_type'] === 'PHONE' && (int) $line['reference_id'] === $serialized->getKey()) {
                $this->addError('phoneSearch', 'That IMEI is already on the invoice.');

                return;
            }
        }

        $price = $serialized->expected_selling_price ?? $serialized->variant?->default_selling_price;

        if ($price === null) {
            $this->addError('phoneSearch', 'No selling price is set for this handset. Set it in inventory first.');

            return;
        }

        $this->lines[] = [
            'line_type' => 'PHONE',
            'reference_id' => $serialized->getKey(),
            'description' => $serialized->displayName(),
            'identifier' => $serialized->primaryIdentifier(),
            'quantity' => 1,
            'stock' => 1,
            'unit_price' => (string) $price,
            'discount' => '0',
        ];

        $this->phoneSearch = '';
        $this->resetErrorBag('phoneSearch');
    }

    public function addAccessory(int $variantId): void
    {
        $this->authorize('pos.sell');

        $unit = BusinessUnit::query()->find((int) $this->unitId);

        if ($unit === null || ! auth()->user()->hasUnitAccess($unit->id)) {
            $this->addError('accessorySearch', 'Choose a unit first.');

            return;
        }

        $variant = ProductVariant::query()->with('product.brand')->findOrFail($variantId);

        if ($variant->product?->product_type !== ProductType::Accessory || ! $variant->is_active || ! $variant->product->is_active) {
            $this->addError('accessorySearch', 'Select an active accessory product.');

            return;
        }

        $balance = AccessoryStockBalance::query()
            ->where('business_unit_id', $unit->getKey())
            ->where('product_variant_id', $variant->getKey())
            ->first();

        $available = $balance === null ? 0 : $balance->qty_on_hand - $balance->qty_reserved;

        if ($available < 1) {
            $this->addError('accessorySearch', 'That accessory has no available stock in this unit.');

            return;
        }

        foreach ($this->lines as $index => $line) {
            if ($line['line_type'] === 'ACCESSORY' && (int) $line['reference_id'] === $variant->getKey()) {
                if ($line['quantity'] >= $available) {
                    $this->addError('accessorySearch', "Only {$available} unit(s) available.");

                    return;
                }

                $this->lines[$index]['quantity'] = (int) $line['quantity'] + 1;
                $this->accessorySearch = '';

                return;
            }
        }

        if ($variant->default_selling_price === null) {
            $this->addError('accessorySearch', 'No selling price is set for that accessory.');

            return;
        }

        $this->lines[] = [
            'line_type' => 'ACCESSORY',
            'reference_id' => $variant->getKey(),
            'description' => $variant->displayName(),
            'identifier' => $variant->sku,
            'quantity' => 1,
            'stock' => $available,
            'unit_price' => (string) $variant->default_selling_price,
            'discount' => '0',
        ];

        $this->accessorySearch = '';
        $this->resetErrorBag('accessorySearch');
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function addPayment(): void
    {
        $this->payments[] = ['method' => PaymentMethod::Cash->value, 'amount' => '', 'reference' => ''];
    }

    public function removePayment(int $index): void
    {
        $this->paymentVersion++;
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    public function setExactPayment(string $method): void
    {
        $method = PaymentMethod::tryFrom(strtoupper($method));
        if ($method === null) {
            return;
        }
        $this->saleTerms = 'PARTIAL';
        $amount = $this->totals()['cash_price'];
        $this->paymentVersion++;
        $this->payments = [[
            'method' => $method->value,
            'amount' => $amount->isNegative() ? '0.00' : $amount->amount,
            'reference' => '',
        ]];
        $this->resetErrorBag('payments');
    }

    public function openCustomerModal(): void
    {
        $this->authorize('customer.edit');
        $this->resetErrorBag();
        $this->customerModalOpen = true;
        $this->customerName = '';
        $this->customerPhone = '';
        $this->customerType = 'PERSON';
        $this->customerCreditLimit = '0';
        $this->customerAddress = '';
    }

    public function saveCustomer(): void
    {
        $this->authorize('customer.edit');

        $validated = $this->validate([
            'customerName' => ['required', 'string', 'max:180'],
            'customerPhone' => ['nullable', 'string', 'max:32'],
            'customerType' => ['required', 'in:PERSON,SHOP'],
            'customerCreditLimit' => ['required', 'numeric', 'min:0'],
            'customerAddress' => ['nullable', 'string', 'max:255'],
        ]);

        $existingCustomer = trim($validated['customerPhone']) !== '' ? Customer::active()->where('phone', trim($validated['customerPhone']))->first() : null;
        if ($existingCustomer) {
            $this->customerId = $existingCustomer->id;
            $this->customerModalOpen = false;
            session()->flash('success', 'Existing customer '.$existingCustomer->displayName().' selected.');

            return;
        }
        $customer = Customer::create([
            'type' => $validated['customerType'],
            'name' => $validated['customerName'],
            'phone' => $validated['customerPhone'] ?: null,
            'address' => $validated['customerAddress'] ?: null,
            'credit_limit' => $validated['customerCreditLimit'],
            'is_active' => true,
        ]);

        AuditLogger::record('customer.created', $customer, [], $customer->toArray(), null, (int) $this->unitId, auth()->user());

        $this->customerId = $customer->getKey();
        $this->customerModalOpen = false;
        $this->resetErrorBag();
        session()->flash('success', "Customer {$customer->displayName()} created.");
    }

    public function selectCustomer(mixed $customerId): void
    {
        $this->customerId = ($customerId === '' || $customerId === null) ? null : (int) $customerId;
        $this->resetErrorBag(['customerId', 'customer_id', 'exchange']);
    }

    public function complete(SaleService $sales): void
    {
        $this->authorize('pos.sell');
        $this->resetErrorBag();
        $this->syncExchangeSettlement();

        $validated = $this->validate([
            'emiRate' => [Rule::excludeIf($this->saleTerms !== 'INSTALLMENT' || $this->exchangeEnabled), 'required', 'numeric', 'decimal:0,2', 'between:0,100'],
            'installmentMonths' => [Rule::excludeIf($this->saleTerms !== 'INSTALLMENT' || $this->exchangeEnabled), 'required', 'integer', 'between:1,120'],
            'saleTerms' => ['required', 'in:PARTIAL,CREDIT,INSTALLMENT'],
            'unitId' => ['required', 'integer', Rule::in(auth()->user()->accessibleUnitIds())],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', 'in:PHONE,ACCESSORY'],
            'lines.*.reference_id' => ['required', 'integer'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            'lines.*.discount' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'payments' => [Rule::excludeIf($this->exchangeEnabled), 'array'],
            'payments.*.method' => ['required', Rule::in(array_column(PaymentMethod::cases(), 'value'))],
            'payments.*.amount' => ['nullable', 'numeric', 'min:0'],
            'payments.*.reference' => ['nullable', 'string', 'max:120'],
            'invoiceDiscount' => ['nullable', 'numeric', 'min:0'],
            'customerId' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('is_active', true)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $items = [];

        foreach ($validated['lines'] as $line) {
            $items[] = $line['line_type'] === 'PHONE'
                ? [
                    'line_type' => 'PHONE',
                    'serialized_unit_id' => (int) $line['reference_id'],
                    'unit_price' => (string) $line['unit_price'],
                    'discount' => (string) ($line['discount'] ?: '0'),
                ]
                : [
                    'line_type' => 'ACCESSORY',
                    'product_variant_id' => (int) $line['reference_id'],
                    'quantity' => (int) $line['quantity'],
                    'unit_price' => (string) $line['unit_price'],
                    'discount' => (string) ($line['discount'] ?: '0'),
                ];
        }

        $payments = [];

        foreach ($validated['payments'] ?? [] as $payment) {
            $amount = (string) ($payment['amount'] ?? '');

            if ($amount === '' || ! is_numeric($amount) || (float) $amount <= 0) {
                continue;
            }

            $payments[] = [
                'method' => $payment['method'],
                'amount' => $amount,
                'reference' => $payment['reference'] ?: null,
            ];
        }

        try {
            $saleData = [
                'business_unit_id' => (int) $validated['unitId'],
                'customer_id' => $this->customerId,
                'items' => $items,
                'payments' => $payments,
                'discount_total' => (string) ($validated['invoiceDiscount'] ?: '0'),
                'notes' => $this->notes ?: null,
                'idempotency_key' => $this->idempotencyKey,
            ];
            if ($this->exchangeEnabled) {
                $this->authorize('exchange.create');
                $sale = app(ExchangeService::class)->post($saleData, $this->incoming, [
                    'direction' => $this->exchangeDirection, 'payments' => $this->exchangeDirection === 'EVEN' ? [] : $this->exchangePayments,
                ], auth()->user());
            } else {
                if ($this->totals()['due']->isPositive()) {
                    $saleData['repayment_plan'] = [
                        'type' => $this->saleTerms === 'INSTALLMENT' ? 'INSTALLMENT' : 'DUE',
                        'first_date' => $this->saleTerms === 'INSTALLMENT' ? $this->firstInstallmentDate : $this->dueClearanceDate,
                        'months' => $this->saleTerms === 'INSTALLMENT' ? $this->installmentMonths : null,
                    ];
                } elseif ($this->saleTerms === 'INSTALLMENT') {
                    $this->addError('monthlyInstallment', 'Leave an unpaid balance to create an installment plan.');

                    return;
                }
                $saleData['emi_rate'] = $this->saleTerms === 'INSTALLMENT' ? $this->emiRate : '0';
                $sale = $sales->post($saleData, auth()->user());
            }
        } catch (ValidationException $exception) {
            if (! $this->exchangeEnabled) {
                $map = ['type' => 'saleTerms', 'first_date' => $this->saleTerms === 'INSTALLMENT' ? 'firstInstallmentDate' : 'dueClearanceDate', 'monthly_amount' => 'monthlyInstallment', 'months' => 'installmentMonths'];
                throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn ($messages, $key) => [$map[$key] ?? $key => $messages])->all());
            }
            $incomingKeys = array_keys(ExchangeService::incomingRules());
            $this->exchangeModalOpen = true;
            $mapped = [];
            foreach ($exception->errors() as $key => $messages) {
                $mapped[str_starts_with($key, 'payments') ? 'exchangePayments'.substr($key, strlen('payments')) : (in_array($key, $incomingKeys, true) ? 'incoming.'.$key : 'exchange')] = $messages;
            }
            throw ValidationException::withMessages($mapped);
        } catch (ErpException $exception) {
            if ($this->exchangeEnabled) {
                $this->exchangeModalOpen = true;
            }
            foreach ($exception->errors() as $field => $message) {
                $this->addError($field, $message);
            }

            $this->addError('sale', $exception->getMessage());

            return;
        }

        session()->flash('success', "Invoice {$sale->invoice_no} posted.");

        $this->redirect(route('invoices.show', $sale), navigate: true);
    }

    /**
     * @return array{subtotal: Money, line_discount: Money, invoice_discount: Money, total: Money, paid: Money, due: Money}
     */
    private function totals(): array
    {
        $subtotal = Money::zero();
        $lineDiscount = Money::zero();

        foreach ($this->lines as $line) {
            $price = Money::of(is_numeric($line['unit_price']) ? (string) $line['unit_price'] : '0');
            $quantity = max(1, (int) $line['quantity']);
            $discount = Money::of(is_numeric($line['discount']) ? (string) $line['discount'] : '0');

            $subtotal = $subtotal->add($price->mul($quantity)->sub($discount));
            $lineDiscount = $lineDiscount->add($discount);
        }

        $invoiceDiscount = Money::of(is_numeric($this->invoiceDiscount) ? $this->invoiceDiscount : '0');
        $total = $subtotal->sub($invoiceDiscount);

        $paid = Money::zero();

        foreach ($this->payments as $payment) {
            $amount = $payment['amount'];
            $paid = $paid->add(Money::of(is_numeric($amount) ? (string) $amount : '0'));
        }

        $emiCharge = Money::zero();
        $financed = $total->sub($paid);
        if (! $this->exchangeEnabled && $this->saleTerms === 'INSTALLMENT' && $financed->isPositive() && preg_match('/^\d+(\.\d{1,2})?$/', $this->emiRate) && (float) $this->emiRate >= 0 && (float) $this->emiRate <= 100) {
            $emiCharge = Money::of(bcdiv(bcmul($financed->amount, $this->emiRate, 8), '100', 8));
            $total = $total->add($emiCharge);
        }

        return [
            'emi_charge' => $emiCharge,
            'cash_price' => $total->sub($emiCharge),
            'subtotal' => $subtotal,
            'line_discount' => $lineDiscount,
            'invoice_discount' => $invoiceDiscount,
            'total' => $total,
            'paid' => $paid,
            'due' => $total->sub($paid),
        ];
    }

    public function render(): View
    {
        $user = auth()->user();
        $unitIds = $user->accessibleUnitIds();
        $unit = BusinessUnit::query()->whereIn('id', $unitIds)->active()->find((int) $this->unitId);

        $phoneResults = collect();

        if ($unit !== null && mb_strlen(trim($this->phoneSearch)) >= 1) {
            $term = '%'.trim($this->phoneSearch).'%';

            $phoneResults = SerializedUnit::query()
                ->with(['variant.product.brand'])
                ->where('current_unit_id', $unit->getKey())
                ->whereHas('activeCycle')
                ->where('current_custody', Custody::InUnit->value)
                ->where('sellability_status', Sellability::Available->value)
                ->where(fn ($query) => $query
                    ->where('primary_imei', 'like', $term)
                    ->orWhere('secondary_imei', 'like', $term)
                    ->orWhereHas('variant.product', fn ($product) => $product->where('name', 'like', $term))
                    ->orWhereHas('variant.product.brand', fn ($brand) => $brand->where('name', 'like', $term)))
                ->limit(6)
                ->get();
        }

        $accessoryResults = collect();

        if ($unit !== null && mb_strlen(trim($this->accessorySearch)) >= 1) {
            $term = '%'.trim($this->accessorySearch).'%';

            $accessoryResults = AccessoryStockBalance::query()
                ->with(['variant.product.brand'])
                ->where('business_unit_id', $unit->getKey())
                ->whereRaw('(qty_on_hand - qty_reserved) > 0')
                ->whereHas('variant', fn ($variant) => $variant
                    ->where('sku', 'like', $term)
                    ->orWhereHas('product', fn ($product) => $product->where('name', 'like', $term)
                        ->orWhereHas('brand', fn ($brand) => $brand->where('name', 'like', $term))))
                ->limit(6)
                ->get();
        }

        $this->syncExchangeSettlement();
        $totals = $this->totals();
        $repaymentPreview = [];
        if (! $this->exchangeEnabled && $totals['due']->isPositive()) {
            try {
                $repaymentPreview = app(PaymentScheduleService::class)->build($totals['due'], [
                    'type' => $this->saleTerms === 'INSTALLMENT' ? 'INSTALLMENT' : 'DUE',
                    'first_date' => $this->saleTerms === 'INSTALLMENT' ? $this->firstInstallmentDate : $this->dueClearanceDate,
                    'months' => $this->saleTerms === 'INSTALLMENT' ? $this->installmentMonths : null,
                ]);
            } catch (ValidationException $exception) {
                // Form validation is displayed when posting; incomplete inputs do not break the preview.
            }
        }
        $this->monthlyInstallment = $this->saleTerms === 'INSTALLMENT' && $repaymentPreview !== [] ? $repaymentPreview[0]['amount'] : '';
        $incomingMatch = app(SerialIdentityService::class)->findByImei($this->incoming['primaryImei'] ?? null);
        if ($incomingMatch && ! $user->can('view', $incomingMatch)) {
            $incomingMatch = null;
        }
        $exchangeDifference = $totals['total']->sub(is_numeric($this->incoming['buyingPrice'] ?? '') ? $this->incoming['buyingPrice'] : '0');

        $exchangePaid = Money::zero();
        foreach ($this->exchangeDirection === 'EVEN' ? [] : $this->exchangePayments as $payment) {
            $value = (string) ($payment['amount'] ?? '');
            if (preg_match('/^\d+(\.\d{1,2})?$/', $value)) {
                $exchangePaid = $exchangePaid->add($value);
            }
        }
        $exchangeRequired = $exchangeDifference->isNegative() ? Money::zero()->sub($exchangeDifference) : $exchangeDifference;

        $activeExactMethod = count($this->payments) === 1 && ! $totals['due']->isPositive() && $totals['paid']->equals($totals['total']) ? ($this->payments[0]['method'] ?? null) : null;

        return view('livewire.pos.terminal', [
            'activeExactMethod' => $activeExactMethod,
            'exchangePaid' => $exchangePaid,
            'exchangeRemaining' => $exchangeRequired->sub($exchangePaid),
            'repaymentPreview' => $repaymentPreview,
            'incomingMatch' => $incomingMatch,
            'exchangeDifference' => $exchangeDifference,
            'brands' => Brand::orderBy('name')->get(),
            'exchangeModels' => Product::phones()->with('brand')->where('is_active', true)->get()->filter(fn ($p) => mb_strtolower($p->brand?->name ?? '') === mb_strtolower(trim($this->incoming['brandName'] ?? ''))),
            'exchangeCountries' => collect(['Bangladesh', 'China', 'Hong Kong', 'India', 'Japan', 'Singapore', 'United Arab Emirates', 'United Kingdom', 'United States'])->merge(ProductVariant::whereNotNull('country')->distinct()->pluck('country'))->unique()->sort(),
            'exchangeColours' => ProductVariant::whereNotNull('color')->distinct()->pluck('color'),
            'exchangeAccessories' => ProductVariant::whereHas('product', fn ($q) => $q->where('product_type', 'ACCESSORY')->where('is_active', true))->get(),
            'units' => BusinessUnit::query()->whereIn('id', $unitIds)->active()->ordered()->get(),
            'unit' => $unit,
            'customers' => Customer::query()->active()->orderBy('name')->limit(300)->get(),
            'selectedCustomer' => $this->customerId !== null ? Customer::query()->find($this->customerId) : null,
            'phoneResults' => $phoneResults,
            'accessoryResults' => $accessoryResults,
            'paymentMethods' => PaymentMethod::cases(),
            'totals' => $totals,
            'currency' => config('erp.currency.symbol'),
            'recentSales' => Sale::query()
                ->with(['customer', 'items', 'payments', 'exchange'])
                ->where('business_unit_id', $unit?->id ?? 0)
                ->whereBetween('posted_at', [BusinessClock::businessDayStartUtc(), BusinessClock::businessDayEndUtc()])
                ->latest('posted_at')
                ->limit(10)
                ->get(),
        ]);
    }
}
