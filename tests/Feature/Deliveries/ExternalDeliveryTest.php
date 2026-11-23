<?php

declare(strict_types=1);

namespace Tests\Feature\Deliveries;

use App\Domain\Deliveries\ExternalDeliveryService;
use App\Enums\Custody;
use App\Livewire\Pos\StockHandoff;
use App\Models\Account;
use App\Models\ExternalDelivery;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Support\BusinessClock;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class ExternalDeliveryTest extends TestCase
{
    use BuildsErpScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    private function send($owner, $phone): ExternalDelivery
    {
        return app(ExternalDeliveryService::class)->send(['unit_id' => $this->unitOne->id, 'imei' => $phone->primary_imei, 'recipient_type' => 'SHOP', 'name' => 'Neighbour Shop', 'phone' => '01700000000', 'price' => '10000', 'deposit' => '2000', 'method' => 'CASH', 'notes' => '', 'key' => (string) Str::uuid()], $owner);
    }

    public function test_delivery_references_do_not_collide_across_branches(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id, $this->unitTwo->id]);
        $variant = $this->phoneVariant();
        $phoneOne = $this->addPhoneToStock($owner, $this->unitOne, $variant, $this->validImei(704));
        $phoneTwo = $this->addPhoneToStock($owner, $this->unitTwo, $variant, $this->validImei(705));
        $service = app(ExternalDeliveryService::class);

        $first = $service->send([
            'unit_id' => $this->unitOne->id, 'imei' => $phoneOne->primary_imei, 'recipient_type' => 'PERSON',
            'name' => 'Buyer One', 'phone' => '01700000011', 'price' => '10000', 'deposit' => '0',
            'method' => 'CASH', 'notes' => '', 'key' => (string) Str::uuid(),
        ], $owner);

        $second = $service->send([
            'unit_id' => $this->unitTwo->id, 'imei' => $phoneTwo->primary_imei, 'recipient_type' => 'PERSON',
            'name' => 'Buyer Two', 'phone' => '01700000012', 'price' => '10000', 'deposit' => '0',
            'method' => 'CASH', 'notes' => '', 'key' => (string) Str::uuid(),
        ], $owner);

        $this->assertNotSame($first->reference_no, $second->reference_no);
        $this->assertStringStartsWith('EXT-', $first->reference_no);
    }

    public function test_temporary_delivery_is_not_a_sale_and_return_preserves_cycle_and_refunds_deposit(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(701));
        $cycle = $phone->activeCycle->id;
        $delivery = $this->send($owner, $phone);
        $this->assertSame(Custody::AtExternalParty, $phone->refresh()->current_custody);
        $this->assertSame(0, Sale::count());
        $this->assertFalse($phone->isSellable());
        $service = app(ExternalDeliveryService::class);
        $service->receiveReturn($delivery->id, 'CASH', $owner);
        $count = JournalEntry::count();
        $service->receiveReturn($delivery->id, 'CASH', $owner);
        $this->assertSame($count, JournalEntry::count());
        $this->assertSame($cycle, $phone->refresh()->activeCycle->id);
        $this->assertTrue($phone->isSellable());
        $deposits = Account::where('code', '2400')->sole();
        $this->assertSame(Money::of($deposits->debitTotal())->amount, Money::of($deposits->creditTotal())->amount);
    }

    public function test_handoff_resets_deposit_and_rotates_the_request_key_after_sending(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(703));

        $component = Livewire::actingAs($owner)
            ->test(StockHandoff::class, ['unitId' => (string) $this->unitOne->id])
            ->set('open', true)
            ->set('imei', $phone->primary_imei)
            ->call('lookup')
            ->set('destination', 'PERSON')
            ->set('name', 'Temporary Buyer')
            ->set('phone', '01700000003')
            ->set('price', '10000')
            ->set('deposit', '2000');

        $key = $component->get('requestKey');
        $component->call('send')->assertHasNoErrors();

        $this->assertSame('0', $component->get('deposit'));
        $this->assertSame('', $component->get('imei'));
        $this->assertNotSame($key, $component->get('requestKey'));
        $this->assertSame(Custody::AtExternalParty, $phone->refresh()->current_custody);
    }

    public function test_confirm_sale_applies_deposit_and_scan_imei_collects_only_that_invoice_due(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(702));
        $delivery = $this->send($owner, $phone);
        $this->assertSame('SHOP', $delivery->customer->type->value);
        $delivery->customer->update(['credit_limit' => '50000.00']);
        $sale = app(ExternalDeliveryService::class)->confirmSale($delivery->id, '10000', '3000', 'CASH', BusinessClock::today()->addDays(7)->toDateString(), $owner);
        $this->assertSame('2000.00', $sale->deposit_credit);
        $this->assertSame('5000.00', $sale->due_total);
        $this->assertSame('3000.00', $sale->payments->sole()->amount);
        $this->assertSame(Custody::WithCustomer, $phone->refresh()->current_custody);
        $this->assertSame(1, $phone->cycles()->count());
        $this->assertNull($phone->activeCycle);
        Livewire::actingAs($owner)->test(StockHandoff::class, ['unitId' => (string) $this->unitOne->id])
            ->set('open', true)->set('imei', $phone->primary_imei)->call('lookup')->assertHasNoErrors()
            ->assertSet('collectInvoiceId', (string) $sale->id)->set('collectAmount', '5000')->call('collect')->assertHasNoErrors();
        $this->assertSame('0.00', $sale->refresh()->due_total);
        $this->actingAs($owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('deposit already applied');
        foreach (JournalEntry::all() as $entry) {
            $this->assertSame(Money::of((string) $entry->totalDebit())->amount, Money::of((string) $entry->totalCredit())->amount);
        }
    }
}
