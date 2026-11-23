<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Inventory\InventoryCycleService;
use App\Domain\Inventory\StockStateMachine;
use App\Enums\AccessoryMovementReason;
use App\Enums\Custody;
use App\Enums\CustomerType;
use App\Enums\CycleCloseReason;
use App\Enums\InventoryEventType;
use App\Enums\LineType;
use App\Enums\PaymentMethod;
use App\Enums\SaleStatus;
use App\Enums\SaleType;
use App\Enums\Sellability;
use App\Exceptions\DomainRuleViolationException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidStockTransitionException;
use App\Exceptions\UnitAccessDeniedException;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Exchange;
use App\Models\ExternalDelivery;
use App\Models\InventoryCycle;
use App\Models\JournalEntry;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Posts a POS sale atomically: invoice, line cost snapshots, tender rows,
 * handset custody/sale event, acquisition-cycle close, accessory stock issue
 * and a balanced double-entry journal. Any failure rolls the whole sale back.
 */
final class SaleService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly ChartOfAccounts $accounts,
        private readonly JournalPostingService $journal,
        private readonly StockStateMachine $states,
        private readonly InventoryCycleService $cycles,
        private readonly AccessoryStockService $accessoryStock,
    ) {}

    /**
     * @param  array{
     *     business_unit_id: int,
     *     customer_id?: ?int,
     *     items: list<array{line_type: string, serialized_unit_id?: int, product_variant_id?: int, quantity?: int, unit_price?: string|int|float|null, discount?: string|int|float|null}>,
     *     payments?: list<array{method: string, amount: string|int|float, reference?: ?string}>,
     *     discount_total?: string|int|float|null,
     *     notes?: ?string,
     *     idempotency_key?: ?string
     * } $data
     */
    public function post(array $data, User $actor, ?Exchange $exchange = null, ?ExternalDelivery $delivery = null): Sale
    {
        if (! $actor->can('pos.sell')) {
            throw DomainRuleViolationException::message(
                'You do not have permission to post sales.',
                ['sale' => 'Missing permission: pos.sell'],
            );
        }

        $unit = BusinessUnit::query()->find((int) ($data['business_unit_id'] ?? 0));

        if ($unit === null || ! $unit->is_active) {
            throw DomainRuleViolationException::message(
                'The selected business unit is not available.',
                ['business_unit_id' => 'Inactive or unknown unit.'],
            );
        }

        if (! $actor->hasUnitAccess($unit->getKey())) {
            throw UnitAccessDeniedException::forUnit($unit->getKey());
        }

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = Sale::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing->load(['items', 'payments', 'customer', 'businessUnit']);
            }
        }

        return DB::transaction(function () use ($data, $actor, $unit, $idempotencyKey, $exchange, $delivery): Sale {
            $customer = ! empty($data['customer_id'])
                ? Customer::query()->find((int) $data['customer_id'])
                : null;

            if (! empty($data['customer_id']) && $customer === null) {
                throw DomainRuleViolationException::message(
                    'The selected customer does not exist.',
                    ['customer_id' => 'Unknown customer.'],
                );
            }

            if ($customer !== null && ! $customer->is_active) {
                throw DomainRuleViolationException::message(
                    'The selected customer is inactive.',
                    ['customer_id' => 'Inactive customer.'],
                );
            }

            $items = $data['items'] ?? [];

            if (! is_array($items) || $items === []) {
                throw DomainRuleViolationException::message(
                    'Add at least one handset or accessory to the sale.',
                    ['items' => 'The sale is empty.'],
                );
            }

            $phoneItems = array_values(array_filter($items, fn ($item) => ($item['line_type'] ?? '') === LineType::Phone->value));
            $accessoryItems = array_values(array_filter($items, fn ($item) => ($item['line_type'] ?? '') === LineType::Accessory->value));

            if (count($phoneItems) + count($accessoryItems) !== count($items)) {
                throw DomainRuleViolationException::message(
                    'A sale line has an invalid line type.',
                    ['items' => 'Expected PHONE or ACCESSORY.'],
                );
            }

            $seenUnits = [];

            foreach ($phoneItems as $item) {
                $unitId = (int) ($item['serialized_unit_id'] ?? 0);

                if ($unitId <= 0 || isset($seenUnits[$unitId])) {
                    throw DomainRuleViolationException::message(
                        'A handset is selected more than once, or is missing.',
                        ['items' => 'Each IMEI can appear only once per invoice.'],
                    );
                }

                $seenUnits[$unitId] = true;
            }

            $lockedUnits = $this->lockUnits($phoneItems, $unit);
            $saleLines = [];
            $subtotal = Money::zero();
            $lineDiscountTotal = Money::zero();
            $handsetCostTotal = Money::zero();

            foreach ($phoneItems as $index => $item) {
                $serializedId = (int) $item['serialized_unit_id'];
                /** @var SerializedUnit $serialized */
                $serialized = $lockedUnits->get($serializedId);

                $this->assertSellableHandset($serialized, $unit, $delivery);

                /** @var InventoryCycle $cycle */
                $cycle = $serialized->activeCycle;
                $variant = $serialized->variant;
                $unitPrice = $this->resolvePrice(
                    $item['unit_price'] ?? null,
                    $serialized->expected_selling_price,
                    $variant?->default_selling_price,
                    'handset',
                    $index,
                );
                $discount = $this->resolveDiscount($item['discount'] ?? null, $unitPrice, $index);
                $lineTotal = $unitPrice->sub($discount);
                $cost = Money::of((string) $cycle->effective_cost_basis);

                $saleLines[] = [
                    'line_type' => LineType::Phone,
                    'product_variant_id' => $serialized->product_variant_id,
                    'serialized_unit_id' => $serialized->getKey(),
                    'inventory_cycle_id' => $cycle->getKey(),
                    'description' => $variant?->displayName() ?? 'Handset',
                    'identifier' => $serialized->primary_imei ?? $serialized->secondary_imei,
                    'quantity' => 1,
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'line_total' => $lineTotal,
                    'unit_cost' => $cost,
                    'cost_total' => $cost,
                    'serialized' => $serialized,
                    'cycle' => $cycle,
                ];

                $subtotal = $subtotal->add($lineTotal);
                $lineDiscountTotal = $lineDiscountTotal->add($discount);
                $handsetCostTotal = $handsetCostTotal->add($cost);
            }

            $aggregatedAccessories = $this->aggregateAccessories($accessoryItems);
            $accessoryCostTotal = Money::zero();

            foreach ($aggregatedAccessories as $index => $line) {
                /** @var ProductVariant $variant */
                $variant = $line['variant'];
                $balance = $this->accessoryStock->balanceFor($unit->getKey(), $variant->getKey());

                if ((int) $balance->qty_on_hand - (int) $balance->qty_reserved < $line['quantity']) {
                    throw InsufficientStockException::accessory(
                        $variant->displayName(),
                        $line['quantity'],
                        (int) $balance->qty_on_hand - (int) $balance->qty_reserved,
                    );
                }

                $unitPrice = $this->resolvePrice(
                    $line['unit_price'] ?? null,
                    null,
                    $variant->default_selling_price,
                    'accessory',
                    $index,
                );
                $discount = $this->resolveDiscount($line['discount'] ?? null, $unitPrice->mul($line['quantity']), $index);
                $lineTotal = $unitPrice->mul($line['quantity'])->sub($discount);
                $cost = Money::of((string) $balance->average_cost);

                $saleLines[] = [
                    'line_type' => LineType::Accessory,
                    'product_variant_id' => $variant->getKey(),
                    'serialized_unit_id' => null,
                    'inventory_cycle_id' => null,
                    'description' => $variant->displayName(),
                    'identifier' => null,
                    'quantity' => $line['quantity'],
                    'unit_price' => $unitPrice,
                    'discount' => $discount,
                    'line_total' => $lineTotal,
                    'unit_cost' => $cost,
                    'cost_total' => $cost->mul($line['quantity']),
                    'serialized' => null,
                    'cycle' => null,
                ];

                $subtotal = $subtotal->add($lineTotal);
                $lineDiscountTotal = $lineDiscountTotal->add($discount);
                $accessoryCostTotal = $accessoryCostTotal->add($cost->mul($line['quantity']));
            }

            $invoiceDiscount = Money::of($data['discount_total'] ?? '0');

            if ($invoiceDiscount->isNegative() || $invoiceDiscount->greaterThan($subtotal)) {
                throw DomainRuleViolationException::message(
                    'Invoice discount must be between zero and the subtotal.',
                    ['discount_total' => 'Invalid invoice discount.'],
                );
            }

            $grandTotal = $subtotal->sub($invoiceDiscount);
            $emiRate = (string) ($data['emi_rate'] ?? '0');
            Validator::make(['emi_rate' => $emiRate], ['emi_rate' => ['numeric', 'decimal:0,2', 'between:0,100']])->validate();
            $emiCharge = Money::zero();
            if (Money::of($emiRate)->isPositive()) {
                if (($data['repayment_plan']['type'] ?? '') !== 'INSTALLMENT' || $exchange !== null) {
                    throw DomainRuleViolationException::message('EMI percentage requires a monthly installment plan.', ['emi_rate' => 'Choose installments.']);
                }
                $downPayment = Money::zero();
                foreach ($data['payments'] ?? [] as $tender) {
                    $downPayment = $downPayment->add(Money::of($tender['amount'] ?? '0'));
                }
                $financed = $grandTotal->sub($downPayment);
                if ($financed->isPositive()) {
                    $emiCharge = Money::of(bcdiv(bcmul($financed->amount, $emiRate, 8), '100', 8));
                }
                $grandTotal = $grandTotal->add($emiCharge);
            }

            $tradeInCredit = Money::zero();
            if ($exchange !== null) {
                if (! $actor->can('exchange.create') || ! $exchange->exists || $exchange->business_unit_id !== $unit->id || $exchange->customer_id !== $customer?->id || $exchange->sale_id !== null) {
                    throw DomainRuleViolationException::message('Invalid exchange for this sale.', ['sale' => 'Exchange does not match this customer / branch.']);
                }
                $tradeInCredit = Money::of($exchange->buying_price)->min($grandTotal);
            }
            $depositCredit = Money::zero();
            if ($delivery) {
                if ($exchange || $delivery->status !== 'OUT' || $delivery->business_unit_id !== $unit->id || $delivery->customer_id !== $customer?->id || count($items) !== 1 || (int) ($items[0]['serialized_unit_id'] ?? 0) !== $delivery->serialized_unit_id) {
                    throw DomainRuleViolationException::message('Invalid external delivery sale.', ['sale' => 'Delivery does not match this invoice.']);
                }
                $depositCredit = Money::of($delivery->deposit_amount);
                if ($depositCredit->greaterThan($grandTotal)) {
                    throw DomainRuleViolationException::message('Sale price cannot be less than its deposit. Return and refund first.', ['sale' => 'Deposit exceeds final price.']);
                }
            }
            [$payments, $cashPaid] = $this->preparePayments($data['payments'] ?? [], $grandTotal->sub($tradeInCredit)->sub($depositCredit), $unit);
            $paidTotal = $cashPaid->add($tradeInCredit)->add($depositCredit);

            $dueTotal = $grandTotal->sub($paidTotal);

            if ($dueTotal->isPositive() && $customer === null) {
                throw DomainRuleViolationException::message(
                    'A registered customer is required for a credit/due sale.',
                    ['customer_id' => 'Select a customer or collect the full amount.'],
                );
            }

            if ($dueTotal->isPositive() && $customer !== null) {
                $this->assertCreditAllowed($customer, $dueTotal);
            }

            $sale = Sale::create([
                'invoice_no' => $this->numbers->next('sale'),
                'business_unit_id' => $unit->getKey(),
                'customer_id' => $customer?->getKey(),
                'type' => $customer?->type === CustomerType::Shop ? SaleType::Shop : SaleType::Retail,
                'status' => SaleStatus::Posted,
                'subtotal' => $subtotal->amount,
                'line_discount_total' => $lineDiscountTotal->amount,
                'invoice_discount_total' => $invoiceDiscount->amount,
                'discount_total' => $lineDiscountTotal->add($invoiceDiscount)->amount,
                'tax_total' => '0.00',
                'grand_total' => $grandTotal->amount,
                'emi_rate' => $emiRate, 'emi_charge' => $emiCharge->amount,
                'deposit_credit' => $depositCredit->amount,
                'trade_in_credit' => $tradeInCredit->amount,
                'paid_total' => $paidTotal->amount,
                'due_total' => $dueTotal->amount,
                'notes' => $data['notes'] ?? null,
                'posted_at' => BusinessClock::utcNow(),
                'created_by' => $actor->getKey(),
                'idempotency_key' => $idempotencyKey,
            ]);

            foreach ($saleLines as $line) {
                $sale->items()->create([
                    'line_type' => $line['line_type'],
                    'product_variant_id' => $line['product_variant_id'],
                    'serialized_unit_id' => $line['serialized_unit_id'],
                    'inventory_cycle_id' => $line['inventory_cycle_id'],
                    'description' => $line['description'],
                    'details' => $line['serialized'] ? [
                        'battery_health' => $line['serialized']->battery_health,
                        'cycle_count' => $line['serialized']->cycle_count,
                        'condition_grade' => $line['serialized']->condition_grade,
                        'category' => $line['serialized']->inventory_category,
                        'box_kit' => $line['serialized']->box_kit,
                        'secondary_imei' => $line['serialized']->secondary_imei,
                    ] : ['sku' => $line['identifier']],
                    'identifier' => $line['identifier'],
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price']->amount,
                    'discount' => $line['discount']->amount,
                    'line_total' => $line['line_total']->amount,
                    'unit_cost' => $line['unit_cost']->amount,
                    'cost_total' => $line['cost_total']->amount,
                ]);
            }

            foreach ($saleLines as $line) {
                if ($line['line_type'] === LineType::Phone) {
                    /** @var SerializedUnit $serialized */
                    $serialized = $line['serialized'];
                    /** @var InventoryCycle $cycle */
                    $cycle = $line['cycle'];

                    $this->states->transition(
                        unit: $serialized,
                        toCustody: Custody::WithCustomer,
                        toUnitId: null,
                        eventType: InventoryEventType::Sold,
                        actor: $actor,
                        toSellability: Sellability::Sold,
                        note: "Sold on invoice {$sale->invoice_no}",
                        reference: $sale,
                        metadata: ['invoice_no' => $sale->invoice_no, 'price' => $line['line_total']->amount],
                        idempotencyKey: "sale:{$sale->getKey()}:unit:{$serialized->getKey()}",
                        cycle: $cycle,
                    );

                    $this->cycles->close($cycle, CycleCloseReason::Sold, $actor, null, "Sold on invoice {$sale->invoice_no}");
                } else {
                    $this->accessoryStock->issue(
                        businessUnitId: $unit->getKey(),
                        variantId: $line['product_variant_id'],
                        quantity: $line['quantity'],
                        reason: AccessoryMovementReason::Sale,
                        actor: $actor,
                        source: $sale,
                        note: "Sold on invoice {$sale->invoice_no}",
                    );
                }
            }

            foreach ($payments as $payment) {
                $sale->payments()->create([
                    'account_id' => $payment['account_id'],
                    'method' => $payment['method'],
                    'amount' => $payment['amount']->amount,
                    'reference' => $payment['reference'],
                    'received_by' => $actor->getKey(),
                    'received_at' => BusinessClock::utcNow(),
                ]);
            }

            $journal = $this->postSaleJournal(
                sale: $sale,
                payments: $payments,
                dueTotal: $dueTotal,
                grandTotal: $grandTotal,
                handsetCostTotal: $handsetCostTotal,
                accessoryCostTotal: $accessoryCostTotal,
                tradeInCredit: $tradeInCredit,
                emiCharge: $emiCharge,
                depositCredit: $depositCredit,
                actor: $actor,
            );

            $sale->forceFill(['journal_entry_id' => $journal->getKey()])->save();
            if (isset($data['repayment_plan'])) {
                app(PaymentScheduleService::class)->create($sale, $data['repayment_plan'], $actor);
            }

            AuditLogger::record(
                'sale.posted',
                $sale,
                [],
                $sale->only(['invoice_no', 'grand_total', 'paid_total', 'due_total', 'customer_id']),
                null,
                $unit->getKey(),
                $actor,
            );

            return $sale->load(['items', 'payments', 'customer', 'businessUnit', 'journalEntry.lines']);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $phoneItems
     * @return Collection<int, SerializedUnit>
     */
    private function lockUnits(array $phoneItems, BusinessUnit $unit): Collection
    {
        $ids = array_map(fn (array $item) => (int) $item['serialized_unit_id'], $phoneItems);
        sort($ids);

        $units = SerializedUnit::query()
            ->with(['variant.product', 'activeCycle'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($ids as $id) {
            if (! $units->has($id)) {
                throw DomainRuleViolationException::message(
                    "Handset #{$id} does not exist.",
                    ['items' => "Unknown handset #{$id}."],
                );
            }
        }

        return $units;
    }

    private function assertSellableHandset(?SerializedUnit $serialized, BusinessUnit $unit, ?ExternalDelivery $delivery = null): void
    {
        if ($serialized === null) {
            throw DomainRuleViolationException::message(
                'A selected handset no longer exists.',
                ['items' => 'Unknown handset.'],
            );
        }

        $externalSale = $delivery && $delivery->serialized_unit_id === $serialized->id && $delivery->business_unit_id === $unit->id && $serialized->current_custody === Custody::AtExternalParty;
        if (! $externalSale && (
            (int) $serialized->current_unit_id !== (int) $unit->getKey()
            || $serialized->current_custody !== Custody::InUnit
            || $serialized->sellability_status !== Sellability::Available
        )) {
            throw new InvalidStockTransitionException(
                "Handset {$serialized->primaryIdentifier()} is not available in {$unit->name}. It may have been sold, transferred or reserved.",
                409,
                ['items' => 'Handset is no longer sellable in this unit.'],
            );
        }

        if (! $serialized->activeCycle()->exists()) {
            throw DomainRuleViolationException::message(
                "Handset {$serialized->primaryIdentifier()} has no open acquisition cycle.",
                ['items' => 'Cost basis missing; cannot sell.'],
            );
        }
    }

    private function resolvePrice(
        string|int|float|null $provided,
        string|int|float|null $perUnit,
        string|int|float|null $catalog,
        string $kind,
        int $index,
    ): Money {
        $price = $provided !== null && $provided !== ''
            ? Money::of($provided)
            : Money::of($perUnit ?? $catalog ?? '');

        if ($price->isNegative()) {
            throw DomainRuleViolationException::message(
                'Selling price cannot be negative.',
                ["items.{$index}.unit_price" => 'Enter zero or a positive amount.'],
            );
        }

        if (($perUnit === null && $catalog === null) && ($provided === null || $provided === '')) {
            throw DomainRuleViolationException::message(
                "The {$kind} has no selling price. Set a price before selling.",
                ["items.{$index}.unit_price" => 'Selling price is required.'],
            );
        }

        return $price;
    }

    private function resolveDiscount(string|int|float|null $provided, Money $base, int $index): Money
    {
        $discount = Money::of($provided ?? '0');

        if ($discount->isNegative() || $discount->greaterThan($base)) {
            throw DomainRuleViolationException::message(
                'Line discount must be between zero and the line amount.',
                ["items.{$index}.discount" => 'Invalid discount.'],
            );
        }

        return $discount;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return list<array{variant: ProductVariant, quantity: int, unit_price: mixed, discount: mixed}>
     */
    private function aggregateAccessories(array $items): array
    {
        $grouped = [];

        foreach ($items as $item) {
            $variantId = (int) ($item['product_variant_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);

            if ($variantId <= 0 || $quantity < 1) {
                throw DomainRuleViolationException::message(
                    'An accessory line has an invalid variant or quantity.',
                    ['items' => 'Quantity must be at least one.'],
                );
            }

            if (isset($grouped[$variantId])) {
                $grouped[$variantId]['quantity'] += $quantity;

                continue;
            }

            $variant = ProductVariant::query()->with('product')->find($variantId);

            if ($variant === null) {
                throw DomainRuleViolationException::message(
                    'An accessory references an unknown variant.',
                    ['items' => 'Unknown accessory.'],
                );
            }

            $grouped[$variantId] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'unit_price' => $item['unit_price'] ?? null,
                'discount' => $item['discount'] ?? null,
            ];
        }

        return array_values($grouped);
    }

    /**
     * @param  list<array{method: string, amount: string|int|float, reference?: ?string}>  $payments
     * @return array{0: list<array{account_id: int, method: PaymentMethod, amount: Money, reference: ?string}>, 1: Money}
     */
    private function preparePayments(array $payments, Money $grandTotal, BusinessUnit $unit): array
    {
        $prepared = [];
        $paid = Money::zero();

        foreach ($payments as $index => $payment) {
            $method = PaymentMethod::tryFrom(strtoupper((string) ($payment['method'] ?? '')));

            if ($method === null) {
                throw DomainRuleViolationException::message(
                    'A payment method is invalid.',
                    ["payments.{$index}.method" => 'Unsupported payment method.'],
                );
            }

            $amount = Money::of($payment['amount'] ?? '0');

            if (! $amount->isPositive()) {
                throw DomainRuleViolationException::message(
                    'Payment amounts must be greater than zero.',
                    ["payments.{$index}.amount" => 'Enter an amount above zero.'],
                );
            }

            $prepared[] = [
                'account_id' => $this->accounts->paymentAccount($unit, $method)->getKey(),
                'method' => $method,
                'amount' => $amount,
                'reference' => $payment['reference'] ?? null,
            ];

            $paid = $paid->add($amount);
        }

        if ($paid->greaterThan($grandTotal)) {
            throw DomainRuleViolationException::message(
                'Payments exceed the invoice total. Reduce the payment or handle change explicitly.',
                ['payments' => 'Total tendered is greater than the amount due.'],
            );
        }

        return [$prepared, $paid];
    }

    private function assertCreditAllowed(Customer $customer, Money $newDue): void
    {
        $outstanding = $customer->outstandingBalance();

        if ($customer->type === CustomerType::Shop) {
            if (Money::of((string) $customer->credit_limit)->isZero()) {
                throw DomainRuleViolationException::message(
                    'This shop has no credit limit configured. Collect the full amount or set a credit limit.',
                    ['customer_id' => 'Credit limit is zero.'],
                );
            }

            if ($outstanding->add($newDue)->greaterThan((string) $customer->credit_limit)) {
                throw DomainRuleViolationException::message(
                    'Credit limit exceeded. Outstanding '.config('erp.currency.symbol').$outstanding->formatted().
                    ', new due '.config('erp.currency.symbol').$newDue->formatted().
                    ', limit '.config('erp.currency.symbol').Money::of((string) $customer->credit_limit)->formatted().'.',
                    ['customer_id' => 'Credit limit exceeded.'],
                );
            }

            return;
        }

        if (! Money::of((string) $customer->credit_limit)->isZero() && $outstanding->add($newDue)->greaterThan((string) $customer->credit_limit)) {
            throw DomainRuleViolationException::message(
                'Customer credit limit exceeded.',
                ['customer_id' => 'Credit limit exceeded.'],
            );
        }
    }

    /**
     * @param  list<array{account_id: int, method: PaymentMethod, amount: Money, reference: ?string}>  $payments
     */
    private function postSaleJournal(
        Sale $sale,
        array $payments,
        Money $dueTotal,
        Money $grandTotal,
        Money $handsetCostTotal,
        Money $accessoryCostTotal,
        Money $tradeInCredit,
        Money $emiCharge,
        Money $depositCredit,
        User $actor,
    ): JournalEntry {
        $lines = [];
        if ($tradeInCredit->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->account('2100')->id, 'debit' => $tradeInCredit->amount, 'memo' => 'Trade-in credit '.$sale->invoice_no];
        }

        if ($depositCredit->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->account('2400')->id, 'debit' => $depositCredit->amount, 'memo' => 'Deposit applied '.$sale->invoice_no];
        }

        $byAccount = [];
        foreach ($payments as $payment) {
            $byAccount[$payment['account_id']] = ($byAccount[$payment['account_id']] ?? Money::zero())->add($payment['amount']);
        }

        foreach ($byAccount as $accountId => $amount) {
            $lines[] = ['account_id' => $accountId, 'debit' => $amount->amount, 'memo' => 'Sale receipt '.$sale->invoice_no];
        }

        if ($dueTotal->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->receivable()->getKey(), 'debit' => $dueTotal->amount, 'memo' => 'Due '.$sale->invoice_no];
        }

        if ($grandTotal->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->salesRevenue()->getKey(), 'credit' => $grandTotal->sub($emiCharge)->amount, 'memo' => 'Sales revenue '.$sale->invoice_no];
        }

        if ($emiCharge->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->account('4100')->id, 'credit' => $emiCharge->amount, 'memo' => 'EMI charge '.$sale->invoice_no];
        }

        if ($handsetCostTotal->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->handsetCogs()->getKey(), 'debit' => $handsetCostTotal->amount, 'memo' => 'Handset COGS '.$sale->invoice_no];
            $lines[] = ['account_id' => $this->accounts->handsetInventory()->getKey(), 'credit' => $handsetCostTotal->amount, 'memo' => 'Handset inventory out '.$sale->invoice_no];
        }

        if ($accessoryCostTotal->isPositive()) {
            $lines[] = ['account_id' => $this->accounts->accessoryCogs()->getKey(), 'debit' => $accessoryCostTotal->amount, 'memo' => 'Accessory COGS '.$sale->invoice_no];
            $lines[] = ['account_id' => $this->accounts->accessoryInventory()->getKey(), 'credit' => $accessoryCostTotal->amount, 'memo' => 'Accessory inventory out '.$sale->invoice_no];
        }

        $entry = $this->journal->post(
            lines: $lines,
            memo: 'Sale '.$sale->invoice_no,
            entryDate: BusinessClock::today(),
            sourceKey: 'sale:'.$sale->getKey(),
            source: $sale,
            actor: $actor,
        );

        return $entry;
    }
}
