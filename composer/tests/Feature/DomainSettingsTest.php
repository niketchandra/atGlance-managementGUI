<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class DomainSettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

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

    public function test_accepts_any_domain_or_subdomain(): void
    {
        foreach (['acme.io', 'atglance.acme.com', 'ops.eu.acme.com', 'atglance.internal', 'x-1.lan'] as $domain) {
            $this->assertNull(DomainSettings::validateDomain($domain), $domain);
        }
    }

    public function test_rejects_urls_paths_ports_ips_and_single_labels(): void
    {
        foreach (['http://x.com', 'x.com/path', 'x.com:443', '10.0.0.5', '::1', '-a.com', 'a-.com', 'intranet', str_repeat('a', 64) . '.com'] as $domain) {
            $this->assertNotNull(DomainSettings::validateDomain(DomainSettings::normalizeDomain($domain)), $domain);
        }
    }

    public function test_normalise_lowercases_and_drops_trailing_dot(): void
    {
        $this->assertSame('ops.acme.com', DomainSettings::normalizeDomain(' Ops.ACME.com. '));
    }

    public function test_stored_domain_is_normalised(): void
    {
        AdminSetting::putValue('site', 'site_domain_alias', 'API.Example.com.');

        $this->assertSame('api.example.com', DomainSettings::domain());
    }

    public function test_https_mode_derives_from_legacy_flag(): void
    {
        $this->assertSame('off', DomainSettings::httpsMode());
        AdminSetting::putValue('site', 'site_https_enabled', true);
        $this->assertSame('builtin', DomainSettings::httpsMode());
        AdminSetting::putValue('domain', 'site_https_mode', 'platform');
        $this->assertSame('platform', DomainSettings::httpsMode());
    }

    public function test_server_ip_strips_port(): void
    {
        AdminSetting::putValue('site', 'site_domain_alias_ip', '192.168.1.2:8000');
        $this->assertSame('192.168.1.2', DomainSettings::serverIp());
        $this->assertSame('::1', DomainSettings::stripPort('[::1]:8080'));
        $this->assertSame('::1', DomainSettings::stripPort('::1'));
    }

    public function test_suggested_server_address_prefers_stored_then_request_ip(): void
    {
        $this->assertSame('10.0.0.5', DomainSettings::suggestedServerAddress('10.0.0.5'));
        $this->assertSame('', DomainSettings::suggestedServerAddress('atglance.internal'));
        AdminSetting::putValue('site', 'site_domain_alias_ip', '10.0.0.9');
        $this->assertSame('10.0.0.9', DomainSettings::suggestedServerAddress('10.0.0.5'));
    }

    public function test_fallback_hosts(): void
    {
        foreach (['10.0.0.5', 'localhost', '::1', 'atglance.internal'] as $host) {
            $this->assertTrue(DomainSettings::isFallbackHost($host), $host);
        }
        $this->assertFalse(DomainSettings::isFallbackHost('ops.acme.com'));

        AdminSetting::putValue('site', 'site_domain_alias', 'atglance.internal');
        $this->assertFalse(DomainSettings::isFallbackHost('atglance.internal'));
    }

    public function test_save_sets_app_url_and_plugin_off_restores_ip(): void
    {
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('ops.acme.com', 'platform', '10.0.0.5:8000');

        $this->assertSame('https://ops.acme.com', config('app.url'));
        $this->assertStringContainsString("APP_URL=https://ops.acme.com\n", file_get_contents(base_path('.env')));
        $this->assertSame('true', AdminSetting::getValue('site_https_enabled'));

        DomainSettings::setPluginEnabled(false);
        $this->assertSame('http://10.0.0.5:8000', config('app.url'));
        $this->assertSame('ops.acme.com', DomainSettings::domain());
    }

    public function test_record_proxy_keeps_each_type(): void
    {
        DomainSettings::recordProxy('platform', 'https');
        DomainSettings::recordProxy('builtin', 'http');

        $seen = DomainSettings::proxySeen();
        $this->assertSame('https', $seen['platform']['scheme']);
        $this->assertSame('http', $seen['builtin']['scheme']);
        $this->assertTrue(DomainSettings::builtinProxySeen());
    }

    public function test_view_data_builds_dns_and_hosts_lines(): void
    {
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('atglance.lan', 'off', '10.0.0.5:8000');

        $data = DomainSettings::viewData('10.0.0.5');

        $this->assertSame('http://atglance.lan', $data['access_url']);
        $this->assertSame('atglance.lan  A  10.0.0.5', $data['dns_record']);
        $this->assertSame('10.0.0.5  atglance.lan', $data['hosts_line']);
        $this->assertFalse($data['local_warning']);
    }

    public function test_local_tld_warning_and_fallback_hosts_line(): void
    {
        DomainSettings::save('atglance.local', 'off', '10.0.0.5');
        $this->assertTrue(DomainSettings::viewData(null)['local_warning']);

        DomainSettings::save('', 'off', '10.0.0.5');
        $this->assertSame('10.0.0.5  atglance.internal', DomainSettings::viewData(null)['hosts_line']);
    }
}
