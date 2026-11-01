<?php

use App\Services\Platform\ProjectDocumentationService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    Storage::fake('local');
    config(['service_hours.enabled' => false]);
});

function publishProjectDocument(string $password = 'clave-terna-2026'): string
{
    $owner = ccOwner();
    test()->actingAs($owner)->post('/control-center/documentation/password', ['access_password' => $password, 'access_password_confirmation' => $password])->assertSessionHasNoErrors();
    test()->actingAs($owner)->post('/control-center/documentation/files', ['title' => 'Manual técnico', 'description' => 'Arquitectura y despliegue',
        'file' => UploadedFile::fake()->create('Manual Técnico.pdf', 120, 'application/pdf')])->assertSessionHasNoErrors();

    return (string) DB::table('project_documents')->value('public_id');
}

test('without a password the page stays closed and lists nothing', function () {
    $this->get('/documentacion')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('project-docs/show')->where('available', false)->where('unlocked', false)->where('documents', []));
});

test('the committee only sees and opens the PDFs after entering the password', function () {
    $id = publishProjectDocument();

    $this->get('/documentacion')->assertInertia(fn (Assert $page) => $page->where('available', true)->where('unlocked', false)->where('documents', []));
    $this->get('/documentacion/archivo/'.$id)->assertRedirect('/documentacion');

    $this->post('/documentacion', ['password' => 'incorrecta'])->assertSessionHasErrors('password');
    $this->get('/documentacion')->assertInertia(fn (Assert $page) => $page->where('unlocked', false));

    $this->post('/documentacion', ['password' => 'clave-terna-2026'])->assertRedirect('/documentacion')->assertSessionHasNoErrors();
    $this->get('/documentacion')->assertInertia(fn (Assert $page) => $page->where('unlocked', true)->has('documents', 1)
        ->where('documents.0.title', 'Manual técnico')->where('documents.0.file_name', 'manual-tecnico.pdf'));

    $this->get('/documentacion/archivo/'.$id)->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Content-Security-Policy', "frame-ancestors 'self'");
    expect($this->get('/documentacion/archivo/'.$id.'?download=1')->headers->get('Content-Disposition'))->toStartWith('attachment');

    $this->post('/documentacion/salir')->assertRedirect('/documentacion');
    $this->get('/documentacion/archivo/'.$id)->assertRedirect('/documentacion');
});

test('only the password hash is stored and changing it closes open accesses', function () {
    publishProjectDocument();
    $stored = (string) DB::table('platform_settings')->where('key', ProjectDocumentationService::PASSWORD_KEY)->value('value');
    expect($stored)->not->toContain('clave-terna-2026');

    $this->post('/documentacion', ['password' => 'clave-terna-2026']);
    $this->get('/documentacion')->assertInertia(fn (Assert $page) => $page->where('unlocked', true));

    $owner = ccOwner();
    $this->actingAs($owner)->post('/control-center/documentation/password', ['access_password' => 'otra-clave-nueva', 'access_password_confirmation' => 'otra-clave-nueva'])->assertSessionHasNoErrors();

    $this->get('/documentacion')->assertInertia(fn (Assert $page) => $page->where('unlocked', false));
    expect(DB::table('platform_audit_events')->where('action', 'project_docs.password_changed')->exists())->toBeTrue();
});

test('repeated wrong passwords are blocked for a while', function () {
    publishProjectDocument();
    RateLimiter::clear('project-docs:127.0.0.1');

    foreach (range(1, 5) as $attempt) {
        $this->post('/documentacion', ['password' => 'mal-'.$attempt])->assertSessionHasErrors('password');
    }
    $this->post('/documentacion', ['password' => 'clave-terna-2026'])->assertSessionHasErrors(['password' => 'Demasiados intentos. Vuelve a intentarlo en 15 minutos.']);
    $this->get('/documentacion')->assertInertia(fn (Assert $page) => $page->where('unlocked', false));
});

test('only PDFs are accepted and deleting removes the file', function () {
    $owner = ccOwner();
    $this->actingAs($owner)->post('/control-center/documentation/files', ['title' => 'Script', 'file' => UploadedFile::fake()->create('script.php', 5, 'text/x-php')])
        ->assertSessionHasErrors('file');

    $id = publishProjectDocument();
    $path = (string) DB::table('project_documents')->where('public_id', $id)->value('file_path');
    Storage::disk('local')->assertExists($path);

    $this->actingAs($owner)->delete('/control-center/documentation/files/'.$id)->assertRedirect();
    Storage::disk('local')->assertMissing($path);
    expect(DB::table('project_documents')->count())->toBe(0);
});

test('staff without settings.manage cannot change the documentation', function () {
    ccRelaxTwoFactor();
    $analyst = ccStaff('saas_analyst');

    $this->actingAs($analyst)->post('/control-center/documentation/password', ['access_password' => 'clave-terna-2026', 'access_password_confirmation' => 'clave-terna-2026'])->assertForbidden();
    $this->actingAs($analyst)->post('/control-center/documentation/files', ['title' => 'X', 'file' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')])->assertForbidden();
    expect(DB::table('project_documents')->count())->toBe(0);
});
