<?php

declare(strict_types=1);

namespace App\Livewire\Transfers;

use App\Domain\Transfers\StockTransferService;
use App\Enums\StockTransferStatus;
use App\Exceptions\ErpException;
use App\Models\StockTransfer;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class TransferShow extends Component
{
    public StockTransfer $transfer;

    public function mount(StockTransfer $transfer): void
    {
        $this->authorize('view', $transfer);
        $this->transfer = $transfer;
    }

    public function dispatchTransfer(StockTransferService $transfers): void
    {
        $this->authorize('dispatch', $this->transfer);

        try {
            $this->transfer = $transfers->dispatch($this->transfer, auth()->user());
            session()->flash('success', "Transfer {$this->transfer->transfer_no} dispatched. The handsets are now in transit and cannot be sold.");
        } catch (ErpException $exception) {
            $this->addError('transfer', $exception->getMessage());
        }
    }

    public function receiveTransfer(StockTransferService $transfers): void
    {
        $this->authorize('receive', $this->transfer);

        try {
            $this->transfer = $transfers->receive($this->transfer, auth()->user());
            session()->flash('success', "Transfer {$this->transfer->transfer_no} received into {$this->transfer->toUnit?->name}. Stock is available again.");
        } catch (ErpException $exception) {
            $this->addError('transfer', $exception->getMessage());
        }
    }

    public function cancelTransfer(StockTransferService $transfers): void
    {
        $this->authorize('cancel', $this->transfer);

        try {
            $this->transfer = $transfers->cancel($this->transfer, auth()->user(), 'Cancelled from transfer screen');
            session()->flash('success', "Transfer {$this->transfer->transfer_no} cancelled and reservations released.");
        } catch (ErpException $exception) {
            $this->addError('transfer', $exception->getMessage());
        }
    }

    public function render(): View
    {
        $this->transfer->load([
            'fromUnit',
            'toUnit',
            'requester',
            'dispatcher',
            'receiver',
            'lines.serializedUnit.variant.product.brand',
            'lines.variant.product.brand',
        ]);

        return view('livewire.transfers.show', [
            'canDispatch' => $this->transfer->status === StockTransferStatus::Requested && auth()->user()->can('dispatch', $this->transfer),
            'canReceive' => $this->transfer->status === StockTransferStatus::Dispatched && auth()->user()->can('receive', $this->transfer),
            'canCancel' => $this->transfer->status === StockTransferStatus::Requested && auth()->user()->can('cancel', $this->transfer),
        ]);
    }
}
