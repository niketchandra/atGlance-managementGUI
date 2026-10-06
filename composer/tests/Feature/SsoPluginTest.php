<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Support\SsoSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class SsoPluginTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        AdminSetting::putValue('sso', 'sso_enabled', 'false');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_plugin_is_listed_and_sso_tab_has_no_own_toggle(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'sso']))
            ->assertOk()
            ->assertSee('SSO Login')
            ->assertSee(route('admin.settings.sso.plugin'), false)
            ->assertDontSee('Configure providers on the SSO tab')
            ->assertDontSee('data-tab="sso"', false)
            ->assertDontSee('id="tab-sso"', false);
    }

    public function test_sso_tab_shows_only_while_plugin_is_enabled(): void
    {
        $this->actingAsRole(100);

        // Disabled: ?tab=sso falls back to the Plugins tab.
        $this->get(route('admin.settings', ['tab' => 'sso']))
            ->assertOk()
            ->assertDontSee('data-tab="sso"', false)
            ->assertSee('id="tab-plugins" class="settings-tab-content ag-card" style="display:block', false);

        AdminSetting::putValue('sso', 'sso_enabled', 'true');

        $this->get(route('admin.settings', ['tab' => 'sso']))
            ->assertOk()
            ->assertSee('data-tab="sso"', false)
            ->assertSee('id="tab-sso" class="settings-tab-content ag-card" style="display:block', false)
            ->assertDontSee('id="sso-enabled-toggle"', false);
    }

    public function test_super_admin_enables_and_disables_sso(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.sso.plugin'), ['enabled' => '1'])
            ->assertRedirect(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'sso']));
        $this->assertTrue(SsoSettings::enabled());

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertSee('Configure providers on the SSO tab')
            ->assertSee('ag-plugin-attention', false)
            ->assertSee('No provider is selected');

        AdminSetting::putValue('sso', 'disable_email_registration', 'true');
        $this->post(route('admin.settings.sso.plugin'), ['enabled' => '0']);

        $this->assertFalse(SsoSettings::enabled());
        $this->assertSame('false', AdminSetting::getValue('disable_email_registration'));
    }

    public function test_admin_cannot_toggle_sso(): void
    {
        $this->actingAsRole(101);

        $this->post(route('admin.settings.sso.plugin'), ['enabled' => '1'])->assertForbidden();

        $this->assertFalse(SsoSettings::enabled());
    }

    public function test_provider_form_is_refused_while_plugin_is_disabled(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.sso'), ['sso_enabled_providers' => ['github']])
            ->assertSessionHasErrors('sso');

        $this->assertSame([], SsoSettings::enabledProviders());
    }

    public function test_provider_form_saves_providers_and_keeps_sso_on(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('sso', 'sso_enabled', 'true');

        // A selected provider is checked even when none of its fields were sent.
        $this->post(route('admin.settings.sso'), ['sso_enabled_providers' => ['github']])
            ->assertSessionHasErrors('sso_config.github');

        $this->post(route('admin.settings.sso'), [
            'sso_enabled_providers' => ['github'],
            'sso_config' => ['github' => ['client_id' => 'client-123', 'enterprise_url' => '']],
            'sso_secret' => ['github' => 'secret-1'],
        ])->assertSessionHasNoErrors();

        $this->assertTrue(SsoSettings::enabled());
        $this->assertSame(['github'], SsoSettings::enabledProviders());

        // Unticking every provider is allowed; settings are kept for later.
        $this->post(route('admin.settings.sso'), ['sso_enabled_providers' => []])->assertSessionHasNoErrors();
        $this->assertSame([], SsoSettings::enabledProviders());
        $this->assertSame('client-123', \App\Support\SsoProviders::clientId('github'));
    }
}
