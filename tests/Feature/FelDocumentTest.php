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
    app(FelCertificationService::class)->certify($issuer->id, $doc->public_id);
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
