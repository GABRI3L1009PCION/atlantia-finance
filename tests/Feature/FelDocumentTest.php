<?php

use App\Jobs\CertifyFelDocumentJob;
use App\Jobs\GenerateInvoicePdfJob;
use App\Mail\FelInvoiceMail;
use App\Models\FelDocument;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\Fel\FelAccountingService;
use App\Services\Fel\FelCancellationService;
use App\Services\Fel\FelCertificationService;
use App\Services\Fel\FelDocumentBuilder;
use App\Services\Fel\FelDocumentService;
use App\Services\Fel\FelDocumentStatus;
use App\Services\Fel\FelEmailService;
use App\Services\Fel\FelIssuerSettings;
use App\Services\Fel\FelPdfService;
use App\Services\Fel\FelProviderInterface;
use App\Services\Fel\FelProviderResolver;
use App\Services\Fel\MockFelProvider;
use App\Services\Fel\SatRegistrationService;
use App\Services\Fel\SatXmlReader;
use App\Services\Fel\SubscriptionFelService;
use App\Services\FiscalConfigurationService;
use App\Services\Receivables\ReceivableService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    Queue::fake();
    Http::preventStrayRequests();
    Storage::fake('local');
    config(['fel.provider' => 'mock', 'fel.environment' => 'test', 'fel.queue_connection' => 'database']);
});

test('authorized owner creates exact decimal draft ignoring forged protected fields', function () {
    $f = felFixture();
    $this->actingAs($f['owner'])->post(route('accounting.fel.store', $f['uuid']), [
        ...felInput($f), 'company_id' => 999, 'total_cents' => 1, 'tax_rate' => 0, 'status' => 'certified', 'fel_provider' => 'real',
    ])->assertRedirect()->assertSessionHasNoErrors();
    $doc = FelDocument::firstOrFail();
    expect($doc->company_id)->toBe($f['companyId'])->and($doc->status)->toBe(FelDocumentStatus::Draft)
        ->and($doc->subtotal_cents)->toBe(20002)->and($doc->discount_cents)->toBe(2)
        ->and($doc->tax_cents)->toBe(2400)->and($doc->total_cents)->toBe(22400)
        ->and($doc->fiscal_snapshot['nit'])->toBe($f['nit']);
    Queue::assertNothingPushed();
});

test('tenant isolation covers document actions and downloads', function () {
    $f = felFixture();
    $other = felFixture();
    $doc = felDraft($f);
    $this->actingAs($other['owner']);
    foreach (['index', 'create'] as $name) {
        $this->get(route('accounting.fel.'.$name, $f['uuid']))->assertNotFound();
    }
    foreach (['show', 'edit', 'pdf'] as $name) {
        $this->get(route('accounting.fel.'.$name, [$f['uuid'], $doc->public_id]))->assertNotFound();
        $this->get(route('accounting.fel.'.$name, [$other['uuid'], $doc->public_id]))->assertNotFound();
    }
    $this->post(route('accounting.fel.store', $f['uuid']), felInput($f))->assertNotFound();
    foreach (['certify', 'cancel', 'email', 'accounting', 'regeneratePdf'] as $name) {
        $this->post(route('accounting.fel.'.$name, [$other['uuid'], $doc->public_id]), ['reason' => 'Prueba de acceso'])->assertNotFound();
    }
});

test('viewer cannot write and member needs office and company assignments', function () {
    $f = felFixture();
    $member = User::factory()->create();
    $f['organization']->users()->attach($member, ['role' => 'member', 'status' => 'active']);
    $this->actingAs($member)->get(route('accounting.fel.index', $f['uuid']))->assertNotFound();
    $f['office']->users()->attach($member, ['role' => 'member', 'status' => 'active']);
    DB::table('company_user')->insert(['company_id' => $f['companyId'], 'user_id' => $member->id, 'permission' => 'viewer']);
    $this->get(route('accounting.fel.index', $f['uuid']))->assertOk();
    $this->post(route('accounting.fel.store', $f['uuid']), felInput($f))->assertForbidden();
});

test('cross-company customers are rejected', function () {
    $f = felFixture();
    $other = felFixture();
    $this->actingAs($f['owner'])->post(route('accounting.fel.store', $f['uuid']), [
        ...felInput($f), 'customer_id' => $other['customerId'],
    ])->assertNotFound();
    $this->assertDatabaseCount('fel_documents', 0);
});

test('date effective tax changes use matching rules and missing dates fail', function () {
    $f = felFixture();
    app(FiscalConfigurationService::class)->ensureDefaults($f['companyId']);
    $original = DB::table('fiscal_tax_rules')->where('company_id', $f['companyId'])->where('code', 'IVA_GENERAL')->first();
    DB::table('fiscal_tax_rules')->where('id', $original->id)->update(['valid_to' => '2026-08-31']);
    $new = (array) $original;
    unset($new['id']);
    DB::table('fiscal_tax_rules')->insert([...$new, 'valid_from' => '2026-09-01', 'rate_basis_points' => 1500]);
    expect(felDraft($f, ['issued_on' => '2026-08-31'])->tax_cents)->toBe(2400)
        ->and(felDraft($f, ['issued_on' => '2026-09-01'])->tax_cents)->toBe(3000);
    expect(fn () => felDraft($f, ['issued_on' => '2012-12-31']))->toThrow(ValidationException::class);
});

test('small taxpayer gross income rule is not added as VAT', function () {
    $f = felFixture();
    app(FiscalConfigurationService::class)->ensureDefaults($f['companyId']);
    DB::table('company_fiscal_profiles')->where('company_id', $f['companyId'])->update(['vat_regime' => 'small_taxpayer']);
    $doc = felDraft($f);
    expect($doc->tax_cents)->toBe(0)->and($doc->total_cents)->toBe(20000);
});

test('sequence is unique per company and independent across companies', function () {
    $f = felFixture();
    $other = felFixture();
    $a = felDraft($f);
    $b = felDraft($f);
    $c = felDraft($other);
    expect($a->number)->toBe(1)->and($b->number)->toBe(2)->and($c->number)->toBe(1);
    expect(fn () => DB::table('fel_documents')->where('id', $b->id)->update(['number' => $a->number]))
        ->toThrow(QueryException::class);
});

test('invalid discounts roll back document and sequence', function () {
    $f = felFixture();
    $data = felInput($f);
    $data['lines'][0]['discount'] = '999999';
    $this->actingAs($f['owner'])->post(route('accounting.fel.store', $f['uuid']), $data)->assertSessionHasErrors();
    expect(felDraft($f)->number)->toBe(1);
});

test('mock certification and duplicate jobs preserve authorization and audit', function () {
    $f = felFixture();
    $doc = felDraft($f);
    app(FelDocumentService::class)->requestCertification($doc, $f['owner']->id);
    Queue::assertPushed(CertifyFelDocumentJob::class);
    $job = new CertifyFelDocumentJob($f['companyId'], $doc->public_id);
    $job->handle(app(FelCertificationService::class));
    $authorization = $doc->fresh()->authorization_number;
    $job->handle(app(FelCertificationService::class));
    expect(Str::isUuid($authorization))->toBeTrue()->and($doc->fresh()->authorization_number)->toBe($authorization)
        ->and($doc->fresh()->status)->toBe(FelDocumentStatus::Certified);
    $this->assertDatabaseCount('fel_mock_receipts', 1);
    expect(DB::table('audit_logs')->where('action', 'fel.certified')->count())->toBe(1);
    Queue::assertPushed(GenerateInvoicePdfJob::class);
    Http::assertNothingSent();
});

test('provider retries reuse result and reject altered payload', function () {
    $provider = app(FelProviderInterface::class);
    $key = (string) Str::uuid();
    $result = $provider->certify(['number' => 1], $key);
    expect($provider->certify(['number' => 1], $key))->toBe($result)->and($provider->getStatus($key)['simulated'])->toBeTrue();
    expect(fn () => $provider->certify(['number' => 2], $key))->toThrow(LogicException::class);
});

test('worker recovers after provider accepted before local certification save', function () {
    $f = felFixture();
    $doc = felDraft($f);
    app(FelDocumentService::class)->requestCertification($doc, $f['owner']->id);
    $doc->refresh();
    $doc->transition(FelDocumentStatus::Processing);
    $payload = app(FelDocumentBuilder::class)->build($doc);
    $doc->forceFill(['provider_payload' => $payload])->save();
    $result = app(FelProviderInterface::class)->certify($payload, $doc->public_id);
    app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id);
    expect($doc->fresh()->authorization_number)->toBe($result['authorization_number']);
    $this->assertDatabaseCount('fel_mock_receipts', 1);
});

test('invalid transitions are rejected', function (string $from, string $to) {
    expect(fn () => FelDocumentStatus::from($from)->transitionTo(FelDocumentStatus::from($to)))->toThrow(ValidationException::class);
})->with([['draft', 'certified'], ['pending', 'cancelled'], ['certified', 'draft'], ['cancelled', 'pending'], ['failed', 'certified']]);

test('issued documents retain fiscal snapshot and cannot be edited or deleted', function () {
    $f = felFixture();
    $doc = felCertified($f);
    DB::table('companies')->where('id', $f['companyId'])->update(['name' => 'Nombre posterior', 'tax_id' => 'POSTERIOR']);
    expect($doc->fresh()->fiscal_snapshot['nit'])->toBe($f['nit']);
    $this->actingAs($f['owner'])->patch(route('accounting.fel.update', [$f['uuid'], $doc->public_id]), felInput($f))->assertSessionHasErrors();
    expect(fn () => $doc->forceFill(['total_cents' => 1])->save())->toThrow(ValidationException::class);
    expect(fn () => $doc->fresh()->delete())->toThrow(ValidationException::class);
});

test('certification failures are sanitized and manually recoverable', function () {
    $f = felFixture();
    $doc = felDraft($f);
    $service = app(FelDocumentService::class);
    $service->requestCertification($doc, $f['owner']->id);
    $this->mock(MockFelProvider::class)->shouldReceive('certify')->once()->andThrow(new RuntimeException('token=SUPER_SECRET'));
    expect(fn () => app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id))->toThrow(RuntimeException::class, 'No fue posible completar');
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::Failed);
    expect(json_encode(DB::table('audit_logs')->get()))->not->toContain('SUPER_SECRET');
    app()->forgetInstance(MockFelProvider::class);
    $service->requestCertification($doc->fresh(), $f['owner']->id);
    app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id);
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::Certified);
});

test('private PDF is idempotent and clearly identifies simulation', function () {
    $f = felFixture();
    $doc = felCertified($f);
    $service = app(FelPdfService::class);
    $service->generate($f['companyId'], $doc->public_id);
    $service->generate($f['companyId'], $doc->public_id);
    expect(Storage::disk('local')->get($doc->fresh()->pdf_path))->toStartWith('%PDF');
    expect(view('fel.invoice', ['document' => $doc->fresh()->load('lines')])->render())->toContain('NO VÁLIDO ANTE SAT');
    expect(DB::table('audit_logs')->where('action', 'fel.pdf_generated')->count())->toBe(1);
    $this->actingAs($f['owner'])->get(route('accounting.fel.pdf', [$f['uuid'], $doc->public_id]))->assertOk()->assertDownload();
});

test('email sends once with the matching tenant PDF', function () {
    Mail::fake();
    $f = felFixture();
    $doc = felCertified($f);
    app(FelPdfService::class)->generate($f['companyId'], $doc->public_id);
    $service = app(FelEmailService::class);
    $service->send($f['companyId'], $doc->public_id);
    $service->send($f['companyId'], $doc->public_id);
    Mail::assertSent(FelInvoiceMail::class, fn ($mail) => $mail->document->company_id === $f['companyId'] && $mail->hasTo('client@example.test'));
    Mail::assertSentCount(1);
});

test('Resend uses stable idempotency key without exposing credentials', function () {
    $f = felFixture();
    $doc = felCertified($f);
    app(FelPdfService::class)->generate($f['companyId'], $doc->public_id);
    config(['mail.default' => 'resend', 'services.resend.key' => 'test-token']);
    Http::fake(['api.resend.com/emails' => Http::response(['id' => 'test-message'])]);
    app(FelEmailService::class)->send($f['companyId'], $doc->public_id);
    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'fel/'.$doc->public_id) && $request['to'] === ['client@example.test']);
    expect($doc->fresh()->toJson())->not->toContain('test-token');
});

test('foreign worker scope and forged PDF path cannot leak attachments', function () {
    Mail::fake();
    $f = felFixture();
    $other = felFixture();
    $doc = felCertified($f);
    expect(fn () => app(FelPdfService::class)->generate($other['companyId'], $doc->public_id))->toThrow(ModelNotFoundException::class);
    $doc->forceFill(['pdf_path' => 'fel/'.$other['companyId'].'/secret.pdf'])->save();
    $this->actingAs($f['owner'])->get(route('accounting.fel.pdf', [$f['uuid'], $doc->public_id]))->assertNotFound();
    expect(fn () => app(FelEmailService::class)->send($f['companyId'], $doc->public_id))->toThrow(RuntimeException::class);
    Mail::assertNothingSent();
});

test('accounting creates one balanced draft with two way source reference', function () {
    $f = felFixture();
    $doc = felCertified($f);
    app(AccountingService::class)->installDefaultChart($f['companyId']);
    DB::table('accounting_periods')->insert(['company_id' => $f['companyId'], 'name' => 'Septiembre', 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'created_at' => now(), 'updated_at' => now()]);
    $service = app(FelAccountingService::class);
    $entry = $service->draft($doc, $f['organization'], $f['owner']);
    expect($service->draft($doc, $f['organization'], $f['owner']))->toBe($entry);
    $this->assertDatabaseHas('accounting_source_entries', ['source_type' => 'fel_document', 'source_id' => $doc->id, 'journal_entry_id' => $entry]);
    $this->assertDatabaseCount('journal_entries', 1);
    expect((int) DB::table('journal_lines')->sum('debit_cents'))->toBe(22400)->and((int) DB::table('journal_lines')->sum('credit_cents'))->toBe(22400);
});

test('cancellation records reason and actor and invalidates previous PDF', function () {
    $f = felFixture();
    $doc = felCertified($f);
    app(FelPdfService::class)->generate($f['companyId'], $doc->public_id);
    $this->actingAs($f['owner'])->post(route('accounting.fel.cancel', [$f['uuid'], $doc->public_id]), ['reason' => 'Documento de prueba duplicado'])->assertRedirect()->assertSessionHasNoErrors();
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::Cancelled)->and($doc->fresh()->pdf_path)->toBeNull();
    expect(app(FelProviderInterface::class)->getStatus($doc->public_id)['status'])->toBe('cancelled');
    $this->assertDatabaseHas('audit_logs', ['action' => 'fel.cancelled', 'user_id' => $f['owner']->id]);
    $this->get(route('accounting.fel.pdf', [$f['uuid'], $doc->public_id]))->assertNotFound();
});

test('unimplemented providers and non-test FEL environment fail closed', function () {
    expect(fn () => app(FelProviderResolver::class)->resolve('real', 'test'))->toThrow(LogicException::class);
    expect(fn () => app(FelProviderResolver::class)->resolve('mock', 'production'))->toThrow(LogicException::class);
    Http::assertNothingSent();
});

test('document pages do not expose provider payloads or private paths', function () {
    $f = felFixture();
    $doc = felCertified($f);
    $this->actingAs($f['owner'])->get(route('accounting.fel.show', [$f['uuid'], $doc->public_id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->component('accounting/fel/show')
        ->missing('document.provider_payload')->missing('document.provider_response')->missing('document.pdf_path')
        ->where('document.provider', 'mock')->where('document.environment', 'test'));
});

test('a certified FEL credit note credits its internal invoice once and cancelling it restores the balance', function () {
    $f = felFixture();
    $invoice = DB::table('sales_invoices')->insertGetId(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'sales_customer_id' => $f['customerId'], 'number' => 1, 'status' => 'issued',
        'issued_on' => '2026-09-01', 'due_on' => '2026-10-01', 'total_cents' => 100000, 'customer_name' => 'Cliente de prueba', 'created_by' => $f['owner']->id, 'issued_by' => $f['owner']->id, 'issued_at' => now()]);
    expect(fn () => felDraft($f, ['document_type' => 'NCRE']))->toThrow(ValidationException::class);
    $doc = felDraft($f, ['document_type' => 'NCRE', 'sales_invoice_id' => $invoice]);
    expect($doc->sales_invoice_id)->toBe($invoice);
    app(FelDocumentService::class)->requestCertification($doc, $f['owner']->id);
    app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id);
    app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id);
    $notes = DB::table('sales_credit_notes')->where('fel_document_id', $doc->id)->get();
    expect($notes)->toHaveCount(1)->and((int) $notes[0]->amount_cents)->toBe((int) $doc->fresh()->total_cents)
        ->and(app(ReceivableService::class)->find($invoice)['outstanding_cents'])->toBe(100000 - (int) $doc->fresh()->total_cents);
    expect(fn () => app(FelAccountingService::class)->draft($doc->fresh(), $f['organization'], $f['owner']))->toThrow(ValidationException::class);

    app(FelCancellationService::class)->cancel($doc->fresh(), $f['organization'], $f['owner'], 'Emitida por error');
    expect(DB::table('sales_credit_notes')->where('fel_document_id', $doc->id)->value('status'))->toBe('void')
        ->and(app(ReceivableService::class)->find($invoice)['outstanding_cents'])->toBe(100000);
});

function felFixture(): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Oficina FEL '.Str::random(8), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $organization = $owner->organizations()->firstOrFail();
    $price = PlanPrice::whereHas('plan', fn ($q) => $q->where('code', 'essential'))->where('billing_period', 'monthly')->firstOrFail();
    $subscription = $organization->subscriptions()->create(['status' => 'active']);
    $subscription->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $nit = Str::upper(Str::random(8));
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Empresa FEL',
        'tax_id' => $nit, 'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    $customerId = DB::table('sales_customers')->insertGetId(['company_id' => $companyId, 'name' => 'Cliente de prueba',
        'tax_id' => 'CF', 'email' => 'client@example.test', 'created_at' => now(), 'updated_at' => now()]);

    return compact('owner', 'organization', 'office', 'uuid', 'nit', 'companyId', 'customerId');
}

function felInput(array $f): array
{
    return ['issued_on' => '2026-09-05', 'currency' => 'GTQ', 'document_type' => 'FACT', 'customer_id' => $f['customerId'],
        'receiver_name' => 'Cliente de prueba', 'receiver_nit' => 'CF', 'receiver_address' => 'Guatemala', 'receiver_email' => 'client@example.test', 'intent' => 'draft',
        'lines' => [['item' => 'Servicio', 'description' => 'Servicio de prueba', 'classification' => 'service', 'quantity' => '2', 'price' => '100.01', 'discount' => '0.02']]];
}

function felDraft(array $f, array $override = []): FelDocument
{
    $company = app(AccountingService::class)->company($f['organization'], $f['owner'], $f['uuid'], true);

    return app(FelDocumentService::class)->save($f['organization'], $f['owner'], $company, [...felInput($f), ...$override]);
}

function felCertified(array $f): FelDocument
{
    $doc = felDraft($f);
    app(FelDocumentService::class)->requestCertification($doc, $f['owner']->id);
    app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id);

    return $doc->fresh();
}

test('draft edits recalculate and issue asynchronously', function () {
    $f = felFixture();
    $doc = felDraft($f);
    $input = felInput($f);
    $input['lines'][0]['quantity'] = '1.2500';
    $input['lines'][0]['price'] = '10.01';
    $input['lines'][0]['discount'] = '0';
    $input['intent'] = 'issue';
    $this->actingAs($f['owner'])->patch(route('accounting.fel.update', [$f['uuid'], $doc->public_id]), $input)->assertRedirect()->assertSessionHasNoErrors();
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::Pending)->and($doc->fresh()->subtotal_cents)->toBe(1251)
        ->and($doc->fresh()->tax_cents)->toBe(150)->and($doc->fresh()->total_cents)->toBe(1401);
    Queue::assertPushed(CertifyFelDocumentJob::class, fn ($job) => $job->companyId === $f['companyId'] && $job->connection === 'database');
    $this->assertDatabaseCount('fel_mock_receipts', 0);
});

test('stale fiscal draft must be reviewed before emission', function () {
    $f = felFixture();
    $doc = felDraft($f);
    DB::table('fiscal_tax_rules')->where('company_id', $f['companyId'])->where('code', 'IVA_GENERAL')->update(['rate_basis_points' => 1300]);
    expect(fn () => app(FelDocumentService::class)->requestCertification($doc, $f['owner']->id))->toThrow(ValidationException::class);
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::Draft);
});

test('expired Resend retry window requires review instead of resending', function () {
    $f = felFixture();
    $doc = felCertified($f);
    app(FelPdfService::class)->generate($f['companyId'], $doc->public_id);
    $doc->forceFill(['email_started_at' => now()->subDays(2)])->save();
    config(['mail.default' => 'resend', 'services.resend.key' => 'test-token']);
    Http::fake();
    app(FelEmailService::class)->send($f['companyId'], $doc->public_id);
    Http::assertNothingSent();
    expect($doc->fresh()->email_status)->toBe('needs_review');
});

test('subscription billing requires explicit issuer and is idempotent for confirmed payment', function () {
    $issuer = felFixture();
    $buyer = felFixture();
    $buyer['organization']->update(['tax_identifier' => '1234567', 'legal_name' => 'Cliente SaaS']);
    $buyer['office']->update(['address_line_1' => 'Zona 10, Guatemala']);
    $payment = Payment::create([
        'organization_id' => $buyer['organization']->id, 'subscription_id' => $buyer['organization']->subscriptions()->first()->id,
        'provider' => 'recurrente', 'provider_payment_id' => 'test-paid', 'currency_code' => 'GTQ', 'amount' => '399.00',
        'status' => 'succeeded', 'paid_at' => '2026-09-05 12:00:00',
    ]);
    DB::table('fel_subscription_requests')->insert(['payment_id' => $payment->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    config(['fel.subscription_issuer' => null]);
    app(SubscriptionFelService::class)->generate($payment->id);
    $this->assertDatabaseCount('fel_documents', 0);
    $this->assertDatabaseHas('fel_subscription_requests', ['status' => 'needs_configuration']);
    config(['fel.subscription_issuer' => $issuer['uuid']]);
    app(SubscriptionFelService::class)->generate($payment->id);
    app(SubscriptionFelService::class)->generate($payment->id);
    $doc = FelDocument::firstOrFail();
    expect($doc->company_id)->toBe($issuer['companyId'])->and($doc->total_cents)->toBe(39900)
        ->and($doc->receiver_snapshot['name'])->toBe('Cliente SaaS')->and($doc->status)->toBe(FelDocumentStatus::Pending);
    $this->assertDatabaseCount('fel_documents', 1);
});

test('recovery command restores pending certification and post certification jobs', function () {
    $f = felFixture();
    $pending = felDraft($f, ['intent' => 'issue']);
    $certified = felCertified($f);
    DB::table('fel_documents')->update(['updated_at' => now()->subMinutes(10)]);
    Queue::fake();
    $this->artisan('fel:recover')->assertSuccessful();
    Queue::assertPushed(CertifyFelDocumentJob::class, fn ($job) => $job->documentId === $pending->public_id);
    Queue::assertPushed(GenerateInvoicePdfJob::class, fn ($job) => $job->documentId === $certified->public_id);
});

test('tenant issuer settings are isolated and cannot enable a real provider', function () {
    $f = felFixture();
    $other = felFixture();
    $input = ['name' => 'Emisor independiente', 'nit' => '12345678', 'address' => 'Zona de pruebas', 'provider' => 'mock', 'environment' => 'test'];
    $this->actingAs($f['owner'])->post(route('accounting.fel.settings.update', $other['uuid']), $input)->assertNotFound();
    $this->post(route('accounting.fel.settings.update', $f['uuid']), [...$input, 'provider' => 'real'])->assertSessionHasErrors('provider');
    $this->post(route('accounting.fel.settings.update', $f['uuid']), [...$input, 'company_id' => $other['companyId'], 'platform' => true])->assertSessionHasNoErrors();
    $doc = felDraft($f);
    expect($doc->fiscal_snapshot['name'])->toBe('Emisor independiente')->and($doc->fiscal_snapshot['nit'])->toBe('12345678');
    expect(felDraft($other)->fiscal_snapshot['name'])->toBe('Empresa FEL');
    $this->assertDatabaseCount('platform_fel_issuer', 0);
    $member = User::factory()->create();
    $f['organization']->users()->attach($member, ['role' => 'member', 'status' => 'active']);
    // A member without access to the company cannot even confirm it exists.
    $this->actingAs($member)->post(route('accounting.fel.settings.update', $f['uuid']), $input)->assertNotFound();
});

test('logo uploads are restricted and emitted issuer snapshots stay unchanged', function () {
    $f = felFixture();
    $input = ['name' => 'Emisor con logo', 'nit' => '12345678', 'address' => 'Guatemala', 'provider' => 'mock', 'environment' => 'test'];
    $this->actingAs($f['owner'])->post(route('accounting.fel.settings.update', $f['uuid']), [...$input,
        'logo' => UploadedFile::fake()->create('unsafe.svg', 1, 'image/svg+xml'),
    ])->assertSessionHasErrors('logo');
    $this->post(route('accounting.fel.settings.update', $f['uuid']), [...$input,
        'logo' => UploadedFile::fake()->image('logo.png', 100, 50),
    ])->assertSessionHasNoErrors();
    $doc = felCertified($f);
    $snapshot = $doc->fiscal_snapshot;
    expect($snapshot['logo_data_uri'])->toStartWith('data:image/png;base64,');
    $this->post(route('accounting.fel.settings.update', $f['uuid']), [...$input, 'name' => 'Nuevo nombre', 'remove_logo' => true])->assertSessionHasNoErrors();
    expect($doc->fresh()->fiscal_snapshot)->toBe($snapshot);
    expect(app(FelDocumentBuilder::class)->build($doc)['issuer'])->not->toHaveKey('logo_data_uri');
});

test('platform invoices subscriptions with Atlantia identity and the original buyer snapshot', function () {
    Mail::fake();
    $buyer = felFixture();
    $admin = User::factory()->create();
    $this->artisan('fel:setup-platform', ['owner' => $admin->email, '--address' => config('fel.platform.address')])->assertSuccessful();
    $uuid = app(FelIssuerSettings::class)->platformCompanyUuid();
    $issuer = DB::table('companies')->where('public_id', $uuid)->firstOrFail();
    $this->artisan('fel:setup-platform', ['owner' => $admin->email, '--address' => 'Otra dirección'])->assertSuccessful();
    $this->assertDatabaseCount('platform_fel_issuer', 1);
    $payment = Payment::create(['organization_id' => $buyer['organization']->id,
        'subscription_id' => $buyer['organization']->subscriptions()->firstOrFail()->id, 'provider' => 'recurrente',
        'provider_payment_id' => 'confirmed-platform-test', 'currency_code' => 'GTQ', 'amount' => '799.00', 'status' => 'succeeded', 'paid_at' => now(),
        'billing_snapshot' => ['name' => 'Comprador original', 'nit' => 'CF', 'address' => 'Dirección original',
            'email' => 'buyer@example.test', 'plan_name' => 'Profesional', 'billing_period' => 'monthly'],
    ]);
    $buyer['organization']->update(['legal_name' => 'Nombre modificado', 'tax_identifier' => '999']);
    DB::table('fel_subscription_requests')->insert(['payment_id' => $payment->id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
    app(SubscriptionFelService::class)->generate($payment->id);
    app(SubscriptionFelService::class)->generate($payment->id);
    $doc = FelDocument::where('payment_id', $payment->id)->firstOrFail();
    expect($doc->company_id)->toBe($issuer->id)->and($doc->company_id)->not->toBe($buyer['companyId'])
        ->and($doc->fiscal_snapshot['name'])->toBe('Atlantia Systems')
        ->and($doc->fiscal_snapshot['nit'])->toBe('97655988')
        ->and($doc->fiscal_snapshot['logo_data_uri'])->toStartWith('data:image/png;base64,')
        ->and($doc->receiver_snapshot['name'])->toBe('Comprador original')
        ->and($doc->receiver_snapshot['email'])->toBe('buyer@example.test')->and($doc->total_cents)->toBe(79900);
    // Atlantia emits its own subscription invoices in the SAT portal and registers what the SAT returned.
    expect($doc->status)->toBe(FelDocumentStatus::AwaitingSat)->and($doc->provider)->toBe('sat_manual');
    app(SatRegistrationService::class)->register($doc, $admin, ['authorization_number' => 'PLAT-AUTH-1', 'fel_series' => 'AB12', 'fel_number' => '501', 'certified_at' => now()->toDateTimeString()]);
    app(FelPdfService::class)->generate($issuer->id, $doc->public_id);
    app(FelEmailService::class)->send($issuer->id, $doc->public_id);
    app(FelEmailService::class)->send($issuer->id, $doc->public_id);
    Mail::assertSent(FelInvoiceMail::class, fn ($mail) => $mail->hasTo('buyer@example.test') && $mail->document->payment_id === $payment->id);
    Mail::assertSentCount(1);
    expect($doc->fresh()->email_status)->toBe('sent');
    expect(FelPdfService::amountInWords(79900))->toBe('SETECIENTOS NOVENTA Y NUEVE QUETZALES CON 00/100');
    $pdf = Storage::disk('local')->get($doc->fresh()->pdf_path);
    expect($pdf)->toStartWith('%PDF');
    if ($path = getenv('FEL_PLATFORM_QA_PATH')) {
        file_put_contents($path, $pdf);
    }
    $this->actingAs($buyer['owner'])->get(route('accounting.fel.show', [$uuid, $doc->public_id]))->assertNotFound();
    $this->post(route('accounting.fel.settings.update', $uuid), [])->assertNotFound();
});

test('PDF supports long multiple-page detail without removing test disclaimer', function () {
    $f = felFixture();
    $input = felInput($f);
    $input['lines'] = array_fill(0, 35, $input['lines'][0]);
    $doc = felDraft($f, $input);
    app(FelDocumentService::class)->requestCertification($doc, $f['owner']->id);
    app(FelCertificationService::class)->certify($f['companyId'], $doc->public_id);
    app(FelPdfService::class)->generate($f['companyId'], $doc->public_id);
    $pdf = Storage::disk('local')->get($doc->fresh()->pdf_path);
    expect($pdf)->toStartWith('%PDF')->and(strlen($pdf))->toBeGreaterThan(10000);
    if ($path = getenv('FEL_PDF_QA_PATH')) {
        file_put_contents($path, $pdf);
    }
});

function satIssuerMode(array $f): void
{
    test()->actingAs($f['owner'])->post(route('accounting.fel.settings.update', $f['uuid']), [
        'name' => 'Empresa FEL', 'nit' => $f['nit'], 'address' => 'Guatemala', 'certifier' => 'sat_manual',
        'certifier_environment' => 'test', 'establishment_code' => '1', 'vat_affiliation' => 'GEN',
        // The screen used to send these two; a company that issues in the SAT portal already shows "production".
        'provider' => 'sat_manual', 'environment' => 'production',
    ])->assertSessionHasNoErrors();
}

function satXml(string $nit, string $total, string $authorization, string $receiver = 'CF'): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><dte:GTDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0" Version="0.1"><dte:SAT ClaseDocumento="dte"><dte:DTE ID="DatosCertificados"><dte:DatosEmision ID="DatosEmision">'
        .'<dte:DatosGenerales CodigoMoneda="GTQ" FechaHoraEmision="2026-09-05T10:00:00-06:00" Tipo="FACT"/>'
        .'<dte:Emisor AfiliacionIVA="GEN" CodigoEstablecimiento="1" NITEmisor="'.$nit.'" NombreEmisor="Empresa FEL"/>'
        .'<dte:Receptor IDReceptor="'.$receiver.'" NombreReceptor="Cliente de prueba"/>'
        .'<dte:Totales><dte:GranTotal>'.$total.'</dte:GranTotal></dte:Totales></dte:DatosEmision></dte:DTE>'
        .'<dte:Certificacion><dte:NITCertificador>1</dte:NITCertificador><dte:NumeroAutorizacion Numero="4501" Serie="AB12CD34">'.$authorization.'</dte:NumeroAutorizacion>'
        .'<dte:FechaHoraCertificacion>2026-09-05T10:00:05-06:00</dte:FechaHoraCertificacion></dte:Certificacion></dte:SAT></dte:GTDocumento>';
}

test('a company that issues in the SAT portal keeps the draft waiting and registers the XML the SAT gave back', function () {
    $f = felFixture();
    satIssuerMode($f);
    $doc = felDraft($f, ['intent' => 'issue']);

    expect($doc->status)->toBe(FelDocumentStatus::AwaitingSat)->and($doc->provider)->toBe('sat_manual')->and($doc->environment)->toBe('production')
        ->and($doc->awaiting_sat_at)->not->toBeNull();
    Queue::assertNotPushed(CertifyFelDocumentJob::class);
    $this->assertDatabaseCount('fel_mock_receipts', 0);

    $this->actingAs($f['owner'])->get(route('accounting.fel.show', [$f['uuid'], $doc->public_id]))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('accounting/fel/show')->where('notice', null)->where('document.status', 'awaiting_sat')->has('satPortalUrl'));

    $wrong = UploadedFile::fake()->createWithContent('fel.xml', satXml($f['nit'], '999.00', 'AAAA-1'));
    $this->post(route('accounting.fel.sat.register', [$f['uuid'], $doc->public_id]), ['sat_xml' => $wrong])->assertSessionHasErrors('sat_xml');
    $otherNit = UploadedFile::fake()->createWithContent('fel.xml', satXml('ZZZ999', '224.00', 'AAAA-1'));
    $this->post(route('accounting.fel.sat.register', [$f['uuid'], $doc->public_id]), ['sat_xml' => $otherNit])->assertSessionHasErrors('sat_xml');
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::AwaitingSat);

    $good = UploadedFile::fake()->createWithContent('fel.xml', satXml($f['nit'], '224.00', 'AAAA-1'));
    $this->post(route('accounting.fel.sat.register', [$f['uuid'], $doc->public_id]), ['sat_xml' => $good])->assertSessionHasNoErrors();
    $registered = $doc->fresh();
    expect($registered->status)->toBe(FelDocumentStatus::Certified)->and($registered->authorization_number)->toBe('AAAA-1')
        ->and($registered->fel_series)->toBe('AB12CD34')->and($registered->fel_number)->toBe('4501')->and($registered->sat_source)->toBe('xml')
        ->and($registered->sat_registered_by)->toBe($f['owner']->id)->and($registered->sat_xml_path)->not->toBeNull();
    Storage::disk('local')->assertExists($registered->sat_xml_path);
    Queue::assertPushed(GenerateInvoicePdfJob::class);
    $this->assertDatabaseHas('audit_logs', ['action' => 'fel.sat_registered', 'auditable_id' => $doc->id]);
});

test('without the XML the four key data register the document and duplicates are refused', function () {
    $f = felFixture();
    satIssuerMode($f);
    $first = felDraft($f, ['intent' => 'issue']);
    $second = felDraft($f, ['intent' => 'issue']);
    $data = ['authorization_number' => 'MANUAL-1', 'fel_series' => 'ZZ99', 'fel_number' => '77', 'certified_at' => '2026-09-05 10:00:00'];

    $this->actingAs($f['owner'])->post(route('accounting.fel.sat.register', [$f['uuid'], $first->public_id]), [...$data, 'authorization_number' => ''])->assertSessionHasErrors('authorization_number');
    $this->post(route('accounting.fel.sat.register', [$f['uuid'], $first->public_id]), $data)->assertSessionHasNoErrors();
    expect($first->fresh()->status)->toBe(FelDocumentStatus::Certified)->and($first->fresh()->sat_source)->toBe('manual');

    $this->post(route('accounting.fel.sat.register', [$f['uuid'], $second->public_id]), $data)->assertSessionHasErrors('authorization_number');
    $this->post(route('accounting.fel.sat.register', [$f['uuid'], $first->public_id]), [...$data, 'authorization_number' => 'OTRO'])->assertSessionHasErrors('document');

    $this->post(route('accounting.fel.sat.return', [$f['uuid'], $second->public_id]))->assertSessionHasNoErrors();
    expect($second->fresh()->status)->toBe(FelDocumentStatus::Draft)->and($second->fresh()->awaiting_sat_at)->toBeNull();
    $this->get(route('accounting.fel.edit', [$f['uuid'], $second->public_id]))->assertOk();
});

test('the accountant hands the portal step to an assistant, stale documents raise a warning once a day and the registered ones can be cancelled', function () {
    $f = felFixture();
    satIssuerMode($f);
    $f['office']->users()->attach($f['owner'], ['role' => 'member', 'status' => 'active']);
    $doc = felDraft($f, ['intent' => 'issue']);

    $taskId = app(SatRegistrationService::class)->assignTask($doc->fresh(), $f['organization'], $f['owner'], $f['owner']->id);
    $this->assertDatabaseHas('work_tasks', ['id' => $taskId, 'task_type' => 'fel_sat_issue', 'assigned_to' => $f['owner']->id, 'source_type' => 'fel_documents', 'source_id' => $doc->id]);
    expect(app(SatRegistrationService::class)->assignTask($doc->fresh(), $f['organization'], $f['owner'], $f['owner']->id))->toBe($taskId);

    $this->artisan('fel:sat-alerts')->assertSuccessful();
    $this->assertDatabaseMissing('work_notifications', ['type' => 'fel.sat_pending']);
    DB::table('fel_documents')->where('id', $doc->id)->update(['awaiting_sat_at' => now()->subDays(3)]);
    $this->artisan('fel:sat-alerts')->assertSuccessful();
    $this->assertDatabaseHas('work_notifications', ['user_id' => $f['owner']->id, 'type' => 'fel.sat_pending', 'related_type' => 'fel_documents', 'related_id' => $doc->id]);
    $this->artisan('fel:sat-alerts')->assertSuccessful();
    expect(DB::table('work_notifications')->where('type', 'fel.sat_pending')->count())->toBe(1);

    app(SatRegistrationService::class)->register($doc->fresh(), $f['owner'], ['authorization_number' => 'CANCEL-1', 'fel_series' => 'S1', 'fel_number' => '1', 'certified_at' => '2026-09-05 10:00:00']);
    app(FelCancellationService::class)->cancel($doc->fresh(), $f['organization'], $f['owner'], 'Anulada también en el portal de la SAT');
    expect($doc->fresh()->status)->toBe(FelDocumentStatus::Cancelled)->and($doc->fresh()->cancellation_response['source'])->toBe('manual');
});

test('the reader understands the DTE layout of the SAT and refuses the emission XML that has no certification', function () {
    $emission = <<<'XML'
<dte:GTDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0" Version="0.1">
  <dte:SAT ClaseDocumento="dte">
    <dte:DTE ID="DatosCertificados">
      <dte:DatosEmision ID="DatosEmision">
        <dte:DatosGenerales CodigoMoneda="GTQ" FechaHoraEmision="2026-10-13T11:26:10.000-06:00" Tipo="FACT"/>
        <dte:Emisor AfiliacionIVA="GEN" CodigoEstablecimiento="1" NITEmisor="123456789" NombreComercial="NOMBRE COMERCIAL, S.A." NombreEmisor="RAZÓN SOCIAL, SOCIEDAD ANÓNIMA">
          <dte:DireccionEmisor><dte:Direccion>3 Calle 6-11 Zona 9, Ciudad de Guatemala</dte:Direccion><dte:CodigoPostal>01009</dte:CodigoPostal><dte:Municipio>Guatemala</dte:Municipio><dte:Departamento>GUATEMALA</dte:Departamento></dte:DireccionEmisor>
        </dte:Emisor>
        <dte:Receptor CorreoReceptor="cliente@correo.com" IDReceptor="98765432" NombreReceptor="NOMBRE DEL CLIENTE">
          <dte:DireccionReceptor><dte:Direccion>10 Avenida 12-00 Zona 1, Ciudad de Guatemala</dte:Direccion><dte:CodigoPostal>01001</dte:CodigoPostal><dte:Municipio>Guatemala</dte:Municipio><dte:Departamento>GUATEMALA</dte:Departamento></dte:DireccionReceptor>
        </dte:Receptor>
        <dte:Frases><dte:Frase CodigoEscenario="1" TipoFrase="1"/></dte:Frases>
        <dte:Items><dte:Item BienOServicio="S" NumeroLinea="1"><dte:Cantidad>1.00</dte:Cantidad><dte:UnidadMedida>UNI</dte:UnidadMedida><dte:Descripcion>Servicio de consultoría profesional</dte:Descripcion><dte:PrecioUnitario>1000.00</dte:PrecioUnitario><dte:Precio>1000.00</dte:Precio><dte:Descuento>0.00</dte:Descuento><dte:Impuestos><dte:Impuesto><dte:NombreCorto>IVA</dte:NombreCorto><dte:CodigoUnidadGravable>1</dte:CodigoUnidadGravable><dte:MontoGravable>892.86</dte:MontoGravable><dte:MontoImpuesto>107.14</dte:MontoImpuesto></dte:Impuesto></dte:Impuestos><dte:Total>1000.00</dte:Total></dte:Item></dte:Items>
        <dte:Totales><dte:TotalImpuestos><dte:TotalImpuesto NombreCorto="IVA" TotalMontoImpuesto="107.14"/></dte:TotalImpuestos><dte:GranTotal>1000.00</dte:GranTotal></dte:Totales>
      </dte:DatosEmision>
    </dte:DTE>
  </dte:SAT>
</dte:GTDocumento>
XML;
    $reader = app(SatXmlReader::class);

    expect(fn () => $reader->read($emission))->toThrow(ValidationException::class, 'no trae la certificación');

    $certified = str_replace('</dte:SAT>', '<dte:Certificacion><dte:NITCertificador>12521337</dte:NITCertificador><dte:NombreCertificador>SAT</dte:NombreCertificador><dte:NumeroAutorizacion Numero="1234567" Serie="A1B2C3D4">9F8E7D6C-0000-4000-8000-ABCDEF123456</dte:NumeroAutorizacion><dte:FechaHoraCertificacion>2026-10-13T11:26:12.000-06:00</dte:FechaHoraCertificacion></dte:Certificacion></dte:SAT>', $emission);
    $read = $reader->read($certified);

    expect($read)->toMatchArray([
        'authorization_number' => '9F8E7D6C-0000-4000-8000-ABCDEF123456', 'series' => 'A1B2C3D4', 'number' => '1234567',
        'issuer_nit' => '123456789', 'receiver_id' => '98765432', 'total_cents' => 100000, 'document_type' => 'FACT',
    ])->and($read['certified_at'])->toBe('2026-10-13T11:26:12.000-06:00');

    expect(fn () => $reader->read('<!DOCTYPE x [<!ENTITY a "b">]><x/>'))->toThrow(ValidationException::class)
        ->and(fn () => $reader->read('esto no es xml'))->toThrow(ValidationException::class);
});
