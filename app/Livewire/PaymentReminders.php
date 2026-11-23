<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Sale;
use App\Models\SalePaymentSchedule;
use App\Support\BusinessClock;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
class PaymentReminders extends Component
{
    use WithPagination;

    #[Locked]
    public string $unitId = '';

    public function mount(string $unitId = ''): void
    {
        $this->authorize('viewAny', Sale::class);
        $this->unitId = $unitId;
    }

    public function render()
    {
        $this->authorize('viewAny', Sale::class);
        $ids = auth()->user()->accessibleUnitIds();
        if ($this->unitId !== '' && $this->unitId !== 'all') {
            abort_unless(in_array((int) $this->unitId, $ids, true), 403);
            $ids = [(int) $this->unitId];
        }
        $query = SalePaymentSchedule::query()->with(['sale.customer', 'sale.businessUnit'])
            ->where('due_date', '<', BusinessClock::today()->toDateString())
            ->whereColumn('paid_amount', '<', 'amount')
            ->whereHas('sale', fn ($q) => $q->whereIn('business_unit_id', $ids)->where('status', 'POSTED')->where('due_total', '>', 0));

        return view('livewire.payment-reminders', [
            'overdueTotal' => (clone $query)->selectRaw('COALESCE(SUM(amount - paid_amount), 0) as total')->value('total'),
            'reminders' => $query->orderBy('due_date')->orderBy('id')->paginate(10, ['*'], 'reminderPage'),
            'currency' => config('erp.currency.symbol'),
        ]);
    }
}
