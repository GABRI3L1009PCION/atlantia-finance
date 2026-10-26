<?php

use App\Models\Organization;
use App\Models\PlanPrice;
use App\Models\User;
use App\Services\AccountingService;
use Database\Seeders\PlanCatalogSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    $this->seed(PlanCatalogSeeder::class);
    Process::preventStrayProcesses();
});

/** @return array{owner: User, uuid: string, companyId: int} */
function invoiceReaderCompany(string $plan = 'professional'): array
{
    $owner = User::factory()->create();
    $organization = Organization::create(['name' => 'Firma Lector '.Str::random(4), 'status' => 'active']);
    $organization->users()->attach($owner->id, ['role' => 'owner', 'status' => 'active']);
    $price = PlanPrice::whereHas('plan', fn ($query) => $query->where('code', $plan))->where('billing_period', 'monthly')->firstOrFail();
    $organization->subscriptions()->create(['status' => 'active'])->items()->create(['plan_price_id' => $price->id, 'item_type' => 'base_plan']);
    $office = $organization->offices()->create(['code' => 'MAIN', 'name' => 'Sede', 'status' => 'active', 'is_primary' => true]);
    $uuid = (string) Str::uuid();
    $companyId = DB::table('companies')->insertGetId(['public_id' => $uuid, 'office_id' => $office->id, 'name' => 'Comercial Compradora, S.A.', 'tax_id' => '7654321-0',
        'currency' => 'GTQ', 'created_at' => now(), 'updated_at' => now()]);
    app(AccountingService::class)->installDefaultChart($companyId);

    return compact('owner', 'uuid', 'companyId');
}

function felXml(string $receiver = '76543210'): string
{
    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<dte:GTDocumento xmlns:dte="http://www.sat.gob.gt/dte/fel/0.2.0" Version="0.1"><dte:SAT ClaseDocumento="dte"><dte:DTE ID="DatosCertificados">
<dte:DatosEmision ID="DatosEmision"><dte:DatosGenerales CodigoMoneda="GTQ" FechaHoraEmision="2026-10-20T10:30:00-06:00" Tipo="FACT"/>
<dte:Emisor AfiliacionIVA="GEN" NITEmisor="12345678" NombreComercial="FERRETERIA EL MARTILLO" NombreEmisor="FERRETERIA EL MARTILLO, S.A."/>
<dte:Receptor IDReceptor="{$receiver}" NombreReceptor="COMERCIAL COMPRADORA, S.A."/>
<dte:Items>
<dte:Item BienOServicio="B" NumeroLinea="1"><dte:Cantidad>3</dte:Cantidad><dte:Descripcion>Cemento gris 42.5 kg</dte:Descripcion><dte:PrecioUnitario>80.00</dte:PrecioUnitario><dte:Precio>240.00</dte:Precio><dte:Descuento>0</dte:Descuento>
<dte:Impuestos><dte:Impuesto><dte:NombreCorto>IVA</dte:NombreCorto><dte:CodigoUnidadGravable>1</dte:CodigoUnidadGravable><dte:MontoGravable>214.29</dte:MontoGravable><dte:MontoImpuesto>25.71</dte:MontoImpuesto></dte:Impuesto></dte:Impuestos><dte:Total>240.00</dte:Total></dte:Item>
<dte:Item BienOServicio="S" NumeroLinea="2"><dte:Cantidad>2.5</dte:Cantidad><dte:Descripcion>Flete por kilómetro</dte:Descripcion><dte:PrecioUnitario>20.00</dte:PrecioUnitario><dte:Precio>50.00</dte:Precio><dte:Descuento>0</dte:Descuento>
<dte:Impuestos><dte:Impuesto><dte:NombreCorto>IVA</dte:NombreCorto><dte:CodigoUnidadGravable>1</dte:CodigoUnidadGravable><dte:MontoGravable>44.64</dte:MontoGravable><dte:MontoImpuesto>5.36</dte:MontoImpuesto></dte:Impuesto></dte:Impuestos><dte:Total>50.00</dte:Total></dte:Item>
</dte:Items>
<dte:Totales><dte:TotalImpuestos><dte:TotalImpuesto NombreCorto="IVA" TotalMontoImpuesto="31.07"/></dte:TotalImpuestos><dte:GranTotal>290.00</dte:GranTotal></dte:Totales>
</dte:DatosEmision></dte:DTE>
<dte:Certificacion><dte:NITCertificador>16693949</dte:NITCertificador><dte:NumeroAutorizacion Numero="2864195623" Serie="7A3F2B1C">7a3f2b1c-aab7-4b27-9c2e-1d4f5e6a7b8c</dte:NumeroAutorizacion><dte:FechaHoraCertificacion>2026-10-20T10:31:02-06:00</dte:FechaHoraCertificacion></dte:Certificacion>
</dte:SAT></dte:GTDocumento>
XML;
}

const PRINTED_INVOICE = <<<'TXT'
        DISTRIBUIDORA LA ESPERANZA
        Nombre comercial: DISTRIBUIDORA LA ESPERANZA         NIT Emisor: 9876543-2
        FACTURA
        Serie: B2C4D6E8      Número de DTE: 1234567890
        Número de autorización: B2C4D6E8-4996-02D2-8A3B-112233445566
        Fecha y hora de emisión: 15/10/2026 09:12:44
        NIT Receptor: 7654321-0           Nombre Receptor: COMERCIAL COMPRADORA
        1   Papel bond carta resma            Q 450.00
        Subtotal                                Q 401.79
        IVA (12%)                                    48.21
        TOTAL                                   Q 450.00
TXT;

test('a FEL XML fills the purchase exactly and finds the supplier by its NIT', function () {
    $f = invoiceReaderCompany();
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Ferretería El Martillo', 'tax_id' => '1234567-8', 'created_at' => now(), 'updated_at' => now()]);

    $invoice = $this->actingAs($f['owner'])->post(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->createWithContent('factura.xml', felXml())])
        ->assertOk()->json('invoice');

    expect($invoice['source'])->toBe('xml')
        ->and($invoice['supplier'])->toBe(['tax_id' => '12345678', 'name' => 'FERRETERIA EL MARTILLO, S.A.', 'id' => $supplierId])
        ->and($invoice['fiscal_document_type'])->toBe('FACT')
        ->and($invoice['fiscal_series'])->toBe('7A3F2B1C')
        ->and($invoice['fiscal_number'])->toBe('2864195623')
        ->and($invoice['fiscal_authorization'])->toBe('7A3F2B1C-AAB7-4B27-9C2E-1D4F5E6A7B8C')
        ->and($invoice['document_date'])->toBe('2026-10-20')
        ->and($invoice['total'])->toBe('290.00')
        ->and($invoice['vat'])->toBe('31.07')
        ->and($invoice['lines'])->toBe([
            ['description' => 'Cemento gris 42.5 kg', 'quantity' => '3', 'unit_price' => '80.00', 'tax_treatment' => 'goods'],
            ['description' => 'Flete por kilómetro (cant. 2.5)', 'quantity' => '1', 'unit_price' => '50.00', 'tax_treatment' => 'services'],
        ])
        ->and($invoice['warnings'])->toBe([]);
});

test('the reader warns about another receiver, an unknown supplier and an invoice already registered', function () {
    $f = invoiceReaderCompany();
    $supplierId = DB::table('purchase_suppliers')->insertGetId(['company_id' => $f['companyId'], 'name' => 'Otro', 'tax_id' => '1111111', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('purchase_documents')->insert(['public_id' => (string) Str::uuid(), 'company_id' => $f['companyId'], 'purchase_supplier_id' => $supplierId, 'supplier_name' => 'Otro', 'number' => 1,
        'document_type' => 'purchase', 'status' => 'draft', 'document_date' => '2026-10-20', 'due_on' => '2026-10-20', 'total_cents' => 29000, 'fiscal_authorization' => '7A3F2B1C-AAB7-4B27-9C2E-1D4F5E6A7B8C',
        'created_by' => $f['owner']->id, 'created_at' => now(), 'updated_at' => now()]);

    $invoice = $this->actingAs($f['owner'])->post(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->createWithContent('f.xml', felXml('CF99'))])
        ->assertOk()->json('invoice');

    expect($invoice['supplier']['id'])->toBeNull()
        ->and(implode(' ', $invoice['warnings']))->toContain('NIT 12345678 no está registrado')->toContain('a nombre del NIT CF99')->toContain('ya está registrada');
});

test('a PDF with text is read without OCR and its FEL data is found', function () {
    $f = invoiceReaderCompany();
    Process::fake(['*pdftotext*' => Process::result(PRINTED_INVOICE)]);

    $invoice = $this->actingAs($f['owner'])->post(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->create('factura.pdf', 20, 'application/pdf')])
        ->assertOk()->json('invoice');

    expect($invoice['source'])->toBe('pdf')
        ->and($invoice['supplier']['tax_id'])->toBe('9876543-2')
        ->and($invoice['supplier']['name'])->toBe('DISTRIBUIDORA LA ESPERANZA')
        ->and($invoice['receiver_tax_id'])->toBe('7654321-0')
        ->and($invoice['fiscal_series'])->toBe('B2C4D6E8')
        ->and($invoice['fiscal_number'])->toBe('1234567890')
        ->and($invoice['fiscal_authorization'])->toBe('B2C4D6E8-4996-02D2-8A3B-112233445566')
        ->and($invoice['document_date'])->toBe('2026-10-15')
        ->and($invoice['total'])->toBe('450.00')
        ->and($invoice['vat'])->toBe('48.21')
        ->and($invoice['lines'][0])->toMatchArray(['quantity' => '1', 'unit_price' => '450.00', 'tax_treatment' => 'goods'])
        ->and(implode(' ', $invoice['warnings']))->toContain('revisa cada campo');
    Process::assertDidntRun(fn (PendingProcess $process): bool => str_contains(implode(' ', (array) $process->command), 'tesseract'));
});

test('a photo and a scanned PDF go through OCR, and the series and number come from the authorization when missing', function () {
    $f = invoiceReaderCompany();
    $ocrText = "COMERCIAL EL SOL\nNIT: 5556667\nAutorización: B2C4D6E8-4996-02D2-8A3B-112233445566\nFecha: 3 de octubre de 2026\nGran total Q 1,250.75";
    Process::fake([
        '*pdftotext*' => Process::result(''),
        '*pdftoppm*' => function (PendingProcess $process) {
            File::put(end($process->command).'-1.png', 'png');

            return Process::result('');
        },
        '*tesseract*' => Process::result($ocrText),
    ]);

    foreach ([UploadedFile::fake()->image('foto.jpg'), UploadedFile::fake()->create('escaneo.pdf', 20, 'application/pdf')] as $file) {
        $invoice = $this->actingAs($f['owner'])->post(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => $file])->assertOk()->json('invoice');
        expect($invoice['source'])->toBe('ocr')
            ->and($invoice['supplier']['tax_id'])->toBe('5556667')
            ->and($invoice['supplier']['name'])->toBe('COMERCIAL EL SOL')
            ->and($invoice['fiscal_series'])->toBe('B2C4D6E8')
            ->and($invoice['fiscal_number'])->toBe((string) hexdec('499602D2'))
            ->and($invoice['document_date'])->toBe('2026-10-03')
            ->and($invoice['total'])->toBe('1250.75');
    }
    expect(File::glob(storage_path('app/tmp-ocr/*')))->toBe([]);
});

test('without Tesseract on the server a photo gets a clear message, and XML and PDF keep working', function () {
    $f = invoiceReaderCompany();
    Process::fake(['*tesseract*' => Process::result('', 'tesseract: not found', 127)]);

    $this->actingAs($f['owner'])->postJson(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->image('foto.png')])
        ->assertUnprocessable()->assertJsonValidationErrors(['file' => 'no está disponible en este servidor']);
    $this->postJson(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->createWithContent('f.xml', felXml())])->assertOk();
});

test('unsafe or unsupported files are refused and plans without document management can not use the reader', function () {
    $f = invoiceReaderCompany();
    $this->actingAs($f['owner'])->postJson(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->createWithContent('x.xml', '<?xml version="1.0"?><!DOCTYPE a [<!ENTITY b SYSTEM "file:///etc/passwd">]><a>&b;</a>')])
        ->assertUnprocessable()->assertJsonValidationErrors(['file' => 'no es seguro']);
    $this->postJson(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->create('virus.exe', 10)])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson(route('accounting.purchaseReadInvoice', $f['uuid']), ['file' => UploadedFile::fake()->createWithContent('otro.xml', '<?xml version="1.0"?><pedido><total>5</total></pedido>')])
        ->assertUnprocessable()->assertJsonValidationErrors(['file' => 'no es una factura electrónica']);

    $essential = invoiceReaderCompany('essential');
    $this->actingAs($essential['owner'])->get(route('accounting.purchases', $essential['uuid']))->assertInertia(fn ($page) => $page->where('canReadInvoices', false));
    $this->postJson(route('accounting.purchaseReadInvoice', $essential['uuid']), ['file' => UploadedFile::fake()->createWithContent('f.xml', felXml())])->assertForbidden();
    $this->actingAs($f['owner'])->get(route('accounting.purchases', $f['uuid']))->assertInertia(fn ($page) => $page->where('canReadInvoices', true));
});
