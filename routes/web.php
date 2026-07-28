<?php

use App\Http\Controllers\AccountingController;
use App\Http\Controllers\AresLookupController;
use App\Http\Controllers\BankController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\InvoicePreviewController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Settings\InvoicingController;
use App\Http\Controllers\Settings\OrganizationController;
use App\Http\Controllers\TaxController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Route;

// Local platform: no public website, account, or sign-in screen.
Route::redirect('/', '/prehled');

Route::get('/prehled', DashboardController::class)->name('dashboard');
Route::get('/hledat', [SearchController::class, 'search'])->name('search');
Route::get('/ares/{ico}', AresLookupController::class)->name('ares.lookup');

Route::get('/klienti', [ClientController::class, 'index'])->name('clients.index');
Route::get('/klienti/novy', [ClientController::class, 'create'])->name('clients.create');
Route::post('/klienti', [ClientController::class, 'store'])->name('clients.store');
Route::get('/klienti/{client}/upravit', [ClientController::class, 'edit'])->name('clients.edit');
Route::get('/klienti/{client}', [ClientController::class, 'show'])->name('clients.show');
Route::put('/klienti/{client}', [ClientController::class, 'update'])->name('clients.update');
Route::delete('/klienti/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

Route::get('/faktury', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/faktury/nova', [InvoiceController::class, 'create'])->name('invoices.create');
// Náhledy nic neukládají; vynechání CSRF zabraňuje 419 po obnově lokální cache.
Route::post('/faktury/nahled', [InvoicePreviewController::class, 'invoice'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('invoices.preview');
Route::post('/faktury', [InvoiceController::class, 'store'])->name('invoices.store');
Route::get('/faktury/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
Route::get('/faktury/{invoice}/upravit', [InvoiceController::class, 'edit'])->name('invoices.edit');
Route::put('/faktury/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
Route::delete('/faktury/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
Route::delete('/faktury/{invoice}/trvale', [InvoiceController::class, 'forceDestroy'])->name('invoices.force-destroy');
Route::post('/faktury/{invoice}/vystavit', [InvoiceController::class, 'issue'])->name('invoices.issue');
Route::post('/faktury/{invoice}/zaplaceno', [InvoiceController::class, 'markPaid'])->name('invoices.paid');
Route::post('/faktury/{invoice}/storno', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
Route::post('/faktury/{invoice}/duplikovat', [InvoiceController::class, 'duplicate'])->name('invoices.duplicate');
Route::get('/faktury/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
Route::get('/faktury/{invoice}/isdoc', [InvoiceController::class, 'isdoc'])->name('invoices.isdoc');
Route::post('/faktury/{invoice}/odeslat', [InvoiceController::class, 'send'])->name('invoices.send');

Route::get('/banka', [BankController::class, 'index'])->name('bank.index');
Route::post('/banka/sync', [BankController::class, 'sync'])->name('bank.sync');
Route::post('/banka/overit-platby', [BankController::class, 'verifyPayments'])->name('bank.verify-payments');
Route::post('/banka/napojeni', [BankController::class, 'storeConnection'])->name('bank.connections.store');
Route::delete('/banka/napojeni/{connection}', [BankController::class, 'destroyConnection'])->name('bank.connections.destroy');
Route::post('/banka/import', [BankController::class, 'import'])->name('bank.import');
Route::get('/banka/export', [BankController::class, 'export'])->name('bank.export');
Route::post('/banka/transakce/{transaction}/sparovat', [BankController::class, 'match'])->name('bank.match');
Route::delete('/banka/parovani/{match}', [BankController::class, 'unmatch'])->name('bank.unmatch');

Route::get('/ucetnictvi', [AccountingController::class, 'show'])->name('accounting.index');
Route::get('/ucetnictvi/export/zip', [AccountingController::class, 'exportZip'])->name('accounting.export.zip');
Route::get('/ucetnictvi/export/faktury-csv', [AccountingController::class, 'exportInvoicesCsv'])->name('accounting.export.invoices-csv');
Route::get('/ucetnictvi/export/transakce-csv', [AccountingController::class, 'exportTransactionsCsv'])->name('accounting.export.transactions-csv');
Route::get('/ucetnictvi/export/prehled-xlsx', [AccountingController::class, 'exportXlsx'])->name('accounting.export.xlsx');
Route::get('/ucetnictvi/export/pohoda-xml', [AccountingController::class, 'exportPohoda'])->name('accounting.export.pohoda');
Route::get('/ucetnictvi/export/money-xml', [AccountingController::class, 'exportMoney'])->name('accounting.export.money');
Route::get('/ucetnictvi/sablona/klienti', [AccountingController::class, 'templateClients'])->name('accounting.template.clients');
Route::get('/ucetnictvi/sablona/faktury', [AccountingController::class, 'templateInvoices'])->name('accounting.template.invoices');
Route::post('/ucetnictvi/import/klienti', [AccountingController::class, 'importClients'])->name('accounting.import.clients');
Route::post('/ucetnictvi/import/faktury', [AccountingController::class, 'importInvoices'])->name('accounting.import.invoices');
Route::post('/ucetnictvi/import/isdoc', [AccountingController::class, 'importIsdoc'])->name('accounting.import.isdoc');
Route::get('/dane', [TaxController::class, 'show'])->name('tax.index');
Route::put('/dane/{year}/nastaveni', [TaxController::class, 'update'])->name('tax.update');

Route::redirect('/nastaveni', '/nastaveni/fakturace')->name('settings.index');
Route::get('/nastaveni/fakturace', [InvoicingController::class, 'show'])->name('settings.invoicing');
Route::post('/nastaveni/fakturace/nahled', [InvoicePreviewController::class, 'organization'])
    ->withoutMiddleware(ValidateCsrfToken::class)
    ->name('settings.invoicing.preview');
Route::post('/nastaveni/fakturace/rady', [InvoicingController::class, 'storeSeries'])->name('settings.series.store');
Route::delete('/nastaveni/fakturace/rady/{series}', [InvoicingController::class, 'destroySeries'])->name('settings.series.destroy');
Route::post('/nastaveni/fakturace/ucty', [InvoicingController::class, 'storeAccount'])->name('settings.accounts.store');
Route::post('/nastaveni/fakturace/ucty/{account}/vychozi', [InvoicingController::class, 'setDefaultAccount'])->name('settings.accounts.default');
Route::delete('/nastaveni/fakturace/ucty/{account}', [InvoicingController::class, 'destroyAccount'])->name('settings.accounts.destroy');
Route::put('/nastaveni/firma', [OrganizationController::class, 'update'])->name('settings.organization.update');
Route::get('/nastaveni/firma/obrazek/{type}', [OrganizationController::class, 'showImage'])->name('settings.organization.image');
Route::post('/nastaveni/firma/obrazek/{type}', [OrganizationController::class, 'uploadImage'])->name('settings.organization.image.upload');
Route::delete('/nastaveni/firma/obrazek/{type}', [OrganizationController::class, 'deleteImage'])->name('settings.organization.image.delete');
