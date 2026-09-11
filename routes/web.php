<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AccountingDocumentController;
use App\Http\Controllers\AccountingReportController;
use App\Http\Controllers\AtlantiaAiConnectionController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\Billing\BillingDetailsController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\CreateCheckoutController;
use App\Http\Controllers\CompanyDashboardController;
use App\Http\Controllers\FelDocumentController;
use App\Http\Controllers\FelSettingsController;
use App\Http\Controllers\FiscalSettingsController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\OperationalRecordController;
use App\Http\Controllers\OperationalRecordFileController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\Webhooks\RecurrenteWebhookController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::post('webhooks/recurrente', RecurrenteWebhookController::class)->name('webhooks.recurrente');
Route::get('invitations/{invitation}', [InvitationController::class, 'show'])->middleware(['signed', 'throttle:20,1'])->name('invitations.show');
Route::post('invitations/{invitation}', [InvitationController::class, 'accept'])->middleware(['signed', 'throttle:10,1'])->name('invitations.accept');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware('subscription.active')->prefix('accounting')->name('accounting.')->group(function () {
        Route::get('/', [AccountingController::class, 'index'])->name('index');
        Route::post('companies', [AccountingController::class, 'storeCompany'])->middleware('throttle:20,1')->name('storeCompany');
        Route::get('companies/{company}/dashboard', CompanyDashboardController::class)->name('companyDashboard');
        Route::get('companies/{company}', [AccountingController::class, 'show'])->name('show');
        Route::get('companies/{company}/reports', [AccountingReportController::class, 'index'])->name('reports');
        Route::get('companies/{company}/export', [AccountingReportController::class, 'export'])->middleware('throttle:10,1')->name('export');
        Route::get('companies/{company}/fel/settings', [FelSettingsController::class, 'edit'])->name('fel.settings.edit');
        Route::post('companies/{company}/fel/settings', [FelSettingsController::class, 'update'])->middleware('throttle:20,1')->name('fel.settings.update');
        Route::prefix('companies/{company}/fel')->name('fel.')->controller(FelDocumentController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('create', 'create')->name('create');
            Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
            Route::post('quote', 'quote')->middleware('throttle:60,1')->name('quote');
            Route::get('{document}', 'show')->name('show');
            Route::get('{document}/edit', 'edit')->name('edit');
            Route::patch('{document}', 'update')->middleware('throttle:30,1')->name('update');
            Route::post('{document}/certify', 'certify')->middleware('throttle:10,1')->name('certify');
            Route::get('{document}/pdf', 'pdf')->name('pdf');
            Route::post('{document}/pdf', 'regeneratePdf')->middleware('throttle:10,1')->name('regeneratePdf');
            Route::post('{document}/email', 'email')->middleware('throttle:5,1')->name('email');
            Route::post('{document}/cancel', 'cancel')->middleware('throttle:10,1')->name('cancel');
            Route::post('{document}/accounting', 'accounting')->middleware('throttle:10,1')->name('accounting');
        });
        Route::get('companies/{company}/sales', [SalesController::class, 'index'])->name('sales');
        Route::get('companies/{company}/customers', [SalesController::class, 'customers'])->name('salesCustomers');
        Route::get('companies/{company}/receivables', [SalesController::class, 'receivables'])->name('receivables');
        Route::post('companies/{company}/sales/customers', [SalesController::class, 'customer'])->middleware('throttle:30,1')->name('salesCustomer');
        Route::get('companies/{company}/sales/customers/{customer}', [SalesController::class, 'customerShow'])->name('salesCustomerShow');
        Route::patch('companies/{company}/sales/customers/{customer}', [SalesController::class, 'customerUpdate'])->middleware('throttle:30,1')->name('salesCustomerUpdate');
        Route::post('companies/{company}/sales/invoices', [SalesController::class, 'store'])->middleware('throttle:30,1')->name('salesStore');
        Route::get('companies/{company}/sales/invoices/{invoice}', [SalesController::class, 'show'])->name('invoice');
        Route::get('companies/{company}/sales/invoices/{invoice}/pdf', [SalesController::class, 'pdf'])->middleware('throttle:10,1')->name('invoicePdf');
        Route::post('companies/{company}/sales/invoices/{invoice}/process', [SalesController::class, 'process'])->middleware('throttle:30,1')->name('invoiceProcess');
        Route::post('companies/{company}/sales/invoices/{invoice}/receipts', [SalesController::class, 'receipt'])->middleware('throttle:30,1')->name('receipt');
        Route::post('companies/{company}/sales/invoices/{invoice}/receipts/{receipt}/void', [SalesController::class, 'voidReceipt'])->middleware('throttle:30,1')->name('voidReceipt');
        Route::get('companies/{company}/purchases', [PurchaseController::class, 'index'])->name('purchases');
        Route::get('companies/{company}/purchases/export', [PurchaseController::class, 'export'])->middleware('throttle:10,1')->name('purchasesExport');
        Route::get('companies/{company}/suppliers', [PurchaseController::class, 'suppliers'])->name('suppliers');
        Route::get('companies/{company}/suppliers/export', [PurchaseController::class, 'suppliersExport'])->middleware('throttle:10,1')->name('suppliersExport');
        Route::get('companies/{company}/payables', [PurchaseController::class, 'payables'])->name('payables');
        Route::get('companies/{company}/banks', [BankController::class, 'index'])->name('banks');
        Route::get('companies/{company}/banks/export', [BankController::class, 'export'])->middleware('throttle:10,1')->name('banksExport');
        Route::get('companies/{company}/fiscal-settings', [FiscalSettingsController::class, 'index'])->name('fiscalSettings');
        Route::patch('companies/{company}/fiscal-settings', [FiscalSettingsController::class, 'update'])->middleware('throttle:20,1')->name('fiscalSettingsUpdate');
        Route::post('companies/{company}/fiscal-settings/tax-rules', [FiscalSettingsController::class, 'storeRule'])->middleware('throttle:20,1')->name('fiscalTaxRuleStore');
        Route::post('companies/{company}/fiscal-settings/exchange-rates', [FiscalSettingsController::class, 'storeExchangeRate'])->middleware('throttle:20,1')->name('fiscalExchangeRateStore');
        Route::post('companies/{company}/banks/accounts', [BankController::class, 'storeAccount'])->middleware('throttle:20,1')->name('bankAccountStore');
        Route::post('companies/{company}/banks/transactions', [BankController::class, 'storeTransaction'])->middleware('throttle:30,1')->name('bankTransactionStore');
        Route::post('companies/{company}/purchases/suppliers', [PurchaseController::class, 'supplier'])->middleware('throttle:30,1')->name('purchaseSupplier');
        Route::patch('companies/{company}/purchases/suppliers/{supplier}', [PurchaseController::class, 'supplierUpdate'])->middleware('throttle:30,1')->name('purchaseSupplierUpdate');
        Route::patch('companies/{company}/accounting-mapping', [PurchaseController::class, 'mapping'])->middleware('throttle:20,1')->name('accountingMapping');
        Route::post('companies/{company}/purchases/documents', [PurchaseController::class, 'store'])->middleware('throttle:30,1')->name('purchaseStore');
        Route::get('companies/{company}/purchases/documents/{document}', [PurchaseController::class, 'show'])->name('purchaseShow');
        Route::get('companies/{company}/purchases/documents/{document}/pdf', [PurchaseController::class, 'documentPdf'])->middleware('throttle:10,1')->name('purchasePdf');
        Route::post('companies/{company}/purchases/documents/{document}/process', [PurchaseController::class, 'process'])->middleware('throttle:30,1')->name('purchaseProcess');
        Route::post('companies/{company}/purchases/documents/{document}/payments', [PurchaseController::class, 'payment'])->middleware('throttle:30,1')->name('purchasePayment');
        Route::post('companies/{company}/purchases/documents/{document}/payments/{payment}/void', [PurchaseController::class, 'voidPayment'])->middleware('throttle:30,1')->name('purchasePaymentVoid');
        Route::post('companies/{company}/purchases/documents/{document}/files', [PurchaseController::class, 'fileStore'])->middleware('throttle:20,1')->name('purchaseFileStore');
        Route::get('companies/{company}/purchases/documents/{document}/files/{file}', [PurchaseController::class, 'fileDownload'])->middleware('throttle:30,1')->name('purchaseFileDownload');
        Route::delete('companies/{company}/purchases/documents/{document}/files/{file}', [PurchaseController::class, 'fileDestroy'])->middleware('throttle:20,1')->name('purchaseFileDestroy');
        Route::post('companies/{company}/assignments', [AccountingController::class, 'assign'])->name('assign');
        Route::post('companies/{company}/accounts', [AccountingController::class, 'account'])->name('account');
        Route::post('companies/{company}/accounts/template', [AccountingController::class, 'accountTemplate'])->middleware('throttle:10,1')->name('accountTemplate');
        Route::patch('companies/{company}/accounts/{account}', [AccountingController::class, 'updateAccount'])->middleware('throttle:30,1')->name('updateAccount');
        Route::post('companies/{company}/periods', [AccountingController::class, 'period'])->name('period');
        Route::post('companies/{company}/periods/{period}/close', [AccountingController::class, 'close'])->name('close');
        Route::post('companies/{company}/entries', [AccountingController::class, 'entry'])->middleware('throttle:30,1')->name('entry');
        Route::post('companies/{company}/entries/{entry}/process', [AccountingController::class, 'process'])->middleware('throttle:30,1')->name('process');
        Route::post('companies/{company}/entries/{entry}/documents', [AccountingDocumentController::class, 'store'])->middleware('throttle:20,1')->name('entryDocumentStore');
        Route::get('companies/{company}/entries/{entry}/documents/{document}', [AccountingDocumentController::class, 'download'])->middleware('throttle:30,1')->name('entryDocumentDownload');
        Route::delete('companies/{company}/entries/{entry}/documents/{document}', [AccountingDocumentController::class, 'destroy'])->middleware('throttle:20,1')->name('entryDocumentDestroy');
        Route::prefix('companies/{company}/operations/{module}')->name('operations.')->controller(OperationalRecordController::class)->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->middleware('throttle:30,1')->name('store');
            Route::get('{record}', 'show')->name('show');
            Route::patch('{record}', 'update')->middleware('throttle:30,1')->name('update');
            Route::post('{record}/transition', 'transition')->middleware('throttle:30,1')->name('transition');
        });
        Route::post('companies/{company}/operations/{module}/{record}/files', [OperationalRecordFileController::class, 'store'])
            ->middleware('throttle:20,1')->name('operations.files.store');
        Route::get('companies/{company}/operations/{module}/{record}/files/{file}', [OperationalRecordFileController::class, 'download'])
            ->middleware('throttle:30,1')->name('operations.files.download');
        Route::delete('companies/{company}/operations/{module}/{record}/files/{file}', [OperationalRecordFileController::class, 'destroy'])
            ->middleware('throttle:20,1')->name('operations.files.destroy');
        Route::get('companies/{company}/configuration/ai/health', AtlantiaAiConnectionController::class)
            ->middleware('throttle:20,1')->name('configuration.ai.health');
    });
    Route::get('billing/details', [BillingDetailsController::class, 'edit'])->name('billing.details.edit');
    Route::patch('billing/details', [BillingDetailsController::class, 'update'])->middleware('throttle:20,1')->name('billing.details.update');
    Route::get('billing/checkout/status', [CheckoutController::class, 'status'])
        ->middleware('throttle:60,1')->name('billing.checkout.status');
    Route::get('billing/checkout', [CheckoutController::class, 'show'])
        ->name('billing.checkout.show');
    Route::get('billing/plans', [CheckoutController::class, 'plans'])
        ->name('billing.plans.index');
    Route::patch('billing/plan', [CheckoutController::class, 'changePlan'])
        ->middleware('throttle:10,1')
        ->name('billing.plan.update');
    Route::post('billing/checkout', CreateCheckoutController::class)
        ->middleware('throttle:10,1')
        ->name('billing.checkout.store');

    Route::get('dashboard', [WorkspaceController::class, 'show'])
        ->middleware('subscription.active')
        ->name('dashboard');
    Route::post('workspace/setup', [WorkspaceController::class, 'update'])->middleware(['subscription.active', 'throttle:10,1'])->name('workspace.setup');
    Route::post('workspace/invitations', [InvitationController::class, 'store'])->middleware(['subscription.active', 'throttle:5,1'])->name('workspace.invite');
    Route::delete('workspace/invitations/{invitation}', [InvitationController::class, 'destroy'])->middleware('subscription.active')->name('workspace.revoke');
});

require __DIR__.'/settings.php';
