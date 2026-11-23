<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsErpScenario;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use BuildsErpScenario;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootErp();
    }

    public function test_inactive_users_cannot_log_in(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'password',
            'is_active' => false,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_active_users_log_in_and_last_login_is_recorded(): void
    {
        $user = $this->staff('cashier', [$this->unitOne->id]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Secret!12345',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $this->assertNotNull($user->refresh()->last_login_at);
    }

    public function test_suspended_session_is_terminated_on_next_request(): void
    {
        $user = $this->staff('owner', [$this->unitOne->id]);
        $user->forceFill(['is_active' => false])->save();

        $this->actingAs($user->refresh())
            ->get(route('dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_cashier_cannot_open_settings_or_staff_administration(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);

        $this->actingAs($cashier)->get(route('settings.index'))->assertForbidden();
        $this->actingAs($cashier)->get(route('staff.index'))->assertForbidden();
    }

    public function test_staff_cannot_open_purchases_without_permission(): void
    {
        $staff = $this->staff('staff', [$this->unitOne->id]);

        $this->actingAs($staff)->get(route('purchases.index'))->assertForbidden();
    }

    public function test_owner_can_open_settings(): void
    {
        $owner = $this->staff('owner', [$this->unitOne->id]);

        $this->actingAs($owner)->get(route('settings.index'))->assertOk()->assertSee('Business identity');
    }

    public function test_cashier_cannot_sell_or_view_cost_permissions_via_api_surface(): void
    {
        $cashier = $this->staff('cashier', [$this->unitOne->id]);

        $this->assertFalse($cashier->can('inventory.cost.view'));
        $this->assertFalse($cashier->can('purchase.create'));
        $this->assertTrue($cashier->can('pos.sell'));
    }
}
