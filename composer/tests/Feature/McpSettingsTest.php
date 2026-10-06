<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class McpSettingsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const CONTROL = 'http://mcp-control:2375';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private bool $running = false;

    private function fakeControl(bool $running): void
    {
        $this->running = $running;
        Http::fake(fn (HttpRequest $request) => str_ends_with($request->url(), '/mcp/status')
            ? Http::response(['State' => ['Running' => $this->running]])
            : Http::response('', 204));
    }

    private function assertControlCalled(string $path): void
    {
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::CONTROL . $path && $request->method() === 'POST');
    }

    public function test_old_mcp_url_redirects_to_the_plugin(): void
    {
        $this->actingAsRole(100);

        $this->get('/admin/settings/mcp')->assertRedirect(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'mcp']));
    }

    public function test_plugin_shows_toggle_status_and_setup_link(): void
    {
        $this->actingAsRole(100);
        $this->fakeControl(false);

        $this->get(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'mcp']))
            ->assertOk()
            ->assertSee('MCP Server (AI tools)')
            ->assertSee('Server stopped')
            ->assertSee(route('admin.settings.mcp'), false)
            ->assertDontSee('Open setup page')
            ->assertDontSee('data-tab="mcp"', false);

        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');
        $this->fakeControl(true);

        $this->get(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'mcp']))
            ->assertOk()
            ->assertSee('Server running')
            ->assertSee('Open setup page')
            ->assertSee(':8002/mcp', false)
            ->assertSee(route('mcp.connect'), false);
    }

    public function test_plugin_flags_attention_when_enabled_but_stopped(): void
    {
        $this->actingAsRole(100);
        $this->fakeControl(false);
        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');

        $this->get(route('admin.settings', ['tab' => 'plugins']))
            ->assertOk()
            ->assertSee('stopped although the plugin is enabled')
            ->assertSee('ag-plugin-attention', false);
    }

    public function test_connect_page_and_sidebar_link_only_when_mcp_is_on(): void
    {
        $this->actingAsRole(102);

        $this->get(route('mcp.connect'))->assertNotFound();
        $this->get(route('dashboard'))->assertDontSee(route('mcp.connect'), false);

        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');

        $this->get(route('dashboard'))->assertSee(route('mcp.connect'), false)->assertSee('ag-nav--app', false);
        $this->get(route('mcp.connect'))
            ->assertOk()
            ->assertSee(':8002/mcp', false)
            ->assertSee('claude mcp add --transport http atglance', false)
            ->assertSee('mcp-remote', false)
            ->assertSee('.vscode/mcp.json', false)
            ->assertSee('~/.copilot/mcp-config.json', false)
            ->assertSee('%USERPROFILE%\.mcp.json', false)
            ->assertSee('Other apps (B2B)')
            ->assertSee("\nimport asyncio", false)
            ->assertSee('streamable_http_client(URL, http_client=http)', false)
            ->assertSee('StreamableHTTPClientTransport', false)
            ->assertSee('get_ai_check_progress')
            ->assertSee('Troubleshooting')
            ->assertDontSee('ce-atglance-gateway', false);
    }

    public function test_connect_page_offers_private_ip_when_domain_is_set(): void
    {
        $this->actingAsRole(102);
        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');
        AdminSetting::putValue('domain', 'site_domain_alias', 'atglance.internal');
        AdminSetting::putValue('domain', 'site_domain_alias_ip', '192.168.1.14:8000');

        $this->get(route('mcp.connect'))
            ->assertOk()
            ->assertSee('http://atglance.internal:8002/mcp', false)
            ->assertSee('If atglance.internal does not work', false)
            ->assertSee('http://192.168.1.14:8002/mcp', false)
            ->assertSee('name="mcp-address"', false);
    }

    public function test_connect_page_explains_how_to_find_the_ip_when_unknown(): void
    {
        $this->actingAsRole(102);
        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');
        AdminSetting::putValue('domain', 'site_domain_alias', 'atglance.internal');
        AdminSetting::putValue('domain', 'site_domain_alias_ip', '127.0.0.1');

        $this->get(route('mcp.connect'))
            ->assertOk()
            ->assertSee('http://&lt;private_ip&gt;:8002/mcp', false)
            ->assertSee('hostname -I', false)
            ->assertSee('Or ask your administrator')
            ->assertDontSee('name="mcp-address"', false);
    }

    public function test_connect_page_uses_private_ip_from_the_browser_address(): void
    {
        $this->actingAsRole(102);
        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');

        $this->get('http://192.168.1.14:8000/connect-ai')
            ->assertOk()
            ->assertSee('http://192.168.1.14:8002/mcp', false);
    }

    public function test_other_settings_tabs_do_not_call_mcp_control(): void
    {
        $this->actingAsRole(100);
        Http::fake();

        $this->get(route('admin.settings', ['tab' => 'info']))->assertOk();

        Http::assertNothingSent();
    }

    public function test_turning_on_starts_and_turning_off_stops_the_container(): void
    {
        $this->actingAsRole(100);
        $this->fakeControl(false);

        $this->post(route('admin.settings.mcp'), ['enabled' => '1'])
            ->assertRedirect(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'mcp']))
            ->assertSessionHasNoErrors();
        $this->assertSame('true', AdminSetting::getValue('mcp_enabled'));
        $this->assertControlCalled('/mcp/start');

        $this->post(route('admin.settings.mcp'), ['enabled' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('false', AdminSetting::getValue('mcp_enabled'));
        $this->assertControlCalled('/mcp/stop');
    }

    public function test_setting_is_saved_and_error_shown_when_control_is_unreachable(): void
    {
        $this->actingAsRole(100);
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: mcp-control'));

        $this->post(route('admin.settings.mcp'), ['enabled' => '1'])->assertSessionHasErrors('mcp');

        $this->assertSame('true', AdminSetting::getValue('mcp_enabled'));
    }

    public function test_admin_cannot_toggle_mcp(): void
    {
        $this->actingAsRole(101);
        Http::fake();

        $this->post(route('admin.settings.mcp'), ['enabled' => '1'])->assertForbidden();

        $this->assertNotSame('true', AdminSetting::getValue('mcp_enabled'));
        Http::assertNothingSent();
    }

    public function test_manage_command_stops_a_running_container_when_off(): void
    {
        $this->fakeControl(true);
        AdminSetting::putValue('mcp', 'mcp_enabled', 'false');

        $this->artisan('mcp:manage')->assertExitCode(0);

        $this->assertControlCalled('/mcp/stop');
    }

    public function test_manage_command_does_nothing_when_state_matches(): void
    {
        $this->fakeControl(true);
        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');

        $this->artisan('mcp:manage')->assertExitCode(0);

        Http::assertSentCount(1);
    }

    public function test_manage_command_does_nothing_when_control_is_unreachable(): void
    {
        Http::fake(fn () => throw new ConnectionException('Could not resolve host: mcp-control'));
        AdminSetting::putValue('mcp', 'mcp_enabled', 'true');

        $this->artisan('mcp:manage')->assertExitCode(0);

        Http::assertNotSent(fn (HttpRequest $request) => $request->method() === 'POST');
    }
}
