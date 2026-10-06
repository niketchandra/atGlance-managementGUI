<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Support\S3Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class S3PluginTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        AdminSetting::putValue('storage', 's3_enabled', 'false');
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    public function test_s3_tab_appears_only_while_plugin_is_enabled(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 's3']))
            ->assertOk()
            ->assertDontSee('data-tab="s3"', false)
            ->assertSee('S3 Storage')
            ->assertSee('id="tab-plugins" class="settings-tab-content ag-card" style="display:block', false);

        $this->post(route('admin.settings.s3.plugin'), ['enabled' => '1'])
            ->assertRedirect(route('admin.settings', ['tab' => 'plugins', 'plugin' => 's3']));
        $this->assertTrue(S3Settings::pluginEnabled());
        $this->assertFalse(S3Settings::enabled());

        $this->get(route('admin.settings', ['tab' => 's3']))
            ->assertOk()
            ->assertSee('data-tab="s3"', false)
            ->assertSee('Not in use yet')
            ->assertDontSee('Enable S3 configuration');
    }

    public function test_saving_keys_puts_s3_to_use(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('storage', 's3_plugin_enabled', 'true');

        $this->post(route('admin.settings.s3'), [
            's3_access_key' => 'AKIA123',
            's3_secret_key' => 'secret',
            's3_region' => 'ap-south-1',
            's3_bucket' => 'my-bucket',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(S3Settings::enabled());
    }

    public function test_keys_cannot_be_saved_while_plugin_is_disabled(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('storage', 's3_plugin_enabled', 'false');

        $this->post(route('admin.settings.s3'), [
            's3_access_key' => 'AKIA123',
            's3_secret_key' => 'secret',
            's3_region' => 'ap-south-1',
            's3_bucket' => 'my-bucket',
        ])->assertSessionHasErrors('s3');

        $this->assertFalse(S3Settings::enabled());
    }

    public function test_disabling_turns_s3_off(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('storage', 's3_plugin_enabled', 'true');
        AdminSetting::putValue('storage', 's3_enabled', 'true');

        $this->post(route('admin.settings.s3.plugin'), ['enabled' => '0']);

        $this->assertFalse(S3Settings::pluginEnabled());
        $this->assertFalse(S3Settings::enabled());
    }

    public function test_install_already_using_s3_keeps_the_plugin_on(): void
    {
        AdminSetting::putValue('storage', 's3_enabled', 'true');

        $this->assertTrue(S3Settings::pluginEnabled());
    }

    public function test_admin_cannot_toggle_s3(): void
    {
        $this->actingAsRole(101);

        $this->post(route('admin.settings.s3.plugin'), ['enabled' => '1'])->assertForbidden();

        $this->assertFalse(S3Settings::pluginEnabled());
    }
}
