<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class SettingsSplitLayoutTest extends TestCase
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

    public function test_notification_tab_lists_channels_on_the_left(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'notification', 'channel' => 'telegram']))
            ->assertOk()
            ->assertSee('data-split="channel"', false)
            ->assertSee('Tick to let workspace admins use a channel.')
            ->assertSee('name="allowed[telegram]"', false)
            ->assertSee('id="notify-panel-telegram" data-key="telegram" style="display: block;"', false)
            ->assertSee('Coming soon')
            ->assertSee('Save Notification Channels');
    }

    public function test_backup_tab_lists_backups_on_the_left(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'backup-restore', 'backup' => 'config']))
            ->assertOk()
            ->assertSee('data-split="backup"', false)
            ->assertSee('class="backup-nav-toggle" data-type="database"', false)
            ->assertSee('Config &amp; files', false)
            ->assertSee('data-key="config" style="display: block;"', false)
            ->assertSee('data-key="database" style="display: none;"', false)
            ->assertSee('set up S3 Storage in the Plugins tab first');
    }

    public function test_sso_tab_uses_the_shared_layout(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('sso', 'sso_enabled', 'true');

        $this->get(route('admin.settings', ['tab' => 'sso']))
            ->assertOk()
            ->assertSee('data-split="provider"', false)
            ->assertDontSee('sso-nav-item', false);
    }
}
