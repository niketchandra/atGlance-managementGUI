<?php

namespace Tests\Feature;

use App\Support\CustomCertificate;
use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\Feature\Concerns\MakesCertificates;
use Tests\TestCase;

class DomainTlsEndpointsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;
    use MakesCertificates;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        DomainSettings::setPluginEnabled(true);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function useCustomCertificate(): void
    {
        CustomCertificate::store(CustomCertificate::fromPem(...array_values($this->makeCertificate(['ops.acme.com']))));
        DomainSettings::save('ops.acme.com', 'custom', '10.0.0.5');
    }

    public function test_allows_configured_domain_in_builtin_mode(): void
    {
        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');

        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertOk();
        $this->get('/api/internal/domain/tls-allowed?domain=evil.example')->assertNotFound();
    }

    public function test_fallback_name_is_allowed_in_every_mode(): void
    {
        DomainSettings::save('ops.acme.com', 'off', '10.0.0.5');

        $this->get('/api/internal/domain/tls-allowed?domain=atglance.internal')->assertOk();
    }

    public function test_nothing_allowed_when_mode_off(): void
    {
        DomainSettings::save('ops.acme.com', 'off', '10.0.0.5');

        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertNotFound();
    }

    public function test_nothing_allowed_from_remote_address(): void
    {
        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertNotFound();
    }

    public function test_nothing_allowed_when_plugin_off(): void
    {
        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');
        DomainSettings::setPluginEnabled(false);

        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertNotFound();
        $this->get('/api/internal/domain/tls-allowed?domain=atglance.internal')->assertNotFound();
    }

    public function test_certificate_served_in_custom_mode(): void
    {
        $this->useCustomCertificate();

        $response = $this->get('/api/internal/domain/certificate?server_name=ops.acme.com');

        $response->assertOk();
        $this->assertStringContainsString('BEGIN CERTIFICATE', $response->getContent());
        $this->assertStringContainsString('PRIVATE KEY', $response->getContent());
        $this->get('/api/internal/domain/tls-allowed?domain=ops.acme.com')->assertOk();
    }

    public function test_certificate_not_served_for_other_names(): void
    {
        $this->useCustomCertificate();

        $this->get('/api/internal/domain/certificate?server_name=other.acme.com')->assertNoContent();
    }

    public function test_certificate_not_served_to_remote_address(): void
    {
        $this->useCustomCertificate();

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
    }

    public function test_certificate_not_served_in_other_modes(): void
    {
        $this->useCustomCertificate();
        DomainSettings::save('ops.acme.com', 'builtin', '10.0.0.5');

        $this->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
    }

    public function test_certificate_endpoint_refuses_forwarded_requests(): void
    {
        $this->useCustomCertificate();

        $this->withHeaders(['X-Forwarded-For' => '203.0.113.7'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
    }

    public function test_certificate_endpoint_refuses_requests_through_builtin_proxy(): void
    {
        $this->useCustomCertificate();

        $this->withHeaders(['X-AtGlance-Proxy' => 'builtin'])
            ->get('/api/internal/domain/certificate?server_name=ops.acme.com')->assertNoContent();
    }
}
