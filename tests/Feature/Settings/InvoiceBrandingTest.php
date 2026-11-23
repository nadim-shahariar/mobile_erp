<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Sales\SaleService;
use App\Livewire\Settings\SettingsPage;
use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class InvoiceBrandingTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
        Setting::flushMemo();
    }

    public function test_shop_identity_terms_and_invoice_design_can_be_saved_and_previewed_without_a_sale(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        Livewire::actingAs($owner)->test(SettingsPage::class)
            ->set('companyName', 'Different Mobile Shop')->set('companyAddress', '42 Example Road')
            ->set('companyPhone', '01711111111 / 01822222222')->set('companyEmail', 'shop@example.com')
            ->set('invoiceTitle', 'SALES RECEIPT')->set('invoiceTagline', 'Our own shop slogan')->set('brandSubtitle', 'MOBILE STORE')
            ->set('invoiceAccent', '#be123c')->set('termsHeading', 'OUR WARRANTY')
            ->set('invoiceTerms', 'Warranty: Contact {shop_name} for service.')->set('invoiceFooter', 'Thank you from our shop.')
            ->set('customerSignature', 'Buyer signature')->set('authorizedSignature', 'Shop signature')
            ->call('save')->assertHasNoErrors();
        $response = $this->actingAs($owner)->get(route('invoices.preview'));
        $response->assertOk()->assertSee('Different Mobile Shop')->assertSee('42 Example Road')->assertSee('SALES RECEIPT')
            ->assertSee('Our own shop slogan')->assertSee('OUR WARRANTY')->assertSee('Contact Different Mobile Shop for service.')
            ->assertSee('Buyer signature')->assertSee('Shop signature')->assertSee('Sample preview only')->assertSee('#be123c', false);
        $this->assertDatabaseCount('sales', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseHas('settings', ['key' => 'invoice.title']);
    }

    public function test_logo_can_be_uploaded_served_and_removed_and_settings_are_protected(): void
    {
        Storage::fake('public');
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $component = Livewire::actingAs($owner)->test(SettingsPage::class)
            ->set('logoUpload', UploadedFile::fake()->createWithContent('shop-logo.png', $png))->call('save')->assertHasNoErrors();
        $path = Setting::get('company.logo_path');
        Storage::disk('public')->assertExists($path);
        $this->actingAs($owner)->get(route('branding.logo'))->assertOk();
        $component->set('removeLogo', true)->call('save')->assertHasNoErrors();
        $this->actingAs($owner)->get(route('branding.logo'))->assertNotFound();
        $cashier = $this->staff('cashier', [$this->unitOne->id]);
        $this->actingAs($cashier)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('invoices.preview'))->assertForbidden();
    }

    public function test_cash_memo_preserves_sold_condition_after_handset_is_edited(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);
        $phone = $this->addPhoneToStock($owner, $this->unitOne, $this->phoneVariant(), $this->validImei(6011));
        $phone->update(['battery_health' => 92, 'box_kit' => 'Only set', 'inventory_category' => 'PRE_OWNED']);
        $customer = Customer::create(['name' => 'Invoice Customer', 'phone' => '01733334444', 'type' => 'PERSON', 'is_active' => true]);
        $sale = app(SaleService::class)->post(['business_unit_id' => $this->unitOne->id, 'customer_id' => $customer->id,
            'items' => [['line_type' => 'PHONE', 'serialized_unit_id' => $phone->id, 'unit_price' => '25000']],
            'payments' => [['method' => 'CASH', 'amount' => '25000']]], $owner);
        $phone->update(['battery_health' => 80, 'box_kit' => 'Box replaced']);
        $this->actingAs($owner)->get(route('invoices.show', $sale))->assertOk()->assertSee('CASH MEMO')->assertSee('Invoice Customer')
            ->assertSee('Battery health 92%')->assertSee('Only set')->assertDontSee('Battery health 80%')->assertSee('IMEI Number')->assertSee('Customer Signature');
    }
}
