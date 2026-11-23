<?php

declare(strict_types=1);

namespace App\Domain\Exchanges;

use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\JournalPostingService;
use App\Domain\Inventory\AccessoryStockService;
use App\Domain\Inventory\ImeiNormalizer;
use App\Domain\Inventory\ManualIntakeService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Sales\SaleService;
use App\Enums\AccessoryMovementReason;
use App\Enums\AcquisitionSource;
use App\Enums\PaymentMethod;
use App\Enums\ProductType;
use App\Models\Brand;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\Exchange;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessClock;
use App\Support\DocumentNumberService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ExchangeService
{
    public static function incomingRules(): array
    {
        return [
            'kind' => ['required', 'in:PHONE,ACCESSORY'],
            'primaryImei' => ['nullable', 'string', 'max:32'],
            'secondaryImei' => ['nullable', 'string', 'max:32'],
            'brandName' => ['required_if:kind,PHONE', 'nullable', 'string', 'max:100'],
            'modelName' => ['required_if:kind,PHONE', 'nullable', 'string', 'max:180'],
            'country' => ['nullable', 'string', 'max:100'],
            'colour' => ['nullable', 'string', 'max:60'],
            'storage' => ['nullable', 'string', 'max:60'],
            'ram' => ['nullable', 'string', 'max:60'],
            'batteryHealth' => ['nullable', 'integer', 'between:0,100'],
            'cycleCount' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'boxKit' => ['nullable', 'string', 'max:60'],
            'conditionGrade' => ['nullable', 'string', 'max:20'],
            'category' => ['required', 'in:PRE_OWNED,NEW'],
            'buyingPrice' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'askingPrice' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'statusNote' => ['nullable', 'string', 'max:255'],
            'productSku' => ['required_if:kind,ACCESSORY', 'nullable', 'string', 'max:60'],
            'productName' => ['required_if:kind,ACCESSORY', 'nullable', 'string', 'max:180'],
            'inStock' => ['accepted'],
        ];
    }

    public function post(array $saleData, array $incoming, array $settlement, User $actor): Sale
    {
        Gate::forUser($actor)->authorize('exchange.create');
        Gate::forUser($actor)->authorize('pos.sell');
        $incoming = array_replace(array_fill_keys(array_keys(self::incomingRules()), null), Validator::make($incoming, self::incomingRules())->validate());

        if ($incoming['kind'] === 'PHONE') {
            $normalizer = app(ImeiNormalizer::class);
            $incoming['primaryImei'] = $normalizer->normalize($incoming['primaryImei'] ?? null);
            $incoming['secondaryImei'] = $normalizer->normalize($incoming['secondaryImei'] ?? null);

            if ($incoming['primaryImei'] === null && $incoming['secondaryImei'] === null) {
                throw ValidationException::withMessages(['primaryImei' => 'Enter IMEI 1 or IMEI 2 for the incoming phone.']);
            }
        }
        $split = null;
        if (array_key_exists('payments', $settlement)) {
            $validatedPayments = Validator::make($settlement, [
                'payments' => ['required_if:direction,CUSTOMER,COUNTER', 'array', 'max:20'],
                'payments.*.method' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value'))],
                'payments.*.amount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01', 'max:999999999'],
                'payments.*.reference' => ['nullable', 'string', 'max:120'],
            ])->validate();
            $split = [];
            $total = Money::zero();
            foreach ($validatedPayments['payments'] as $payment) {
                $amount = Money::of($payment['amount']);
                $total = $total->add($amount);
                $split[] = ['method' => $payment['method'], 'amount' => $amount->amount, 'reference' => ($payment['reference'] ?? '') ?: null];
            }
            $settlement['amount'] = $total->amount;
            $settlement['method'] = count($split) > 1 ? 'SPLIT' : ($split[0]['method'] ?? 'CASH');
        }
        $settlement = Validator::make($settlement, [
            'direction' => ['required', 'in:CUSTOMER,COUNTER,EVEN'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999999'],
            'method' => ['required', 'in:'.implode(',', array_column(PaymentMethod::cases(), 'value')).($split !== null ? ',SPLIT' : '')],
        ])->validate();
        $split ??= Money::of($settlement['amount'])->isPositive()
            ? [['method' => $settlement['method'], 'amount' => Money::of($settlement['amount'])->amount, 'reference' => null]] : [];
        Validator::make($saleData, [
            'business_unit_id' => ['required', 'integer', 'in:'.implode(',', $actor->accessibleUnitIds())],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($saleData, $incoming, $settlement, $split, $actor) {
            $branch = BusinessUnit::active()->whereKey($saleData['business_unit_id'])->lockForUpdate()->firstOrFail();
            $existing = Exchange::where('idempotency_key', $saleData['idempotency_key'])->first();
            if ($existing) {
                abort_unless($existing->business_unit_id === $branch->id && $existing->created_by === $actor->id, 403);

                return $existing->sale()->firstOrFail();
            }
            // The same key must not turn an ordinary posted invoice into an exchange.
            if (Sale::where('idempotency_key', $saleData['idempotency_key'])->exists()) {
                throw ValidationException::withMessages(['exchange' => 'This invoice has already been posted. Start a new sale.']);
            }
            $customer = ! empty($saleData['customer_id']) ? Customer::active()->findOrFail($saleData['customer_id']) : null;
            $exchange = Exchange::create([
                'document_no' => app(DocumentNumberService::class)->next('exchange'),
                'idempotency_key' => $saleData['idempotency_key'], 'business_unit_id' => $branch->id,
                'customer_id' => $customer?->id, 'buying_price' => $incoming['buyingPrice'],
                'description' => Str::limit($incoming['kind'] === 'PHONE' ? $incoming['brandName'].' '.$incoming['modelName'] : $incoming['productName'], 250, ''),
                'imei' => $incoming['primaryImei'] ?: $incoming['secondaryImei'], 'condition_snapshot' => $incoming,
                'settlement_direction' => $settlement['direction'], 'settlement_amount' => $settlement['amount'],
                'settlement_method' => $settlement['method'], 'settlement_payments' => $split, 'created_by' => $actor->id,
            ]);
            $accounts = app(ChartOfAccounts::class);
            if ($incoming['kind'] === 'PHONE') {
                $identity = app(SerialIdentityService::class);
                $previous = $identity->findByImei($incoming['primaryImei']) ?? $identity->findByImei($incoming['secondaryImei'] ?? null);
                if ($previous) {
                    Gate::forUser($actor)->authorize('update', $previous);
                    $normalizer = app(ImeiNormalizer::class);
                    $samePrimary = $normalizer->normalize($previous->primary_imei) === $incoming['primaryImei'];
                    $sameSecondary = $normalizer->normalize($previous->secondary_imei) === $incoming['secondaryImei'];

                    if (! $samePrimary || ! $sameSecondary) {
                        throw ValidationException::withMessages(['primaryImei' => 'Keep both original IMEIs when receiving a previously registered phone.']);
                    }
                }
                $variant = $this->phoneVariant($incoming, $actor, $previous?->variant);
                $phone = app(ManualIntakeService::class)->addPhone(
                    variant: $variant,
                    identity: ['primary_imei' => $incoming['primaryImei'], 'secondary_imei' => $incoming['secondaryImei'] ?: null],
                    attributes: [
                        'battery_health' => filled($incoming['batteryHealth']) ? (int) $incoming['batteryHealth'] : null,
                        'cycle_count' => filled($incoming['cycleCount']) ? (int) $incoming['cycleCount'] : null,
                        'condition_grade' => $incoming['conditionGrade'] ?: null, 'box_kit' => $incoming['boxKit'] ?: null,
                        'expected_selling_price' => $incoming['askingPrice'] ?: null,
                        'notes' => $incoming['notes'] ?: null, 'status_note' => $incoming['statusNote'] ?: null,
                    ],
                    businessUnit: $branch, acquisitionCost: (string) $incoming['buyingPrice'], actor: $actor,
                    acquiredAt: BusinessClock::now(), note: 'Exchange '.$exchange->document_no,
                    buyingParty: $customer?->name ?? 'Walk-in customer', existingUnit: $previous,
                    source: AcquisitionSource::TradeIn, sourceModel: $exchange,
                );
                $phone->forceFill(['inventory_category' => $incoming['category']])->save();
                $exchange->update(['serialized_unit_id' => $phone->id, 'product_variant_id' => $variant->id, 'description' => Str::limit($variant->displayName(), 250, '')]);
                $inventoryAccount = $accounts->handsetInventory();
                foreach ($saleData['items'] ?? [] as $item) {
                    if (($item['serialized_unit_id'] ?? null) === $phone->id) {
                        throw ValidationException::withMessages(['exchange' => 'The incoming and outgoing phone must be different.']);
                    }
                }
            } else {
                $variant = ProductVariant::with('product')->where('sku', trim($incoming['productSku']))->first();
                if ($variant && ($variant->product->product_type !== ProductType::Accessory || ! $variant->is_active || ! $variant->product->is_active)) {
                    throw ValidationException::withMessages(['productSku' => 'Choose an active accessory SKU.']);
                }
                if (! $variant) {
                    Gate::forUser($actor)->authorize('catalog.manage');
                    $product = Product::create(['name' => trim($incoming['productName']), 'product_type' => ProductType::Accessory, 'is_active' => true]);
                    $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => trim($incoming['productSku']), 'default_purchase_price' => $incoming['buyingPrice'], 'default_selling_price' => $incoming['askingPrice'] ?: null, 'is_active' => true]);
                }
                app(AccessoryStockService::class)->receive($branch->id, $variant->id, 1, $incoming['buyingPrice'], AccessoryMovementReason::PurchaseReceipt, $actor, $exchange, 'Exchange '.$exchange->document_no);
                $exchange->update(['product_variant_id' => $variant->id, 'description' => Str::limit($variant->displayName(), 250, '')]);
                $inventoryAccount = $accounts->accessoryInventory();
            }
            $buying = Money::of($incoming['buyingPrice']);
            if ($buying->isPositive()) {
                app(JournalPostingService::class)->post(
                    [['account_id' => $inventoryAccount->id, 'debit' => $buying->amount], ['account_id' => $accounts->account('2100')->id, 'credit' => $buying->amount]],
                    'Exchange intake '.$exchange->document_no, BusinessClock::today(), 'exchange:'.$exchange->id.':intake', $exchange, $actor,
                );
            }
            $saleData['payments'] = $settlement['direction'] === 'CUSTOMER' && Money::of($settlement['amount'])->isPositive()
                ? array_map(fn ($payment) => array_merge($payment, ['reference' => $payment['reference'] ?: $exchange->document_no]), $split) : [];
            $sale = app(SaleService::class)->post($saleData, $actor, $exchange);
            $difference = Money::of($sale->grand_total)->sub($buying);
            $direction = $difference->isPositive() ? 'CUSTOMER' : ($difference->isNegative() ? 'COUNTER' : 'EVEN');
            $amount = $difference->isNegative() ? Money::zero()->sub($difference) : $difference;
            if ($direction !== $settlement['direction'] || ! $amount->equals($settlement['amount']) || Money::of($sale->due_total)->isPositive()) {
                throw ValidationException::withMessages(['exchange' => 'Settlement must match the difference: '.$direction.' '.$amount->amount.'. No exchange was saved.']);
            }
            if ($direction === 'COUNTER') {
                app(JournalPostingService::class)->post(
                    array_merge([['account_id' => $accounts->account('2100')->id, 'debit' => $amount->amount]], array_map(fn ($payment) => [
                        'account_id' => $accounts->paymentAccount($branch, PaymentMethod::from($payment['method']))->id,
                        'credit' => $payment['amount'], 'memo' => $payment['reference'] ?: $payment['method'],
                    ], $split)),
                    'Exchange counter payout '.$exchange->document_no, BusinessClock::today(), 'exchange:'.$exchange->id.':payout', $exchange, $actor,
                );
            }
            $exchange->update(['sale_id' => $sale->id]);
            AuditLogger::record('exchange.posted', $exchange, [], $exchange->toArray(), null, $branch->id, $actor);

            return $sale;
        });
    }

    private function phoneVariant(array $data, User $actor, ?ProductVariant $previous): ProductVariant
    {
        if ($previous) {
            if (mb_strtolower(trim($data['brandName'])) !== mb_strtolower($previous->product->brand?->name ?? '') || mb_strtolower(trim($data['modelName'])) !== mb_strtolower($previous->product->name)) {
                throw ValidationException::withMessages(['modelName' => 'Keep the original model of this handset. Condition and specifications can be updated.']);
            }
            $product = $previous->product;
        } else {
            $brand = Brand::whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['brandName']))])->first();
            if (! $brand) {
                Gate::forUser($actor)->authorize('catalog.manage');
                $brand = Brand::create(['name' => trim($data['brandName']), 'slug' => Str::slug($data['brandName']).'-'.Str::random(8), 'is_active' => true]);
            }
            $product = Product::phones()->where('is_active', true)->where('brand_id', $brand->id)->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['modelName']))])->first();
            if (! $product) {
                Gate::forUser($actor)->authorize('catalog.manage');
                $product = Product::create(['name' => trim($data['modelName']), 'model' => trim($data['modelName']), 'brand_id' => $brand->id, 'product_type' => ProductType::Phone, 'is_active' => true]);
            }
        }
        $spec = collect(['country', 'storage', 'ram'])->mapWithKeys(fn ($key) => [$key => trim((string) ($data[$key] ?? '')) ?: null])->all();
        $spec['color'] = trim((string) ($data['colour'] ?? '')) ?: null;

        return ProductVariant::firstOrCreate(array_merge(['product_id' => $product->id, 'is_active' => true], $spec), [
            'sku' => 'PHONE-'.Str::uuid(), 'default_purchase_price' => $data['buyingPrice'], 'default_selling_price' => $data['askingPrice'] ?: null,
        ]);
    }
}
