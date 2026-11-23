<?php

declare(strict_types=1);

namespace App\Livewire\Pos;

use App\Domain\Deliveries\ExternalDeliveryService;
use App\Domain\Inventory\SerialIdentityService;
use App\Domain\Sales\CustomerPaymentService;
use App\Domain\Transfers\PosUnitTransferService;
use App\Enums\PaymentMethod;
use App\Exceptions\ErpException;
use App\Models\BusinessUnit;
use App\Models\Customer;
use App\Models\ExternalDelivery;
use App\Models\Sale;
use App\Models\SerializedUnit;
use App\Support\BusinessClock;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

class StockHandoff extends Component
{
    #[Locked]
    public string $unitId = '';

    #[Locked]
    public ?int $lastTransferId = null;

    public bool $open = false;

    public string $imei = '';

    public string $destination = 'SHOP';

    public string $toUnitId = '';

    public string $name = '';

    public string $phone = '';

    public string $price = '';

    public string $deposit = '0';

    public string $paidNow = '0';

    public string $method = 'CASH';

    public string $dueDate = '';

    public string $notes = '';

    public string $collectInvoiceId = '';

    public string $collectAmount = '';

    #[Locked]
    public string $requestKey = '';

    public function mount(string $unitId): void
    {
        $this->unitId = $unitId;
        $this->requestKey = (string) Str::uuid();
        $this->dueDate = BusinessClock::today()->addMonthNoOverflow()->toDateString();
    }

    public function lookup(): void
    {
        $this->resetErrorBag();
        $this->validate(['imei' => ['required', 'string', 'max:32']]);
        $phone = app(SerialIdentityService::class)->findByImei($this->imei);
        if (! $phone || ! auth()->user()->can('view', $phone)) {
            $this->addError('imei', 'No accessible handset found for this IMEI.');

            return;
        }
        $this->requestKey = (string) Str::uuid();
        $this->deposit = '0';
        $this->name = '';
        $this->phone = '';
        $this->notes = '';
        $this->toUnitId = '';
        $this->collectInvoiceId = '';
        $this->collectAmount = '';
        $delivery = ExternalDelivery::where('business_unit_id', $this->unitId)->where('serialized_unit_id', $phone->id)->where('status', 'OUT')->first();
        $this->price = $delivery?->agreed_price ?? $phone->expected_selling_price ?? $phone->variant->default_selling_price ?? '0';
        $this->paidNow = '0';
        $due = Sale::where('business_unit_id', $this->unitId)->where('due_total', '>', 0)->whereHas('items', fn ($q) => $q->where('serialized_unit_id', $phone->id))->oldest('id')->first();
        $this->collectInvoiceId = (string) ($due?->id ?? '');
        $this->collectAmount = $due?->due_total ?? '';
    }

    public function selectPhone(int $id): void
    {
        $this->imei = SerializedUnit::findOrFail($id)->primaryIdentifier() ?? '';
        $this->lookup();
    }

    public function send(ExternalDeliveryService $external): void
    {
        $this->authorize('stock.transfer.create');
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);
        try {
            if ($this->destination === 'UNIT') {
                $this->validate(['toUnitId' => ['required', 'integer', 'exists:business_units,id']]);
                $phone = app(SerialIdentityService::class)->findByImei($this->imei);
                if (! $phone) {
                    $this->addError('imei', 'Unknown IMEI.');

                    return;
                }
                $transfer = app(PosUnitTransferService::class)->move((int) $this->unitId, (int) $this->toUnitId, [$phone->id], auth()->user(), $this->requestKey, $this->notes);
                $this->lastTransferId = $transfer->id;
                $this->dispatch('unit-phone-moved', phoneId: $phone->id);
                session()->flash('handoffSuccess', 'Transferred to '.$transfer->toUnit->name.'. Transfer memo is ready to print.');
                $this->resetAfterMovement();
            } else {
                $delivery = $external->send(['unit_id' => $this->unitId, 'imei' => $this->imei, 'recipient_type' => $this->destination, 'name' => $this->name, 'phone' => $this->phone, 'price' => $this->price, 'deposit' => $this->deposit, 'method' => $this->method, 'notes' => $this->notes, 'key' => $this->requestKey], auth()->user());
                session()->flash('handoffSuccess', $delivery->reference_no.' sent out temporarily. Scan the IMEI to return it or confirm its sale.');
                $this->resetAfterMovement();
            }
        } catch (ErpException $e) {
            $this->addError('delivery', $e->getMessage());
        }
    }

    public function returnUnitPhone(): void
    {
        $this->authorize('stock.transfer.create');
        $this->authorize('stock.transfer.approve');
        $phone = app(SerialIdentityService::class)->findByImei($this->imei);
        $service = app(PosUnitTransferService::class);
        $previous = $phone ? $service->previousTransfer($phone, (int) $this->unitId) : null;
        if (! $previous) {
            $this->addError('delivery', 'No completed outgoing unit transfer is available to return for this IMEI.');

            return;
        }
        try {
            $transfer = $service->move((int) $previous->to_unit_id, (int) $this->unitId, [$phone->id], auth()->user(), $this->requestKey, 'Return of '.$previous->transfer_no, $previous->id);
        } catch (ErpException $e) {
            $this->addError('delivery', $e->getMessage());

            return;
        }
        $this->lastTransferId = $transfer->id;
        $this->dispatch('unit-phone-moved', phoneId: $phone->id);
        $this->resetAfterMovement();
        session()->flash('handoffSuccess', 'Transferred phone returned to the current unit. Return memo is ready to print.');
    }

    public function returnPhone(int $id, ExternalDeliveryService $service): void
    {
        try {
            $service->receiveReturn($id, $this->method, auth()->user());
        } catch (ErpException $e) {
            $this->addError('delivery', $e->getMessage());

            return;
        }

        session()->flash('handoffSuccess', 'Phone returned to stock. Any recorded deposit has been refunded in the selected cash/bank ledger.');
        $this->resetAfterMovement();
    }

    public function sellPhone(int $id, ExternalDeliveryService $service): void
    {
        try {
            $sale = $service->confirmSale($id, $this->price, $this->paidNow, $this->method, $this->dueDate, auth()->user());
            session()->flash('handoffSuccess', $sale->invoice_no.' posted · due '.config('erp.currency.symbol').$sale->due_total.'. Scan the IMEI to collect the remaining due.');
            $this->collectInvoiceId = (string) $sale->id;
            $this->collectAmount = $sale->due_total;
            $this->requestKey = (string) Str::uuid();
            $this->deposit = '0';
            $this->paidNow = '0';
        } catch (ErpException $e) {
            $this->addError('delivery', $e->getMessage());
        }
    }

    public function collect(CustomerPaymentService $service): void
    {
        $this->authorize('payment.collect');
        $this->validate([
            'collectInvoiceId' => ['required', 'integer'],
            'collectAmount' => ['required', 'numeric', 'decimal:0,2', 'min:0.01'],
            'method' => ['required', 'in:CASH,BANK_TRANSFER'],
        ]);

        $phone = app(SerialIdentityService::class)->findByImei($this->imei);
        $sale = Sale::query()
            ->where('business_unit_id', $this->unitId)
            ->where('due_total', '>', 0)
            ->whereHas('items', fn ($q) => $q->where('serialized_unit_id', $phone?->id))
            ->find((int) $this->collectInvoiceId);

        if ($sale === null || $sale->customer === null) {
            $this->addError('collectInvoiceId', 'Choose an outstanding invoice for this IMEI.');

            return;
        }

        try {
            $payment = $service->collect($sale->customer, BusinessUnit::findOrFail((int) $this->unitId), PaymentMethod::from($this->method), $this->collectAmount, 'IMEI '.$this->imei, 'Due clearance by IMEI', auth()->user(), $this->requestKey.':collect', $sale->id);
            session()->flash('handoffSuccess', $payment->receipt_no.' collected · remaining '.config('erp.currency.symbol').$sale->refresh()->due_total.'.');
            $this->requestKey = (string) Str::uuid();
            $this->collectAmount = '';
        } catch (ErpException $e) {
            $this->addError('delivery', $e->getMessage());
        }
    }

    private function resetAfterMovement(): void
    {
        $this->imei = '';
        $this->name = '';
        $this->phone = '';
        $this->price = '';
        $this->deposit = '0';
        $this->paidNow = '0';
        $this->notes = '';
        $this->collectInvoiceId = '';
        $this->collectAmount = '';
        $this->requestKey = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function render()
    {
        abort_unless(auth()->user()->hasUnitAccess((int) $this->unitId), 403);
        $match = trim($this->imei) !== '' ? app(SerialIdentityService::class)->findByImei($this->imei) : null;
        if ($match && ! auth()->user()->can('view', $match)) {
            $match = null;
        }

        return view('livewire.pos.stock-handoff', [
            'match' => $match,
            'previousUnitTransfer' => $match ? app(PosUnitTransferService::class)->previousTransfer($match, (int) $this->unitId)?->load('fromUnit', 'toUnit') : null,
            'delivery' => $match ? ExternalDelivery::with('customer')->where('business_unit_id', $this->unitId)->where('serialized_unit_id', $match->id)->where('status', 'OUT')->first() : null,
            'dueInvoices' => $match ? Sale::with('customer')->where('business_unit_id', $this->unitId)->where('due_total', '>', 0)->whereHas('items', fn ($q) => $q->where('serialized_unit_id', $match->id))->get() : collect(),
            'destinations' => BusinessUnit::active()->whereIn('id', auth()->user()->accessibleUnitIds())->where('id', '!=', (int) $this->unitId)->ordered()->get(),
            'customers' => Customer::active()->orderBy('name')->get(),
            'pending' => ExternalDelivery::with(['customer', 'phone.variant.product.brand'])->where('business_unit_id', $this->unitId)->where('status', 'OUT')->latest('id')->limit(20)->get(),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
