<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Support\AiSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class AiConnectTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const KEY = 'sk-test-secret-key-123456';

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

    private function saveAnthropic(): void
    {
        AdminSetting::putValue('ai', 'ai_enabled', 'true');
        AdminSetting::putValue('ai', 'ai_provider', 'anthropic');
        AdminSetting::putValue('ai', 'ai_base_url', '');
        AdminSetting::putValue('ai', 'ai_model', 'claude-opus-5');
        AdminSetting::putValue('ai', 'ai_api_key', self::KEY, true);
    }

    public function test_super_admin_saves_settings_with_encrypted_key(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('ai', 'ai_plugin_enabled', 'true');

        $this->post(route('admin.settings.ai'), [
            'ai_provider' => 'openai',
            'ai_config' => ['openai' => ['model' => 'gpt-test']],
            'ai_key' => ['openai' => self::KEY],
        ])->assertRedirect(route('admin.settings', ['tab' => 'ai-connect']))->assertSessionHasNoErrors();

        $this->assertTrue(AiSettings::enabled());
        $this->assertSame('openai', AiSettings::provider());
        $this->assertSame('gpt-test', AiSettings::model());
        $this->assertSame(self::KEY, AiSettings::apiKey());

        $row = AdminSetting::query()->where('setting_key', 'ai_provider_keys')->first();
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString(self::KEY, $row->setting_value);
    }

    public function test_blank_key_keeps_saved_key_and_page_never_shows_it(): void
    {
        $this->saveAnthropic();
        $this->actingAsRole(100);

        $this->post(route('admin.settings.ai'), [
            'ai_provider' => 'anthropic',
            'ai_config' => ['anthropic' => ['model' => 'claude-sonnet-5']],
            'ai_key' => ['anthropic' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame(self::KEY, AiSettings::apiKey());
        $this->assertSame('claude-sonnet-5', AiSettings::model());

        $this->get(route('admin.settings', ['tab' => 'ai-connect']))
            ->assertOk()
            ->assertSee('Saved; leave blank to keep')
            ->assertDontSee(self::KEY);
    }

    public function test_saved_key_is_not_reused_for_another_provider_or_url(): void
    {
        $this->saveAnthropic();
        $this->actingAsRole(100);

        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]])]);

        $this->postJson(route('admin.settings.ai.test'), [
            'ai_provider' => 'custom',
            'ai_base_url' => 'https://evil.example.test/v1',
            'ai_model' => 'm',
        ])->assertOk();

        Http::assertSent(fn (HttpRequest $request) => !$request->hasHeader('Authorization') && !$request->hasHeader('x-api-key'));

        $this->post(route('admin.settings.ai'), [
            'ai_provider' => 'anthropic',
            'ai_config' => ['anthropic' => ['base_url' => 'https://proxy.example.test', 'model' => 'claude-opus-5']],
        ]);
        $this->assertSame('', AiSettings::apiKey());
    }

    public function test_active_provider_needs_model_and_key(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('ai', 'ai_plugin_enabled', 'true');

        $this->post(route('admin.settings.ai'), [
            'ai_provider' => 'anthropic',
            'ai_config' => ['anthropic' => ['model' => 'claude-opus-5']],
        ])->assertSessionHasErrors('ai_config.anthropic');
        $this->assertFalse(AiSettings::enabled());
        // What was entered is still saved.
        $this->assertSame('claude-opus-5', AiSettings::modelFor('anthropic'));

        $this->post(route('admin.settings.ai'), [
            'ai_provider' => 'ollama',
            'ai_config' => ['ollama' => ['model' => 'llama3.2']],
        ])->assertSessionHasNoErrors();
        $this->assertTrue(AiSettings::enabled());
    }

    public function test_custom_and_azure_require_base_url(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('ai', 'ai_plugin_enabled', 'true');

        $this->post(route('admin.settings.ai'), ['ai_provider' => 'custom', 'ai_config' => ['custom' => ['model' => 'x']]])
            ->assertSessionHasErrors('ai_config.custom');
        $this->post(route('admin.settings.ai'), ['ai_provider' => 'azure', 'ai_config' => ['azure' => ['model' => 'x', 'base_url' => 'ftp://nope']]])
            ->assertSessionHasErrors('ai_config.azure.base_url');
    }

    public function test_switching_provider_keeps_each_providers_settings(): void
    {
        $this->actingAsRole(100);
        AdminSetting::putValue('ai', 'ai_plugin_enabled', 'true');

        $this->post(route('admin.settings.ai'), [
            'ai_provider' => 'openai',
            'ai_config' => ['openai' => ['model' => 'gpt-test'], 'ollama' => ['model' => 'llama3.2']],
            'ai_key' => ['openai' => self::KEY],
        ])->assertSessionHasNoErrors();

        $this->post(route('admin.settings.ai'), ['ai_provider' => 'ollama'])->assertSessionHasNoErrors();
        $this->assertSame('ollama', AiSettings::provider());
        $this->assertSame('llama3.2', AiSettings::model());

        $this->post(route('admin.settings.ai'), ['ai_provider' => 'openai'])->assertSessionHasNoErrors();
        $this->assertSame('gpt-test', AiSettings::model());
        $this->assertSame(self::KEY, AiSettings::apiKey());
    }

    public function test_plugin_shows_the_tab_and_gates_ai(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'ai-connect']))
            ->assertOk()
            ->assertDontSee('data-tab="ai-connect"', false)
            ->assertSee('AI Connect');
        $this->post(route('admin.settings.ai'), ['ai_provider' => 'ollama'])->assertSessionHasErrors('ai');

        $this->post(route('admin.settings.ai.plugin'), ['enabled' => '1'])
            ->assertRedirect(route('admin.settings', ['tab' => 'plugins', 'plugin' => 'ai']));
        $this->assertTrue(AiSettings::pluginEnabled());
        $this->assertFalse(AiSettings::enabled());

        $this->get(route('admin.settings', ['tab' => 'ai-connect', 'provider' => 'ollama']))
            ->assertOk()
            ->assertSee('data-tab="ai-connect"', false)
            ->assertSee('data-split="provider"', false)
            ->assertSee('id="ai-panel-ollama" data-key="ollama" style="display: block;"', false);

        $this->post(route('admin.settings.ai.plugin'), ['enabled' => '0']);
        $this->assertFalse(AiSettings::pluginEnabled());
    }

    public function test_install_with_ai_on_keeps_working(): void
    {
        $this->saveAnthropic();

        $this->assertTrue(AiSettings::pluginEnabled());
        $this->assertTrue(AiSettings::enabled());
        $this->assertSame(self::KEY, AiSettings::apiKeyFor('anthropic'));
        $this->assertSame('', AiSettings::apiKeyFor('openai'));
    }

    public function test_admin_cannot_change_or_test(): void
    {
        $this->saveAnthropic();
        $this->actingAsRole(101);

        $this->post(route('admin.settings.ai'), ['ai_provider' => 'openai', 'ai_config' => ['openai' => ['model' => 'x']]])
            ->assertSessionHasErrors('ai');
        $this->assertSame('anthropic', AiSettings::provider());
        $this->post(route('admin.settings.ai.plugin'), ['enabled' => '0'])->assertForbidden();

        $this->postJson(route('admin.settings.ai.test'), ['ai_provider' => 'anthropic'])->assertForbidden();
        $this->postJson(route('admin.settings.ai.models'), ['ai_provider' => 'anthropic'])->assertForbidden();

        $this->get(route('admin.settings', ['tab' => 'ai-connect']))
            ->assertOk()
            ->assertSee('Only the super admin can change these settings.');
    }

    public function test_anthropic_test_call_uses_messages_api_and_records_result(): void
    {
        $this->saveAnthropic();
        $this->actingAsRole(100);

        Http::fake(['api.anthropic.com/v1/messages' => Http::response([
            'content' => [['type' => 'text', 'text' => 'OK']],
            'stop_reason' => 'end_turn',
        ])]);

        $this->postJson(route('admin.settings.ai.test'), [
            'ai_provider' => 'anthropic',
            'ai_model' => 'claude-opus-5',
        ])->assertOk()->assertJson(['ok' => true, 'reply' => 'OK']);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->header('x-api-key') === [self::KEY]
            && $request->header('anthropic-version') === ['2023-06-01']
            && $request['model'] === 'claude-opus-5'
            && !$request->hasHeader('Authorization'));

        $this->assertTrue(AiSettings::lastTest()['ok']);
    }

    public function test_openai_compatible_providers_use_chat_completions(): void
    {
        $this->actingAsRole(100);

        Http::fake([
            'host.docker.internal:18789/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
            'res.openai.azure.com/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]]),
        ]);

        $this->postJson(route('admin.settings.ai.test'), [
            'ai_provider' => 'openclaw',
            'ai_model' => 'openclaw',
            'ai_api_key' => 'gateway-token-abc',
        ])->assertJson(['ok' => true]);

        $this->postJson(route('admin.settings.ai.test'), [
            'ai_provider' => 'azure',
            'ai_base_url' => 'https://res.openai.azure.com/openai/v1/',
            'ai_model' => 'my-deployment',
            'ai_api_key' => 'azure-key-123456',
        ])->assertJson(['ok' => true]);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'http://host.docker.internal:18789/v1/chat/completions'
            && $request->header('Authorization') === ['Bearer gateway-token-abc']
            && $request['model'] === 'openclaw');
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://res.openai.azure.com/openai/v1/chat/completions'
            && $request->header('api-key') === ['azure-key-123456']
            && !$request->hasHeader('Authorization'));
    }

    public function test_failure_message_is_clear_and_redacts_key(): void
    {
        $this->actingAsRole(100);

        Http::fake(['api.openai.com/*' => Http::response([
            'error' => ['message' => 'Incorrect API key provided: ' . self::KEY],
        ], 401)]);

        $response = $this->postJson(route('admin.settings.ai.test'), [
            'ai_provider' => 'openai',
            'ai_model' => 'gpt-test',
            'ai_api_key' => self::KEY,
        ])->assertOk()->assertJson(['ok' => false]);

        $this->assertStringContainsString('HTTP 401', $response->json('message'));
        $this->assertStringContainsString('Check the API key.', $response->json('message'));
        $this->assertStringNotContainsString(self::KEY, $response->json('message'));
        $this->assertFalse(AiSettings::lastTest()['ok']);
        $this->assertStringNotContainsString(self::KEY, (string) AdminSetting::getValue('ai_last_test'));
    }

    public function test_load_models_for_ollama(): void
    {
        $this->actingAsRole(100);

        Http::fake(['host.docker.internal:11434/v1/models' => Http::response([
            'data' => [['id' => 'qwen3:8b'], ['id' => 'llama3.2:latest']],
        ])]);

        $this->postJson(route('admin.settings.ai.models'), ['ai_provider' => 'ollama'])
            ->assertOk()
            ->assertJson(['ok' => true, 'models' => ['llama3.2:latest', 'qwen3:8b']]);

        Http::assertSent(fn (HttpRequest $request) => !$request->hasHeader('Authorization'));
    }
}
