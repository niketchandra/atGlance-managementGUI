<?php

namespace Tests\Feature;

use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class DomainRequestHandlingTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('ops.eu.acme.com', 'platform', '10.0.0.5');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_domain_over_http_redirects_with_302(): void
    {
        $this->withHeaders(['X-Forwarded-For' => '10.0.0.7', 'X-Forwarded-Proto' => 'http'])
            ->get('http://ops.eu.acme.com/')
            ->assertStatus(302)
            ->assertRedirect('https://ops.eu.acme.com/');
    }

    public function test_direct_request_to_app_port_is_never_redirected(): void
    {
        // http://<domain>:8000 reaches the app without a proxy: the recovery path.
        $location = (string) $this->get('http://ops.eu.acme.com:8000/')->headers->get('Location');

        $this->assertStringNotContainsString('https://', $location);
    }

    public function test_fallback_hosts_are_never_redirected(): void
    {
        foreach (['http://10.0.0.5/', 'http://10.0.0.5:8000/', 'http://localhost/', 'http://atglance.internal/', 'http://atglance.internal:8000/'] as $url) {
            $location = (string) $this->get($url)->headers->get('Location');
            $this->assertStringNotContainsString('https://', $location, $url);
        }
    }

    public function test_post_to_domain_over_http_is_not_redirected(): void
    {
        $response = $this->post('http://ops.eu.acme.com/login', ['email' => 'x@y.z', 'password' => 'nope']);

        $this->assertStringStartsNotWith('https://ops.eu.acme.com', (string) $response->headers->get('Location'));
    }

    public function test_no_redirect_when_https_off(): void
    {
        DomainSettings::save('ops.eu.acme.com', 'off', '10.0.0.5');

        $this->assertStringStartsNotWith('https://', (string) $this->get('http://ops.eu.acme.com/')->headers->get('Location'));
    }

    public function test_no_redirect_when_plugin_off(): void
    {
        DomainSettings::setPluginEnabled(false);

        $this->assertStringStartsNotWith('https://', (string) $this->get('http://ops.eu.acme.com/')->headers->get('Location'));
    }

    public function test_forwarded_https_is_secure_and_not_redirected(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'https'])->get('http://ops.eu.acme.com/');

        $this->assertStringStartsNotWith('https://ops.eu.acme.com', (string) $response->headers->get('Location'));
        $this->assertTrue(config('session.secure'));
    }

    public function test_plain_http_fallback_gets_non_secure_cookie_and_own_links(): void
    {
        $response = $this->get('http://atglance.internal/');

        $this->assertFalse(config('session.secure'));
        $response->assertDontSee('https://ops.eu.acme.com', false);
    }

    public function test_detects_builtin_proxy(): void
    {
        $this->withHeaders(['X-AtGlance-Proxy' => 'builtin', 'X-Forwarded-For' => '10.0.0.7'])->get('http://10.0.0.5/');

        $this->assertTrue(DomainSettings::builtinProxySeen());
    }

    public function test_detects_platform_proxy(): void
    {
        $this->withHeaders(['X-Forwarded-Proto' => 'https'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get('http://10.0.0.5/');

        $this->assertSame('https', DomainSettings::proxySeen()['platform']['scheme']);
        $this->assertFalse(DomainSettings::builtinProxySeen());
    }

    public function test_builtin_header_from_remote_address_is_not_builtin(): void
    {
        $this->withHeaders(['X-AtGlance-Proxy' => 'builtin'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get('http://10.0.0.5/');

        $this->assertFalse(DomainSettings::builtinProxySeen());
    }
}
