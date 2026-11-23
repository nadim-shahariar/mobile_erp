<?php

declare(strict_types=1);

use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\LocaleSwitchController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TransferMemoController;
use App\Http\Controllers\UnitSwitchController;
use App\Livewire\Catalog\ProductIndex;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Dashboard;
use App\Livewire\Imports\PhoneImport;
use App\Livewire\Inventory\BulkEntry;
use App\Livewire\Inventory\ImeiHistory;
use App\Livewire\Inventory\InventoryGrid;
use App\Livewire\Inventory\PhoneIntake;
use App\Livewire\PaymentReminders;
use App\Livewire\Payroll\SalaryIndex;
use App\Livewire\Pos\PosTerminal;
use App\Livewire\Purchases\PurchaseForm;
use App\Livewire\Purchases\PurchaseIndex;
use App\Livewire\Purchases\PurchaseLots;
use App\Livewire\Purchases\PurchaseShow;
use App\Livewire\Sales\SalesIndex;
use App\Livewire\Settings\SettingsPage;
use App\Livewire\Staff\StaffIndex;
use App\Livewire\Suppliers\SupplierIndex;
use App\Livewire\Transfers\TransferForm;
use App\Livewire\Transfers\TransferIndex;
use App\Livewire\Transfers\TransferShow;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
})->name('home');

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/staff-salary', SalaryIndex::class)->middleware('can:payroll.run')->name('staff.salary');
    Route::get('/payment-reminders', PaymentReminders::class)->middleware('can:invoice.view')->name('payment-reminders');

    Route::get('/pos', PosTerminal::class)->middleware('can:pos.sell')->name('pos.index');
    Route::get('/sales', SalesIndex::class)->middleware('can:viewAny,App\Models\Sale')->name('sales.index');
    Route::get('/branding/logo', [InvoiceController::class, 'logo'])->name('branding.logo');
    Route::get('/invoice-preview', [InvoiceController::class, 'preview'])->middleware('can:settings.manage')->name('invoices.preview');
    Route::get('/invoices/{sale}', [InvoiceController::class, 'show'])->middleware('can:view,sale')->name('invoices.show');
    Route::get('/customers', CustomerIndex::class)->middleware('can:viewAny,App\Models\Customer')->name('customers.index');

    Route::get('/inventory', InventoryGrid::class)->middleware('can:viewAny,App\Models\SerializedUnit')->name('inventory.index');
    Route::get('/inventory/new', PhoneIntake::class)->middleware('can:create,App\Models\SerializedUnit')->name('inventory.create');
    Route::get('/inventory/bulk-entry', BulkEntry::class)->middleware('can:import,App\Models\SerializedUnit')->name('inventory.bulk');
    Route::get('/imei-history/{imei?}', ImeiHistory::class)->name('imei.history');

    Route::get('/purchase-lots', PurchaseLots::class)->middleware('can:purchase.view')->name('purchase-lots');
    Route::get('/purchases', PurchaseIndex::class)->middleware('can:viewAny,App\Models\Purchase')->name('purchases.index');
    Route::get('/purchases/create', PurchaseForm::class)->middleware('can:create,App\Models\Purchase')->name('purchases.create');
    Route::get('/purchases/{purchase}', PurchaseShow::class)->middleware('can:view,purchase')->name('purchases.show');

    Route::get('/transfers', TransferIndex::class)->middleware('can:viewAny,App\Models\StockTransfer')->name('transfers.index');
    Route::get('/transfers/create', TransferForm::class)->middleware('can:stock.transfer.create')->name('transfers.create');
    Route::get('/transfers/{transfer}/memo', TransferMemoController::class)->middleware('can:view,transfer')->name('transfers.memo');
    Route::get('/transfers/{transfer}', TransferShow::class)->middleware('can:view,transfer')->name('transfers.show');

    Route::get('/catalog', ProductIndex::class)->middleware('can:viewAny,App\Models\Product')->name('catalog.index');
    Route::get('/suppliers', SupplierIndex::class)->middleware('can:viewAny,App\Models\Supplier')->name('suppliers.index');
    Route::get('/imports/phones', PhoneImport::class)->middleware('can:create,App\Models\ImportBatch')->name('imports.phones');

    Route::get('/staff', StaffIndex::class)->middleware('can:staff.view')->name('staff.index');
    Route::get('/settings', SettingsPage::class)->middleware('can:settings.manage')->name('settings.index');

    Route::post('/unit/switch', UnitSwitchController::class)->name('unit.switch');
    Route::post('/locale/switch', LocaleSwitchController::class)->name('locale.switch');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
