<?php

declare(strict_types=1);

namespace App\Livewire\Purchases;

use App\Domain\Purchases\PurchaseService;
use App\Enums\LineType;
use App\Enums\PurchaseStatus;
use App\Exceptions\ErpException;
use App\Models\InventoryCycle;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class PurchaseShow extends Component
{
    public Purchase $purchase;

    public function mount(Purchase $purchase): void
    {
        $this->authorize('view', $purchase);
        $this->purchase = $purchase;
    }

    public function receive(PurchaseService $purchases): void
    {
        $this->authorize('receive', $this->purchase);

        try {
            $this->purchase = $purchases->receive($this->purchase, auth()->user());
            session()->flash('success', "Purchase {$this->purchase->document_no} received. Every IMEI now has an open acquisition cycle.");
        } catch (ErpException $exception) {
            $this->addError('purchase', $exception->getMessage());
        }
    }

    public function cancel(PurchaseService $purchases): void
    {
        $this->authorize('cancel', $this->purchase);

        try {
            $this->purchase = $purchases->cancel($this->purchase, auth()->user(), 'Cancelled from purchase screen');
            session()->flash('success', "Purchase {$this->purchase->document_no} cancelled. No stock was affected.");
        } catch (ErpException $exception) {
            $this->addError('purchase', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $this->purchase->load(['supplier', 'businessUnit', 'lines.variant.product.brand', 'creator', 'receiver']);

        $lineIds = $this->purchase->lines->where('line_type', LineType::Phone)->pluck('id');

        $receivedUnits = InventoryCycle::query()
            ->where('source_ref_type', PurchaseLine::class)
            ->whereIn('source_ref_id', $lineIds)
            ->with('unit')
            ->get()
            ->keyBy('source_ref_id');

        return view('livewire.purchases.show', [
            'receivedUnits' => $receivedUnits,
            'currency' => config('erp.currency.symbol'),
            'canReceive' => auth()->user()->can('receive', $this->purchase) && $this->purchase->status === PurchaseStatus::Draft,
            'canCancel' => auth()->user()->can('cancel', $this->purchase) && $this->purchase->status === PurchaseStatus::Draft,
        ]);
    }
}
