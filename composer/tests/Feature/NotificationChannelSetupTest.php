<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\NotificationGroup;
use App\Notifications\Channels\N8nChannel;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Message;
use App\Support\NotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class NotificationChannelSetupTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

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

    private function group(string $channel, string $target): NotificationGroup
    {
        return new NotificationGroup(['channel' => $channel, 'name' => $channel, 'target' => $target, 'events' => [], 'enabled' => true]);
    }

    public function test_each_channel_shows_its_official_guide(): void
    {
        $this->actingAsRole(100);

        $response = $this->get(route('admin.settings', ['tab' => 'notification']))->assertOk();
        foreach (['teams', 'slack', 'telegram', 'n8n', 'sms'] as $channel) {
            $response->assertSee(NotificationSettings::GUIDES[$channel]['docs'], false);
        }
        $response->assertSee('Send webhook alerts to a channel')
            ->assertSee('Add New Webhook to Workspace')
            ->assertSee('/newbot')
            ->assertSee('Production URL')
            ->assertSee('X-AtGlance-Signature')
            ->assertSee('Consent type')
            ->assertSee('Basic auth: user (optional)')
            ->assertSee('Send silently');
    }

    public function test_new_fields_are_saved(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.notifications'), [
            'allowed' => ['sms' => '1', 'n8n' => '1', 'telegram' => '1'],
            'notify_sms_consent' => 'onetime',
            'notify_telegram_silent' => '1',
            'notify_n8n_basic_user' => 'atglance',
            'notify_n8n_basic_password' => 'pw',
        ])->assertSessionHasNoErrors();

        $this->assertSame('onetime', NotificationSettings::credential('notify_sms_consent'));
        $this->assertSame('true', NotificationSettings::credential('notify_telegram_silent'));
        $this->assertSame('pw', NotificationSettings::credential('notify_n8n_basic_password'));

        $this->post(route('admin.settings.notifications'), ['notify_sms_consent' => 'bogus'])
            ->assertSessionHasErrors('notify_sms_consent');

        // Unticked checkbox turns the option off.
        $this->post(route('admin.settings.notifications'), ['notify_sms_consent' => 'onetime'])->assertSessionHasNoErrors();
        $this->assertSame('false', NotificationSettings::credential('notify_telegram_silent'));
    }

    public function test_sms_uses_documented_body_and_default_consent(): void
    {
        AdminSetting::putValue('notification', 'notify_sms_mailchimp_api_key', 'mc-key', true);
        AdminSetting::putValue('notification', 'notify_sms_from', '+14155550100');
        Http::fake(['mandrillapp.com/*' => Http::response([['status' => 'sent']])]);

        app(SmsChannel::class)->send($this->group('sms', '+919876543210'), new Message('test', 'Hello'));

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://mandrillapp.com/api/1.1/messages/send-sms'
            && $r['message']['sms']['consent'] === 'recurring'
            && $r['message']['sms']['to'] === '+919876543210');
    }

    public function test_n8n_sends_basic_auth_and_telegram_can_send_silently(): void
    {
        AdminSetting::putValue('notification', 'notify_n8n_basic_user', 'atglance');
        AdminSetting::putValue('notification', 'notify_n8n_basic_password', 'pw', true);
        AdminSetting::putValue('notification', 'notify_telegram_bot_token', '123:ABC', true);
        AdminSetting::putValue('notification', 'notify_telegram_silent', 'true');
        Http::fake([
            'n8n.example.test/*' => Http::response('ok'),
            'api.telegram.org/*' => Http::response(['ok' => true]),
        ]);

        app(N8nChannel::class)->send($this->group('n8n', 'https://n8n.example.test/webhook/x'), new Message('test', 'Hello'));
        app(TelegramChannel::class)->send($this->group('telegram', '-1001234567890'), new Message('test', 'Hello'));

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://n8n.example.test')
            && $r->header('Authorization')[0] === 'Basic ' . base64_encode('atglance:pw'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'api.telegram.org') && $r['disable_notification'] === true);
    }
}
