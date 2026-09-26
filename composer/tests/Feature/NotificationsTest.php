<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\NotificationGroup;
use App\Models\Organization;
use App\Models\SystemRegister;
use App\Models\Workspace;
use App\Notifications\NotificationEvents;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private Workspace $ansible;
    private Workspace $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops']);
        $this->ansible = Workspace::create(['org_id' => 200, 'name' => 'ansible', 'status' => 'active']);
        $this->other = Workspace::create(['org_id' => 200, 'name' => 'kubernetes', 'status' => 'active']);

        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function allow(array $channels, array $credentials = []): void
    {
        foreach ($channels as $channel) {
            AdminSetting::putValue('notification', 'notify_' . $channel . '_allowed', 'true');
        }
        foreach ($credentials as $key => $value) {
            AdminSetting::putValue('notification', $key, $value, true);
        }
    }

    private function group(string $channel, string $target, ?Workspace $workspace, array $events): NotificationGroup
    {
        return NotificationGroup::create([
            'workspace_id' => $workspace?->id,
            'channel' => $channel,
            'name' => $channel . ' group',
            'target' => $target,
            'events' => $events,
            'enabled' => true,
        ]);
    }

    private function workspaceAdmin(): \App\Models\User
    {
        $admin = $this->actingAsRole(101);
        $this->ansible->addUser($admin->id, true);

        return $admin;
    }

    private function registerSystem(Workspace $workspace): SystemRegister
    {
        // Parent user/PAT rows are irrelevant here; the test transaction rolls back before FKs are checked.
        DB::statement('PRAGMA defer_foreign_keys = ON');

        return SystemRegister::create([
            'id' => random_int(100000, 999999),
            'pat_token_id' => 1,
            'user_id' => 1,
            'org_id' => 200,
            'workspace_id' => $workspace->id,
            'system_name' => 'web-01',
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'ip_address' => '10.0.0.5',
            'validation_hash' => 'hash-' . uniqid(),
        ]);
    }

    public function test_super_admin_allows_channels_and_admin_cannot(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.settings.notifications'), [
            'allowed' => ['telegram' => '1', 'slack' => '1', 'whatsapp' => '1', 'email' => '0'],
            'notify_telegram_bot_token' => '123456:ABCDEF',
        ])->assertSessionHasNoErrors();

        $this->assertSame('true', AdminSetting::getValue('notify_telegram_allowed'));
        $this->assertSame('false', AdminSetting::getValue('notify_whatsapp_allowed'), 'WhatsApp is not available yet');
        $this->assertSame('123456:ABCDEF', AdminSetting::getValue('notify_telegram_bot_token'));
        $this->assertTrue((bool) AdminSetting::query()->where('setting_key', 'notify_telegram_bot_token')->value('is_encrypted'));

        // A blank secret keeps the saved one.
        $this->post(route('admin.settings.notifications'), ['allowed' => ['telegram' => '1']]);
        $this->assertSame('123456:ABCDEF', AdminSetting::getValue('notify_telegram_bot_token'));

        $this->actingAsRole(101);
        $this->post(route('admin.settings.notifications'), ['allowed' => ['telegram' => '0']])
            ->assertSessionHasErrors('notification');
        $this->assertSame('true', AdminSetting::getValue('notify_telegram_allowed'));
    }

    public function test_workspace_admin_manages_only_their_workspace(): void
    {
        $this->allow(['email', 'webhook']);
        $this->workspaceAdmin();

        $this->get(route('admin.notifications'))
            ->assertOk()
            ->assertSee('ansible')
            ->assertDontSee('kubernetes')
            ->assertDontSee('Organization (org-wide events)');

        $this->post(route('admin.notifications.store'), [
            'scope' => (string) $this->ansible->id,
            'channel' => 'webhook',
            'name' => 'Ansible hooks',
            'target' => 'https://hooks.example.test/atglance',
            'events' => [NotificationEvents::SYSTEM_REGISTERED],
        ])->assertSessionHasNoErrors();
        $this->assertSame('https://hooks.example.test/atglance', NotificationGroup::query()->sole()->target);

        $payload = ['channel' => 'webhook', 'name' => 'x', 'target' => 'https://hooks.example.test/x', 'events' => [NotificationEvents::SYSTEM_REGISTERED]];
        $this->post(route('admin.notifications.store'), ['scope' => (string) $this->other->id] + $payload)->assertForbidden();
        $this->post(route('admin.notifications.store'), ['scope' => 'organization', 'events' => [NotificationEvents::BACKUP_FAILED]] + $payload)->assertForbidden();

        $foreign = $this->group('webhook', 'https://hooks.example.test/k8s', $this->other, [NotificationEvents::SYSTEM_REGISTERED]);
        $this->delete(route('admin.notifications.destroy', $foreign))->assertForbidden();
        $this->assertTrue(NotificationGroup::query()->whereKey($foreign->id)->exists());
    }

    public function test_group_validation_follows_channel_rules(): void
    {
        foreach (['mail_host' => 'smtp.acme.test', 'mail_port' => '587', 'mail_from_address' => 'alerts@acme.test'] as $key => $value) {
            AdminSetting::putValue('mail', $key, $value);
        }
        $this->allow(['email', 'slack', 'sms'], ['notify_sms_mailchimp_api_key' => 'key', 'notify_sms_from' => '+14155550100']);
        $this->workspaceAdmin();
        $base = ['scope' => (string) $this->ansible->id, 'name' => 'g', 'events' => [NotificationEvents::SYSTEM_REGISTERED]];

        $this->post(route('admin.notifications.store'), $base + ['channel' => 'teams', 'target' => 'https://x.test/'])->assertSessionHasErrors('channel');
        $this->post(route('admin.notifications.store'), $base + ['channel' => 'slack', 'target' => 'https://evil.test/hook'])->assertSessionHasErrors('target');
        $this->post(route('admin.notifications.store'), $base + ['channel' => 'email', 'target' => 'ops@acme.test, not-an-email'])->assertSessionHasErrors('target');
        $this->post(route('admin.notifications.store'), $base + ['channel' => 'sms', 'target' => '98765 43210'])->assertSessionHasErrors('target');
        $this->post(route('admin.notifications.store'), ['events' => [NotificationEvents::BACKUP_FAILED]] + $base + ['channel' => 'email', 'target' => 'ops@acme.test'])->assertSessionHasErrors('events.0');

        $this->post(route('admin.notifications.store'), $base + ['channel' => 'email', 'target' => "ops@acme.test\nlead@acme.test"])->assertSessionHasNoErrors();
        $this->assertSame('ops@acme.test,lead@acme.test', NotificationGroup::query()->sole()->target);
    }

    public function test_system_events_reach_only_subscribed_groups_of_that_workspace(): void
    {
        $this->allow(['webhook'], ['notify_webhook_signing_secret' => 'shh-secret']);
        $registered = $this->group('webhook', 'https://hooks.example.test/registered', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);
        $this->group('webhook', 'https://hooks.example.test/deregistered', $this->ansible, [NotificationEvents::SYSTEM_DEREGISTERED]);
        $this->group('webhook', 'https://hooks.example.test/other-workspace', $this->other, [NotificationEvents::SYSTEM_REGISTERED]);
        Http::fake(['hooks.example.test/*' => Http::response('ok')]);

        $system = $this->registerSystem($this->ansible);

        Http::assertSentCount(1);
        Http::assertSent(function (HttpRequest $request) {
            $body = $request->body();

            return $request->url() === 'https://hooks.example.test/registered'
                && $request->header('X-AtGlance-Event')[0] === NotificationEvents::SYSTEM_REGISTERED
                && $request->header('X-AtGlance-Signature')[0] === 'sha256=' . hash_hmac('sha256', $body, 'shh-secret')
                && $request['data']['system_id'] !== null
                && $request['facts']['System'] === 'web-01'
                && $request['facts']['Workspace'] === 'ansible';
        });
        $this->assertSame('sent', $registered->fresh()->last_status);

        $system->update(['status' => 'inactive']);
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === 'https://hooks.example.test/deregistered');
    }

    public function test_channel_payloads(): void
    {
        $this->allow(['slack', 'teams', 'telegram', 'n8n', 'sms'], [
            'notify_telegram_bot_token' => '123456:ABCDEF',
            'notify_n8n_auth_header_name' => 'X-N8N-Key',
            'notify_n8n_auth_header_value' => 'n8n-secret',
            'notify_sms_mailchimp_api_key' => 'mc-key',
            'notify_sms_from' => '+14155550100',
        ]);
        $events = [NotificationEvents::SYSTEM_REGISTERED];
        $this->group('slack', 'https://hooks.slack.com/services/T/B/X', $this->ansible, $events);
        $this->group('teams', 'https://prod.westus.logic.azure.com/workflows/abc', $this->ansible, $events);
        $this->group('telegram', '-1001234567890', $this->ansible, $events);
        $this->group('n8n', 'https://n8n.example.test/webhook/atglance', $this->ansible, $events);
        $this->group('sms', '+919876543210,+919876543211', $this->ansible, $events);
        Http::fake([
            'api.telegram.org/*' => Http::response(['ok' => true]),
            'mandrillapp.com/*' => Http::response([['status' => 'sent']]),
            '*' => Http::response('1'),
        ]);

        $this->registerSystem($this->ansible);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.slack.com/services/T/B/X'
            && str_contains($r['text'], '*System registered: web-01*') && str_contains($r['text'], '• Workspace: ansible'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'logic.azure.com')
            && $r['type'] === 'message'
            && $r['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
            && $r['attachments'][0]['content']['body'][0]['text'] === 'System registered: web-01');
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.telegram.org/bot123456:ABCDEF/sendMessage'
            && $r['chat_id'] === '-1001234567890' && str_contains($r['text'], 'System: web-01'));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://n8n.example.test/webhook/atglance'
            && $r->header('X-N8N-Key')[0] === 'n8n-secret' && $r['event'] === NotificationEvents::SYSTEM_REGISTERED);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://mandrillapp.com/api/1.0/messages/send-sms'
            && $r['key'] === 'mc-key' && $r['message']['to'] === '+919876543211' && $r['message']['from'] === '+14155550100');
        Http::assertSentCount(6);
    }

    public function test_email_uses_the_email_configuration_tab(): void
    {
        foreach (['mail_host' => 'smtp.acme.test', 'mail_port' => '2525', 'mail_from_address' => 'alerts@acme.test', 'mail_from_name' => 'Acme Alerts'] as $key => $value) {
            AdminSetting::putValue('mail', $key, $value);
        }
        config(['mail.mailers.smtp.transport' => 'array']);
        $this->allow(['email']);
        $this->group('email', 'ops@acme.test,lead@acme.test', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);

        $this->registerSystem($this->ansible);

        $messages = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->messages();
        $this->assertCount(1, $messages);
        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('[Acme Ops] System registered: web-01', $email->getSubject());
        $this->assertSame(['ops@acme.test', 'lead@acme.test'], array_map(fn ($address) => $address->getAddress(), $email->getTo()));
        $this->assertSame('alerts@acme.test', $email->getFrom()[0]->getAddress());
        $this->assertSame('smtp.acme.test', config('mail.mailers.smtp.host'));
    }

    public function test_failing_provider_is_recorded_without_breaking_registration(): void
    {
        $this->allow(['telegram'], ['notify_telegram_bot_token' => '999999:SECRETTOKEN']);
        $group = $this->group('telegram', '-1001234567890', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 6: Could not resolve host for https://api.telegram.org/bot999999:SECRETTOKEN/sendMessage'));

        $system = $this->registerSystem($this->ansible);

        $this->assertTrue(SystemRegister::query()->whereKey($system->id)->exists());
        $group->refresh();
        $this->assertSame('failed', $group->last_status);
        $this->assertStringContainsString('Could not resolve host', $group->last_error);
        $this->assertStringNotContainsString('SECRETTOKEN', $group->last_error);
    }

    public function test_disallowed_channel_and_disabled_group_are_skipped(): void
    {
        $this->allow(['webhook']);
        $this->group('slack', 'https://hooks.slack.com/services/T/B/X', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);
        $disabled = $this->group('webhook', 'https://hooks.example.test/off', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);
        $disabled->update(['enabled' => false]);
        Http::fake();

        $this->registerSystem($this->ansible);

        Http::assertNothingSent();
    }

    public function test_backup_result_goes_to_organization_groups(): void
    {
        config([
            'filesystems.disks.s3.key' => 'k', 'filesystems.disks.s3.secret' => 's',
            'filesystems.disks.s3.region' => 'us-east-1', 'filesystems.disks.s3.bucket' => 'b',
        ]);
        Storage::fake('s3');
        foreach (['s3_enabled', 'backup_restore_enabled', 'backup_config_to_s3'] as $key) {
            AdminSetting::putValue('storage', $key, 'true');
        }
        $this->allow(['webhook']);
        $this->group('webhook', 'https://hooks.example.test/org', null, [NotificationEvents::BACKUP_SUCCEEDED, NotificationEvents::BACKUP_FAILED]);
        $this->group('webhook', 'https://hooks.example.test/workspace', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);
        Http::fake();

        app(BackupService::class)->run(BackupService::TYPE_CONFIG);

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://hooks.example.test/org'
            && $r['event'] === NotificationEvents::BACKUP_SUCCEEDED
            && $r['title'] === 'Configuration files backup succeeded');
    }

    public function test_send_test_reports_the_result(): void
    {
        $this->allow(['webhook']);
        $this->workspaceAdmin();
        $group = $this->group('webhook', 'https://hooks.example.test/t', $this->ansible, [NotificationEvents::SYSTEM_REGISTERED]);

        Http::fake(['*' => Http::sequence()->push('down', 503)->push('ok')]);
        $this->post(route('admin.notifications.test', $group))->assertSessionHasErrors('notification');
        $this->assertSame('failed', $group->fresh()->last_status);

        $this->post(route('admin.notifications.test', $group))->assertSessionHas('success');
        $this->assertSame('sent', $group->fresh()->last_status);
    }
}
