<?php

use App\Http\Controllers\Api\V1\AccountingController;
use App\Http\Controllers\Api\V1\BalanceController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\PurchaseDocumentController;
use App\Http\Controllers\Api\V1\SalesInvoiceController;
use App\Http\Controllers\Api\V1\SupplierController;
use Illuminate\Support\Facades\Route;

/*
| Public API v1 (/api/v1). Authenticated with an organization API key (Bearer), limited per key and logged.
*/
Route::prefix('v1')->name('api.v1.')->middleware(['api.token', 'throttle:atlantia-api'])->group(function () {
    Route::get('me', [CompanyController::class, 'me'])->name('me');
    Route::get('companies', [CompanyController::class, 'index'])->name('companies.index');

    Route::prefix('companies/{company}')->whereUuid('company')->group(function () {
        Route::get('/', [CompanyController::class, 'show'])->name('companies.show');

        Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
        Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
        Route::get('customers/{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->name('customers.show');
        Route::patch('customers/{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');

        Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
        Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');

        Route::get('sales-invoices', [SalesInvoiceController::class, 'index'])->name('salesInvoices.index');
        Route::post('sales-invoices', [SalesInvoiceController::class, 'store'])->name('salesInvoices.store');
        Route::get('sales-invoices/{invoice}', [SalesInvoiceController::class, 'show'])->whereUuid('invoice')->name('salesInvoices.show');

        Route::get('purchase-documents', [PurchaseDocumentController::class, 'index'])->name('purchaseDocuments.index');
        Route::post('purchase-documents', [PurchaseDocumentController::class, 'store'])->name('purchaseDocuments.store');
        Route::get('purchase-documents/{document}', [PurchaseDocumentController::class, 'show'])->whereUuid('document')->name('purchaseDocuments.show');

        Route::get('receivables', [BalanceController::class, 'receivables'])->name('receivables');
        Route::get('payables', [BalanceController::class, 'payables'])->name('payables');

        Route::get('accounts', [AccountingController::class, 'accounts'])->name('accounts');
        Route::get('reports/{report}', [AccountingController::class, 'report'])->whereIn('report', ['trial-balance', 'income-statement', 'balance-sheet'])->name('reports');
        Route::get('fel-documents', [AccountingController::class, 'felDocuments'])->name('felDocuments.index');
        Route::get('fel-documents/{document}', [AccountingController::class, 'felDocument'])->whereUuid('document')->name('felDocuments.show');
    });
});
