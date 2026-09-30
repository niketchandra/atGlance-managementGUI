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

    public function test_detects_trusted_platform_proxy(): void
    {
        // 127.0.0.1 is trusted by default; a platform proxy is trusted via ATGLANCE_TRUSTED_PROXIES.
        $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])->get('http://10.0.0.5/');

        $this->assertSame('https', DomainSettings::proxySeen()['platform']['scheme']);
        $this->assertFalse(DomainSettings::builtinProxySeen());
    }

    public function test_lan_client_cannot_forge_its_ip_by_default(): void
    {
        \Illuminate\Support\Facades\Route::middleware('web')->get('/_test_ip', fn (\Illuminate\Http\Request $r) => $r->ip());

        $ip = $this->withHeaders(['X-Forwarded-For' => '1.2.3.4'])
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->get('http://10.0.0.5/_test_ip')->getContent();

        $this->assertSame('192.168.1.50', $ip);
    }

    public function test_untrusted_proxy_is_flagged_and_never_redirected(): void
    {
        $response = $this->withHeaders(['X-Forwarded-Proto' => 'http', 'X-Forwarded-For' => '203.0.113.9'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get('http://ops.eu.acme.com/');

        $this->assertStringNotContainsString('https://', (string) $response->headers->get('Location'));
        $this->assertSame('10.0.0.8', DomainSettings::proxySeen()['untrusted']['address']);
    }

    public function test_builtin_header_from_remote_address_is_not_builtin(): void
    {
        $this->withHeaders(['X-AtGlance-Proxy' => 'builtin'])
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.8'])->get('http://10.0.0.5/');

        $this->assertFalse(DomainSettings::builtinProxySeen());
    }
}
