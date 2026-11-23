<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domain\Purchases\PurchaseService;
use App\Enums\ProductType;
use App\Exceptions\ErpException;
use App\Models\BusinessUnit;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Support\BusinessClock;
use App\Support\Money;
use App\Support\UnitContext;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseForm extends Component
{
    #[Url(as: 'unit')]
    public string $unitId = '';

    public ?string $supplierId = null;

    public ?string $buyingParty = null;

    public string $purchaseDate = '';

    public string $discountTotal = '0';

    public string $otherCharges = '0';

    public string $paidAmount = '0';

    public string $paymentMethod = 'CASH';

    public string $reference = '';

    public string $notes = '';

    public bool $receiveNow = true;

    /** @var list<array<string, mixed>> */
    public array $lines = [];

    public function mount(UnitContext $unitContext): void
    {
        $this->authorize('create', Purchase::class);

        $current = $unitContext->current(auth()->user());
        $this->unitId = (string) ($current?->getKey() ?? '');
        $this->purchaseDate = BusinessClock::today()->toDateString();
        $this->addPhoneLine();
    }

    public function addPhoneLine(): void
    {
        $this->lines[] = [
            'line_type' => 'PHONE',
            'product_variant_id' => '',
            'quantity' => 1,
            'unit_cost' => '',
            'imei_1' => '',
            'imei_2' => '',
            'battery_health' => '',
            'cycle_count' => '',
            'condition_grade' => 'A',
            'box_kit' => '',
            'expected_selling_price' => '',
            'status_note' => '',
            'adapter_name' => '',
            'adapter_price' => '',
            'cable_name' => '',
            'cable_price' => '',
            'allow_legacy' => false,
            'flag_reason' => '',
            'generate_internal_serial' => false,
            'notes' => '',
        ];
    }

    public function addAccessoryLine(): void
    {
        $this->lines[] = [
            'line_type' => 'ACCESSORY',
            'product_variant_id' => '',
            'quantity' => 1,
            'unit_cost' => '',
            'imei_1' => null,
            'imei_2' => null,
            'battery_health' => null,
            'cycle_count' => null,
            'condition_grade' => null,
            'box_kit' => null,
            'expected_selling_price' => null,
            'status_note' => null,
            'adapter_name' => null,
            'adapter_price' => null,
            'cable_name' => null,
            'cable_price' => null,
            'allow_legacy' => false,
            'flag_reason' => null,
            'generate_internal_serial' => false,
            'notes' => '',
        ];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);

        if ($this->lines === []) {
            $this->addPhoneLine();
        }
    }

    public function save(PurchaseService $purchases): void
    {
        $this->authorize('create', Purchase::class);

        $user = auth()->user();
        $accessible = $user->accessibleUnitIds();

        $this->supplierId = $this->supplierId === '' ? null : $this->supplierId;
        $this->buyingParty = $this->buyingParty === '' ? null : $this->buyingParty;

        foreach ($this->lines as $index => $line) {
            foreach ([
                'imei_1', 'imei_2', 'battery_health', 'cycle_count', 'condition_grade', 'box_kit',
                'expected_selling_price', 'status_note', 'adapter_name', 'adapter_price',
                'cable_name', 'cable_price', 'flag_reason', 'notes',
            ] as $field) {
                if (($line[$field] ?? null) === '') {
                    $this->lines[$index][$field] = null;
                }
            }
        }

        $validated = $this->validate([
            'unitId' => ['required', 'integer', Rule::in($accessible)],
            'supplierId' => ['nullable', 'integer', 'exists:suppliers,id'],
            'buyingParty' => ['nullable', 'string', 'max:180'],
            'purchaseDate' => ['required', 'date'],
            'discountTotal' => ['required', 'numeric', 'min:0'],
            'otherCharges' => ['required', 'numeric', 'min:0'],
            'paidAmount' => ['required', 'numeric', 'min:0'],
            'paymentMethod' => ['required', 'in:CASH,BANK_TRANSFER,CARD,BKASH,NAGAD,OTHER'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.line_type' => ['required', 'in:PHONE,ACCESSORY'],
            'lines.*.product_variant_id' => ['required', 'integer', 'exists:product_variants,id'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'lines.*.unit_cost' => ['required', 'numeric', 'min:0'],
            'lines.*.imei_1' => ['nullable', 'string', 'max:32'],
            'lines.*.imei_2' => ['nullable', 'string', 'max:32'],
            'lines.*.battery_health' => ['nullable', 'integer', 'between:0,100'],
            'lines.*.cycle_count' => ['nullable', 'integer', 'between:0,100000'],
            'lines.*.condition_grade' => ['nullable', 'string', 'max:20'],
            'lines.*.box_kit' => ['nullable', 'string', 'max:60'],
            'lines.*.expected_selling_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.status_note' => ['nullable', 'string', 'max:255'],
            'lines.*.adapter_name' => ['nullable', 'string', 'max:120'],
            'lines.*.adapter_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.cable_name' => ['nullable', 'string', 'max:120'],
            'lines.*.cable_price' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.flag_reason' => ['nullable', 'string', 'max:255'],
            'lines.*.allow_legacy' => ['boolean'],
            'lines.*.generate_internal_serial' => ['boolean'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        $seenImeis = [];

        foreach ($validated['lines'] as $index => $line) {
            if ($line['line_type'] === 'PHONE') {
                if ((int) $line['quantity'] !== 1) {
                    $this->addError("lines.{$index}.quantity", 'One line per IMEI: set quantity to 1.');

                    return;
                }

                $imei1 = trim((string) ($line['imei_1'] ?? ''));
                $imei2 = trim((string) ($line['imei_2'] ?? ''));

                if ($imei1 === '' && $imei2 === '' && ! ($line['generate_internal_serial'] ?? false)) {
                    $this->addError("lines.{$index}.imei_1", 'Enter an IMEI or confirm an internal serial.');

                    return;
                }

                if ($imei1 !== '' && $imei1 === $imei2) {
                    $this->addError("lines.{$index}.imei_2", 'IMEI 2 must differ from IMEI 1.');

                    return;
                }

                foreach (array_filter([$imei1, $imei2]) as $imei) {
                    if (isset($seenImeis[$imei])) {
                        $this->addError("lines.{$index}.imei_1", "IMEI {$imei} is repeated on another line in this purchase.");

                        return;
                    }

                    $seenImeis[$imei] = true;
                }
            }
        }

        $payload = [];

        foreach ($validated['lines'] as $line) {
            $payload[] = [
                'product_variant_id' => (int) $line['product_variant_id'],
                'line_type' => $line['line_type'],
                'quantity' => (int) $line['quantity'],
                'unit_cost' => (string) $line['unit_cost'],
                'imei_1' => $line['line_type'] === 'PHONE' ? ($line['imei_1'] ?: null) : null,
                'imei_2' => $line['line_type'] === 'PHONE' ? ($line['imei_2'] ?: null) : null,
                'expected_selling_price' => $line['line_type'] === 'PHONE' && $line['expected_selling_price'] !== null && $line['expected_selling_price'] !== ''
                    ? (string) $line['expected_selling_price']
                    : null,
                'status_note' => $line['line_type'] === 'PHONE' ? ($line['status_note'] ?: null) : null,
                'serial_payload' => $line['line_type'] === 'PHONE' ? [
                    'battery_health' => $line['battery_health'] === '' ? null : (int) $line['battery_health'],
                    'cycle_count' => $line['cycle_count'] === '' ? null : (int) $line['cycle_count'],
                    'condition_grade' => $line['condition_grade'] ?: null,
                    'box_kit' => $line['box_kit'] ?: null,
                    'adapter_name' => $line['adapter_name'] ?: null,
                    'adapter_price' => $line['adapter_price'] ?: null,
                    'cable_name' => $line['cable_name'] ?: null,
                    'cable_price' => $line['cable_price'] ?: null,
                    'allow_legacy' => (bool) ($line['allow_legacy'] ?? false),
                    'flag_reason' => $line['flag_reason'] ?: null,
                    'generate_internal_serial' => (bool) ($line['generate_internal_serial'] ?? false),
                ] : null,
                'notes' => $line['notes'] ?: null,
            ];
        }

        try {
            $purchase = $purchases->create([
                'supplier_id' => ! empty($validated['supplierId']) ? (int) $validated['supplierId'] : null,
                'buying_party_name' => $validated['buyingParty'] ?: null,
                'business_unit_id' => (int) $validated['unitId'],
                'purchase_date' => $validated['purchaseDate'],
                'discount_total' => (string) $validated['discountTotal'],
                'other_charges' => (string) $validated['otherCharges'],
                'paid_amount' => (string) $validated['paidAmount'],
                'payment_method' => $validated['paymentMethod'],
                'reference' => $validated['reference'] ?: null,
                'notes' => $validated['notes'] ?: null,
            ], $payload, $user);

            $received = false;

            if ($this->receiveNow) {
                if (! $user->can('purchase.approve')) {
                    session()->flash('error', "Purchase {$purchase->document_no} saved as a draft. You do not have permission to receive stock.");
                } else {
                    $purchases->receive($purchase, $user);
                    $received = true;
                }
            }

            session()->flash('success', $received
                ? "Purchase {$purchase->document_no} received: IMEIs registered and stock updated."
                : "Purchase {$purchase->document_no} saved as a draft. Stock is unchanged until received.");

            $this->redirect(route('purchases.show', $purchase), navigate: true);
        } catch (ErpException $exception) {
            foreach ($exception->errors() as $field => $message) {
                $this->addError($field, $message);
            }

            $this->addError('form', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $variants = ProductVariant::query()->with('product.brand')->get();

        $phoneVariants = $variants->filter(fn (ProductVariant $variant) => $variant->product?->product_type === ProductType::Phone)
            ->sortBy(fn (ProductVariant $variant) => $variant->displayName())
            ->values();

        $accessoryVariants = $variants->filter(fn (ProductVariant $variant) => $variant->product?->product_type === ProductType::Accessory)
            ->sortBy(fn (ProductVariant $variant) => $variant->displayName())
            ->values();

        $subtotal = Money::zero();

        foreach ($this->lines as $line) {
            $subtotal = $subtotal->add(Money::of((string) ($line['unit_cost'] ?: '0'))->mul(max(1, (int) $line['quantity'])));
        }

        $total = $subtotal->sub(Money::of($this->discountTotal ?: '0'))->add(Money::of($this->otherCharges ?: '0'));

        return view('livewire.purchases.form', [
            'phoneVariants' => $phoneVariants,
            'accessoryVariants' => $accessoryVariants,
            'suppliers' => Supplier::query()->active()->orderBy('name')->get(),
            'units' => BusinessUnit::query()->whereIn('id', auth()->user()->accessibleUnitIds())->active()->ordered()->get(),
            'subtotal' => $subtotal,
            'total' => $total,
            'balance' => $total->sub(Money::of($this->paidAmount ?: '0')),
            'canReceive' => auth()->user()->can('purchase.approve'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
