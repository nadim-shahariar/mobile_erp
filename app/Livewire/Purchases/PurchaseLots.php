<?php

namespace App\Livewire\Purchases;

use App\Domain\Purchases\PurchaseLotService;
use App\Enums\PaymentMethod;
use App\Exceptions\DomainRuleViolationException;
use App\Models\ProductVariant;
use App\Models\PurchaseLot;
use App\Models\Setting;
use App\Models\Supplier;
use App\Support\BusinessClock;
use App\Support\UnitContext;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseLots extends Component
{
    public string $search = '';

    public bool $creating = false;

    public string $supplierId = '';

    public string $orderDate = '';

    public string $notes = '';

    public array $rows = [];

    #[Locked]
    public ?int $lotId = null;

    public array $receipt = [];

    public string $paidAmount = '0';

    public string $paymentMethod = 'CASH';

    public string $scan = '';

    public string $scanResult = '';

    public string $cancelReason = '';

    #[Locked]
    public string $receiptKey = '';

    public function mount(): void
    {
        $this->authorize('purchase.view');
        $this->orderDate = BusinessClock::today()->toDateString();
        $this->receiptKey = (string) Str::uuid();
        $this->addLine();
    }

    private function enabled(): void
    {
        abort_unless(Setting::get('features.purchase_lots', true), 403);
    }

    private function lot(): PurchaseLot
    {
        return PurchaseLot::where('business_unit_id', app(UnitContext::class)->requireCurrent(auth()->user())->id)->findOrFail($this->lotId);
    }

    public function addLine(): void
    {
        $this->rows[(string) Str::uuid()] = ['product_variant_id' => '', 'expected_imei' => '', 'unit_cost' => '0', 'pending_reason' => 'Awaiting delivery'];
    }

    public function duplicateLine(string $key): void
    {
        if (isset($this->rows[$key])) {
            $row = $this->rows[$key];
            $row['expected_imei'] = '';
            $this->rows[(string) Str::uuid()] = $row;
        }
    }

    public function removeLine(string $key): void
    {
        unset($this->rows[$key]);
    }

    public function save(): void
    {
        $this->enabled();
        $this->authorize('purchase.create');
        $this->validate(['supplierId' => 'required|exists:suppliers,id', 'orderDate' => 'required|date', 'notes' => 'nullable|string|max:2000', 'rows' => 'required|array|min:1|max:500', 'rows.*.product_variant_id' => 'required|exists:product_variants,id', 'rows.*.unit_cost' => 'required|numeric|min:0|max:999999999', 'rows.*.expected_imei' => 'nullable|digits:15', 'rows.*.pending_reason' => 'nullable|string|max:1000']);
        $lot = app(PurchaseLotService::class)->create((int) $this->supplierId, $this->rows, $this->orderDate, $this->notes, auth()->user());
        $this->creating = false;
        $this->rows = [];
        $this->addLine();
        $this->openLot($lot->id);
        session()->flash('success', 'Lot order saved. Pending phones are not in stock yet.');
    }

    public function openLot(int $id): void
    {
        $this->enabled();
        $this->authorize('purchase.view');
        $this->lotId = $id;
        $lot = $this->lot();
        $this->receipt = [];
        foreach ($lot->lines as $line) {
            if ($line->status === 'PENDING') {
                $this->receipt[$line->id] = ['selected' => false, 'imei' => $line->expected_imei ?? '', 'battery_health' => '', 'pending_reason' => $line->pending_reason ?? ''];
            }
        }
        $this->paidAmount = '0';
        $this->cancelReason = '';
        $this->scanResult = '';
        $this->receiptKey = (string) Str::uuid();
        $this->resetErrorBag();
    }

    public function scanImei(): void
    {
        $this->enabled();
        $this->authorize('purchase.view');
        $line = $this->lot()->lines()->where(fn ($q) => $q->where('expected_imei', trim($this->scan))->orWhere('actual_imei', trim($this->scan)))->first();
        if (! $line) {
            $this->scanResult = 'IMEI not listed in this lot. For an order without IMEI, select its pending row and enter the received IMEI.';

            return;
        }
        $this->scanResult = $line->status.' — IMEI '.$this->scan;
        if ($line->status === 'PENDING') {
            $this->receipt[$line->id]['selected'] = true;
            $this->receipt[$line->id]['imei'] = trim($this->scan);
        }
        $this->scan = '';
    }

    public function receive(): void
    {
        $this->enabled();
        $this->authorize('purchase.approve');
        $this->validate(['paidAmount' => 'required|numeric|min:0|max:999999999', 'paymentMethod' => ['required', Rule::enum(PaymentMethod::class)], 'receipt' => 'array', 'receipt.*.imei' => 'nullable|digits:15', 'receipt.*.battery_health' => 'nullable|integer|min:0|max:100']);
        $items = array_filter($this->receipt, fn ($r) => $r['selected'] ?? false);
        try {
            app(PurchaseLotService::class)->receive($this->lot(), $items, $this->paidAmount, $this->paymentMethod, $this->receiptKey, auth()->user());
        } catch (DomainRuleViolationException $e) {
            $this->addError('receipt', $e->getMessage());

            return;
        }
        $this->openLot($this->lotId);
        session()->flash('success', 'Selected phones received into stock. Supplier balance updated.');
    }

    public function saveReasons(): void
    {
        $this->enabled();
        $this->authorize('purchase.create');
        $this->validate(['receipt.*.pending_reason' => 'nullable|string|max:1000']);
        $lot = $this->lot();
        foreach ($this->receipt as $id => $row) {
            $lot->lines()->whereKey($id)->where('status', 'PENDING')->update(['pending_reason' => $row['pending_reason'] ?? null]);
        }
        session()->flash('success', 'Pending reasons saved.');
    }

    public function cancelSelected(): void
    {
        $this->enabled();
        $this->validate(['cancelReason' => 'required|string|max:1000']);
        app(PurchaseLotService::class)->cancel($this->lot(), array_keys(array_filter($this->receipt, fn ($r) => $r['selected'] ?? false)), $this->cancelReason, auth()->user());
        $this->openLot($this->lotId);
        session()->flash('success', 'Pending phones cancelled.');
    }

    public function render()
    {
        $this->enabled();
        $unit = app(UnitContext::class)->requireCurrent(auth()->user());
        $lots = PurchaseLot::where('business_unit_id', $unit->id)->with('supplier')->withCount(['lines', 'lines as received_count' => fn ($q) => $q->where('status', 'RECEIVED'), 'lines as pending_count' => fn ($q) => $q->where('status', 'PENDING'), 'lines as cancelled_count' => fn ($q) => $q->where('status', 'CANCELLED')]);
        if (trim($this->search) !== '') {
            $term = '%'.trim($this->search).'%';
            $lots->where(fn ($q) => $q->where('lot_no', 'like', $term)->orWhereHas('lines', fn ($l) => $l->where('expected_imei', 'like', $term)->orWhere('actual_imei', 'like', $term)));
        }

        return view('livewire.purchases.lots', ['lots' => $lots->latest()->limit(100)->get(), 'lot' => $this->lotId ? $this->lot()->load('lines.variant.product.brand', 'lines.purchase', 'supplier') : null, 'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(), 'variants' => ProductVariant::where('is_active', true)->whereHas('product',fn ($q) => $q->where('product_type','PHONE'))->with('product.brand')->get()]);
    }
}
