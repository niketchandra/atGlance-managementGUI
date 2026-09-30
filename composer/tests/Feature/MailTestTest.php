<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class MailTestTest extends TestCase
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

    private function saveSmtp(string $host = 'smtp.acme.test', int $port = 587): void
    {
        AdminSetting::putValue('mail', 'mail_host', $host);
        AdminSetting::putValue('mail', 'mail_port', (string) $port);
        AdminSetting::putValue('mail', 'mail_username', 'ops');
        AdminSetting::putValue('mail', 'mail_password', 'secret', true);
        AdminSetting::putValue('mail', 'mail_encryption', '');
        AdminSetting::putValue('mail', 'mail_from_address', 'alerts@acme.test');
        AdminSetting::putValue('mail', 'mail_from_name', 'Acme Alerts');
    }

    public function test_sends_test_email_with_saved_settings(): void
    {
        $this->actingAsRole(101);
        $this->saveSmtp();
        $sent = [];
        Event::listen(MessageSending::class, function (MessageSending $event) use (&$sent) {
            $sent[] = $event->message;
        });
        config(['mail.mailers.smtp.transport' => 'array']);

        $this->postJson(route('admin.settings.mail.test'), ['recipient' => 'me@acme.test'])
            ->assertOk()
            ->assertJson(['ok' => true, 'message' => 'Test email sent to me@acme.test. Check the inbox (and the spam folder).']);

        $this->assertCount(1, $sent);
        $this->assertSame('me@acme.test', $sent[0]->getTo()[0]->getAddress());
        $this->assertStringContainsString('Test email', $sent[0]->getSubject());
    }

    public function test_invalid_recipient_is_refused(): void
    {
        $this->actingAsRole(101);
        $this->saveSmtp();

        $this->postJson(route('admin.settings.mail.test'), ['recipient' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recipient');
    }

    public function test_asks_to_save_settings_first(): void
    {
        $this->actingAsRole(101);

        $this->postJson(route('admin.settings.mail.test'), ['recipient' => 'me@acme.test'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Save the SMTP host, port and From address first.']);
    }

    public function test_reports_the_smtp_error(): void
    {
        $this->actingAsRole(101);
        $this->saveSmtp('127.0.0.1', 1);

        $response = $this->postJson(route('admin.settings.mail.test'), ['recipient' => 'me@acme.test'])
            ->assertOk()
            ->assertJson(['ok' => false]);

        $this->assertStringContainsString('127.0.0.1', $response->json('message'));
    }

    public function test_regular_user_cannot_send(): void
    {
        Mail::fake();
        $this->actingAsRole(102);

        $this->post(route('admin.settings.mail.test'), ['recipient' => 'me@acme.test'])->assertRedirect();
        Mail::assertNothingSent();
    }

    public function test_email_tab_shows_test_button(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'email']))
            ->assertOk()
            ->assertSee('id="mail-test-send"', false)
            ->assertSee('value="admin100@example.test"', false);
    }
}
