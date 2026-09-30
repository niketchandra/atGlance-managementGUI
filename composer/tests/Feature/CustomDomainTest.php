<?php

namespace Tests\Feature;

use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use App\Support\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Feature\Concerns\MakesCertificates;
use Tests\TestCase;

class CustomDomainTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;
    use MakesCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function enable(): void
    {
        $this->actingAsRole(100);
        $this->post(route('admin.settings.domain.plugin'), ['enabled' => '1'])->assertSessionHasNoErrors();
    }

    public function test_admin_cannot_toggle_plugin(): void
    {
        $this->actingAsRole(101);

        $this->post(route('admin.settings.domain.plugin'), ['enabled' => '1'])->assertForbidden();
        $this->assertFalse(DomainSettings::pluginEnabled());
    }

    public function test_admin_cannot_save_domain(): void
    {
        $this->actingAsRole(101);

        $this->post(route('admin.settings.domain'), ['domain' => 'a.b', 'https_mode' => 'off'])->assertForbidden();
    }

    public function test_save_refused_while_plugin_off(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'off'])
            ->assertSessionHasErrors('domain');
    }

    public function test_save_domain_without_https_sets_app_url(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'Ops.EU.acme.com.', 'https_mode' => 'off', 'server_ip' => '10.0.0.5'])
            ->assertSessionHasNoErrors();

        $this->assertSame('ops.eu.acme.com', DomainSettings::domain());
        $this->assertSame('http://ops.eu.acme.com', config('app.url'));
    }

    public function test_domain_with_scheme_is_refused(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'http://x.com', 'https_mode' => 'off'])->assertSessionHasErrors('domain');
    }

    public function test_invalid_server_ip_is_refused(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'x.com', 'https_mode' => 'off', 'server_ip' => 'not-an-ip'])->assertSessionHasErrors('server_ip');
        $this->post(route('admin.settings.domain'), ['domain' => 'x.com', 'https_mode' => 'off', 'server_ip' => '10.0.0.1:70000'])->assertSessionHasErrors('server_ip');
    }

    public function test_builtin_https_needs_detected_proxy(): void
    {
        $this->enable();

        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'builtin'])
            ->assertSessionHasErrors('https_mode');

        DomainSettings::recordProxy('builtin', 'http');
        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'builtin'])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://atglance.lan', config('app.url'));
    }

    public function test_custom_mode_uploads_pem_pair(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');
        $pair = $this->makeCertificate(['ops.acme.com']);

        $this->post(route('admin.settings.domain'), [
            'domain' => 'ops.acme.com',
            'https_mode' => 'custom',
            'cert_file' => UploadedFile::fake()->createWithContent('site.crt', $pair['cert']),
            'key_file' => UploadedFile::fake()->createWithContent('site.key', $pair['key']),
        ])->assertSessionHasNoErrors();

        $this->assertSame('custom', DomainSettings::httpsMode());
        $this->assertNotNull(CustomCertificate::current());
    }

    public function test_custom_mode_refuses_pfx_for_another_domain(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');

        $this->post(route('admin.settings.domain'), [
            'domain' => 'ops.acme.com',
            'https_mode' => 'custom',
            'pfx_file' => UploadedFile::fake()->createWithContent('site.pfx', $this->makePkcs12(['other.acme.com'], 'pw')),
            'pfx_password' => 'pw',
        ])->assertSessionHasErrors('certificate');

        $this->assertNull(CustomCertificate::current());
        $this->assertSame('off', DomainSettings::httpsMode());
    }

    public function test_custom_mode_uploads_pfx(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');

        $this->post(route('admin.settings.domain'), [
            'domain' => 'ops.acme.com',
            'https_mode' => 'custom',
            'pfx_file' => UploadedFile::fake()->createWithContent('site.pfx', $this->makePkcs12(['ops.acme.com'], 'pw')),
            'pfx_password' => 'pw',
        ])->assertSessionHasNoErrors();

        $this->assertNotNull(CustomCertificate::current());
    }

    public function test_custom_mode_without_certificate_is_refused(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');

        $this->post(route('admin.settings.domain'), ['domain' => 'ops.acme.com', 'https_mode' => 'custom'])
            ->assertSessionHasErrors('certificate');
    }

    public function test_domain_change_not_covered_by_certificate_is_refused(): void
    {
        $this->enable();
        DomainSettings::recordProxy('builtin', 'http');
        CustomCertificate::store(CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com']))));

        $this->post(route('admin.settings.domain'), ['domain' => 'new.acme.com', 'https_mode' => 'custom'])
            ->assertSessionHasErrors('certificate');
    }

    public function test_removing_certificate_turns_https_off(): void
    {
        $this->enable();
        CustomCertificate::store(CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com']))));
        DomainSettings::save('ops.acme.com', 'custom', '10.0.0.5');

        $this->delete(route('admin.settings.domain.certificate.remove'))->assertSessionHasNoErrors();

        $this->assertNull(CustomCertificate::current());
        $this->assertSame('off', DomainSettings::httpsMode());
    }

    public function test_disabling_plugin_restores_ip_app_url(): void
    {
        $this->enable();
        $this->post(route('admin.settings.domain'), ['domain' => 'atglance.lan', 'https_mode' => 'off', 'server_ip' => '192.168.1.2:8000']);

        $this->post(route('admin.settings.domain.plugin'), ['enabled' => '0']);

        $this->assertSame('http://192.168.1.2:8000', config('app.url'));
        $this->assertSame('atglance.lan', DomainSettings::domain());
    }

    public function test_check_reports_match_mismatch_and_unresolved(): void
    {
        $this->actingAsRole(101);
        $this->app->instance(HostResolver::class, new class extends HostResolver {
            public function resolve(string $host): array
            {
                return ['a.acme.com' => ['10.0.0.5'], 'b.acme.com' => ['10.0.0.9']][$host] ?? [];
            }
        });

        $this->postJson(route('admin.settings.domain.check'), ['domain' => 'a.acme.com', 'server_ip' => '10.0.0.5:8000'])
            ->assertJson(['ok' => true, 'message' => 'a.acme.com resolves to 10.0.0.5 (matches).']);
        $this->postJson(route('admin.settings.domain.check'), ['domain' => 'b.acme.com', 'server_ip' => '10.0.0.5'])
            ->assertJson(['ok' => false, 'message' => 'b.acme.com resolves to 10.0.0.9, not 10.0.0.5.']);
        $this->postJson(route('admin.settings.domain.check'), ['domain' => 'c.acme.com', 'server_ip' => '10.0.0.5'])
            ->assertJson(['ok' => false]);
    }

    public function test_ca_download_404_until_issued(): void
    {
        $this->actingAsRole(101);

        $this->get(route('admin.settings.domain.ca'))->assertNotFound();
    }
}
