<?php

namespace Tests\Feature;

use App\Support\DomainSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class CustomDomainViewTest extends TestCase
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

    public function test_site_tab_locked_until_plugin_enabled(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertOk()
            ->assertSee('Enable Custom Domain &amp; HTTPS in the Plugins tab', false)
            ->assertSee('id="domain-fieldset" disabled', false)
            ->assertDontSee('name="site_domain_alias"', false);
    }

    public function test_site_tab_unlocked_shows_dns_and_hosts_lines(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('atglance.local', 'off', '10.0.0.5');

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertOk()
            ->assertDontSee('id="domain-fieldset" disabled', false)
            ->assertSee('atglance.local  A  10.0.0.5')
            ->assertSee('10.0.0.5  atglance.local')
            ->assertSee('reserved for mDNS');
    }

    public function test_plugins_card_shows_status_and_steps(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertOk()
            ->assertSee('Custom Domain &amp; HTTPS', false)
            ->assertSee('Not detected yet')
            ->assertSee('docker compose -f docker-compose.yml -f docker-compose.domain.yml up -d')
            ->assertSee('Azure Container Apps');
    }

    public function test_info_tab_shows_the_default_logo_until_an_organization_logo_is_set(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'info']))
            ->assertOk()
            ->assertSee('id="info-org-logo"', false)
            ->assertSee('src="' . asset('branding/atglance-logo.png') . '"', false);

        \App\Models\AdminSetting::putValue('site', 'site_logo_url', 'https://cdn.example.test/acme.png');

        $this->get(route('admin.settings', ['tab' => 'info']))
            ->assertSee('src="https://cdn.example.test/acme.png"', false);
    }

    public function test_plugins_are_a_searchable_list_of_bars(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertOk()
            ->assertSee('id="plugin-search"', false)
            ->assertSee('data-plugin="custom-domain"', false)
            ->assertSee('aria-controls="plugin-details-custom-domain"', false)
            ->assertSee('Disabled')
            ->assertSee('>Enable<', false)
            // Details stay folded until Info is clicked; the Site tab link appears only once enabled.
            ->assertSee('id="plugin-details-custom-domain" style="display: none;"', false)
            ->assertDontSee('Set the domain on the Site tab');

        DomainSettings::setPluginEnabled(true);

        $this->get(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'custom-domain']))
            ->assertOk()
            ->assertSee('id="plugin-details-custom-domain" style="display: block;"', false)
            ->assertSee('Set the domain on the Site tab')
            ->assertSee('>Disable<', false);
    }

    public function test_plugins_card_shows_detected_builtin_proxy(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);
        DomainSettings::recordProxy('builtin', 'http');

        $this->get(route('admin.settings', ['tab' => 'plugins']))->assertSee('Built-in proxy detected (HTTP)');
    }

    public function test_plugins_card_warns_about_untrusted_proxy(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);
        DomainSettings::recordProxy('untrusted', 'http', '10.0.0.8');

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertSee('10.0.0.8')
            ->assertSee('ATGLANCE_TRUSTED_PROXIES');
    }

    public function test_admin_sees_read_only_controls(): void
    {
        $this->actingAsRole(101);

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertOk()
            ->assertSee('Only the super admin can change this.');
    }

    public function test_builtin_options_disabled_until_proxy_seen(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertSee('value="builtin"  disabled', false)
            ->assertSee('value="custom"  disabled', false);
    }

    public function test_legacy_https_flag_never_selects_a_disabled_option(): void
    {
        // Old installer "Use HTTPS = Yes" left site_https_enabled=true, which
        // derives mode "builtin". A selected+disabled option is not submitted,
        // so the form could never be saved.
        $this->actingAsRole(100);
        \App\Models\AdminSetting::putValue('site', 'site_https_enabled', true);
        DomainSettings::setPluginEnabled(true);

        $this->get(route('admin.settings', ['tab' => 'site']))
            ->assertDontSee('value="builtin" selected', false)
            ->assertSee('value="off" selected', false);
    }

    public function test_info_tab_shows_access_url(): void
    {
        $this->actingAsRole(100);
        DomainSettings::setPluginEnabled(true);
        DomainSettings::save('ops.acme.com', 'platform', '10.0.0.5');

        $this->get(route('admin.settings', ['tab' => 'info']))
            ->assertSee('https://ops.acme.com')
            ->assertSee('Handled by platform');
    }
}
