<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AccountingDocumentController;
use App\Http\Controllers\AccountingReportController;
use App\Http\Controllers\Billing\CheckoutController;
use App\Http\Controllers\Billing\CreateCheckoutController;
use App\Http\Controllers\InvitationController;
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
        Route::get('companies/{company}', [AccountingController::class, 'show'])->name('show');
        Route::get('companies/{company}/reports', [AccountingReportController::class, 'index'])->name('reports');
        Route::get('companies/{company}/export', [AccountingReportController::class, 'export'])->middleware('throttle:10,1')->name('export');
        Route::get('companies/{company}/sales', [SalesController::class, 'index'])->name('sales');
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
        Route::post('companies/{company}/purchases/suppliers', [PurchaseController::class, 'supplier'])->middleware('throttle:30,1')->name('purchaseSupplier');
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
    });
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
