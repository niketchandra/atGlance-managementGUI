<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\Organization;
use App\Models\PatToken;
use App\Models\User;
use App\Services\LicenseClient;
use App\Support\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const KEY = 'atg_test_licence_key_123456';
    private const VERIFY_URL = 'https://atglance.live/api/licenses/verify';
    private const ACTIVATE_URL = 'https://atglance.live/api/licenses/activate';

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

    private function fakeVerify(array $body, int $status = 200): void
    {
        Http::fake([self::VERIFY_URL => Http::response($body, $status)]);
    }

    private function fakeActivate(array $body, int $status = 201): void
    {
        Http::fake([self::ACTIVATE_URL => Http::response($body, $status)]);
    }

    private function inUseBody(): array
    {
        return [
            'valid' => true,
            'status' => 'in_use',
            'user' => ['id' => '1', 'email' => 'ops@company.com', 'name' => 'ops'],
            'license' => ['name' => 'Ops licence'],
            'plan' => 'free',
            'console' => ['instance_id' => License::instanceId(), 'org_name' => 'Acme Corp', 'hostname' => 'ops-01', 'version' => '1.0.0', 'activated_at' => '2026-09-01T10:00:00Z'],
        ];
    }

    public function test_verify_only_checks_the_key(): void
    {
        $this->fakeVerify(['valid' => true, 'status' => 'available', 'license' => ['name' => 'Ops licence'], 'plan' => 'free']);

        $result = app(LicenseClient::class)->verify(self::KEY);

        $this->assertTrue($result['ok']);
        $this->assertSame('available', $result['status']);
        $this->assertSame('Licence is valid.', $result['message']);
        $this->assertSame('Ops licence', $result['details']['name']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::VERIFY_URL
            && $request->hasHeader('Authorization', 'Bearer ' . self::KEY)
            && $request->hasHeader('Accept', 'application/json')
            && $request->data() === []);
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL);
    }

    private function otherConsoleBody(): array
    {
        $body = $this->inUseBody();
        $body['console']['instance_id'] = 'another-console';

        return $body;
    }

    private function availableBody(): array
    {
        return ['valid' => true, 'status' => 'available', 'license' => ['name' => 'Ops licence'], 'plan' => 'free'];
    }

    public function test_check_refuses_key_in_use_by_another_console_without_naming_the_org(): void
    {
        $this->fakeVerify($this->otherConsoleBody());

        $result = app(LicenseClient::class)->checkAvailable(self::KEY);

        $this->assertFalse($result['ok']);
        $this->assertSame(LicenseClient::IN_USE_MESSAGE, $result['message']);
        $this->assertStringNotContainsString('Acme Corp', $result['message']);
    }

    public function test_check_refuses_key_in_use_even_by_this_console(): void
    {
        // A reinstall keeps the same console ID; an "In Use" key must still be refused.
        $this->fakeVerify($this->inUseBody());
        $this->fakeActivate($this->inUseBody(), 200);

        $result = app(LicenseClient::class)->activateIfAvailable(self::KEY, 'Acme Corp');

        $this->assertFalse($result['ok']);
        $this->assertSame(LicenseClient::IN_USE_MESSAGE, $result['message']);
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL);
    }

    public function test_in_use_message_does_not_say_another_console(): void
    {
        $this->assertStringNotContainsString('another', LicenseClient::IN_USE_MESSAGE);
    }

    public function test_check_allows_available_key(): void
    {
        $this->fakeVerify($this->availableBody());

        $result = app(LicenseClient::class)->checkAvailable(self::KEY);

        $this->assertTrue($result['ok']);
        $this->assertSame('Licence is valid and ready to activate.', $result['message']);
    }

    public function test_in_use_key_never_reaches_activate_api(): void
    {
        $this->fakeVerify($this->otherConsoleBody());
        $this->fakeActivate($this->inUseBody(), 201);

        $result = app(LicenseClient::class)->activateIfAvailable(self::KEY, 'New Org');

        $this->assertFalse($result['ok']);
        $this->assertSame(LicenseClient::IN_USE_MESSAGE, $result['message']);
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL);
    }

    public function test_admin_cannot_save_key_in_use_by_another_console(): void
    {
        $this->actingAsRole(100);
        $this->fakeVerify($this->otherConsoleBody());
        $this->fakeActivate($this->inUseBody(), 201);

        $this->post(route('admin.settings.licence'), ['license_key' => self::KEY])
            ->assertSessionHasErrors(['license_key' => LicenseClient::IN_USE_MESSAGE]);

        $this->assertFalse(License::isActive());
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL);
    }

    public function test_activate_sends_org_and_console_identity(): void
    {
        $this->fakeActivate($this->inUseBody(), 201);

        $result = app(LicenseClient::class)->activate(self::KEY, 'Acme Corp');

        $this->assertTrue($result['ok']);
        $this->assertSame('in_use', $result['status']);
        $this->assertSame('Ops licence', $result['details']['name']);
        $this->assertSame('free', $result['details']['plan']);
        $this->assertArrayNotHasKey('user.email', $result['details']['extra']);
        $this->assertSame('ops-01', $result['details']['extra']['console.hostname']);
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL
            && $request->hasHeader('Authorization', 'Bearer ' . self::KEY)
            && $request->hasHeader('Accept', 'application/json')
            && $request['org_name'] === 'Acme Corp'
            && $request['instance_id'] === License::instanceId()
            && isset($request['hostname'], $request['version']));
    }

    public function test_activate_requires_org_name(): void
    {
        $result = app(LicenseClient::class)->activate(self::KEY, '  ');

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }

    public function test_instance_id_is_stable(): void
    {
        $this->assertSame(License::instanceId(), License::instanceId());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', License::instanceId());
    }

    public function test_client_explains_licence_used_by_another_console(): void
    {
        $this->fakeActivate(['valid' => false, 'status' => 'in_use_elsewhere'], 409);

        $result = app(LicenseClient::class)->activate(self::KEY, 'Acme Corp');

        $this->assertFalse($result['ok']);
        $this->assertSame(LicenseClient::IN_USE_MESSAGE, $result['message']);
    }

    public function test_client_explains_unverified_licence(): void
    {
        $this->fakeVerify(['valid' => false, 'status' => 'unverified'], 403);

        $result = app(LicenseClient::class)->verify(self::KEY);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('code emailed', $result['message']);
    }

    public function test_client_reports_wrong_key(): void
    {
        $this->fakeVerify(['message' => 'Unauthenticated.'], 401);

        $result = app(LicenseClient::class)->verify(self::KEY);

        $this->assertFalse($result['ok']);
        $this->assertSame('The licence key is wrong or has been revoked.', $result['message']);
    }

    public function test_admin_saves_activated_licence(): void
    {
        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Corp']);
        $this->actingAsRole(100);
        $this->fakeVerify($this->availableBody());
        $this->fakeActivate($this->inUseBody(), 201);

        $this->post(route('admin.settings.licence'), ['license_key' => self::KEY])
            ->assertRedirect(route('admin.settings', ['tab' => 'licence']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(License::isActive());
        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL
            && $request['org_name'] === 'Acme Corp');
        $summary = License::summary();
        $this->assertSame('Ops licence', $summary['name']);
        $this->assertSame('free', $summary['plan']);

        $row = AdminSetting::query()->where('setting_key', 'license_key')->first();
        $this->assertTrue($row->is_encrypted);
        $this->assertStringNotContainsString(self::KEY, $row->setting_value);

        $this->get(route('admin.settings', ['tab' => 'licence']))
            ->assertOk()
            ->assertSee('Licence active')
            ->assertSee('Sep 1, 2026')
            ->assertDontSee('ops@company.com')
            ->assertDontSee('user.email');
    }

    public function test_sidebar_shows_activated_and_validated_dates(): void
    {
        $this->fakeActivate($this->inUseBody(), 201);
        License::store(self::KEY, app(LicenseClient::class)->activate(self::KEY, 'Acme Corp'));

        $this->actingAsRole(100);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Activated On Sep 1, 2026')
            ->assertSee('Validated On ' . now()->format('M j, Y'))
            ->assertDontSee('No expiry date');
    }

    public function test_daily_check_keeps_licence_active_and_updates_validated_date(): void
    {
        $this->activateLicense();
        AdminSetting::putValue('license', 'license_verified_at', '2026-01-01 00:00:00');
        $this->fakeVerify($this->inUseBody());

        $this->artisan('license:check')->assertExitCode(0);

        $this->assertTrue(License::isActive());
        $this->assertSame(now()->format('M j, Y'), License::date(License::summary()['verified_at']));
        $this->assertSame('2026-09-01T10:00:00Z', License::summary()['activated_at']);
        Http::assertNotSent(fn (HttpRequest $request) => $request->url() === self::ACTIVATE_URL);
    }

    public function test_daily_check_deactivates_revoked_licence(): void
    {
        $this->activateLicense();
        $this->fakeVerify(['message' => 'Unauthenticated.'], 401);

        $this->artisan('license:check')->assertExitCode(0);

        $this->assertFalse(License::isActive());
        $this->assertSame('The licence key is wrong or has been revoked.', License::summary()['check_message']);
    }

    public function test_daily_check_deactivates_licence_owned_by_another_console(): void
    {
        $this->activateLicense();
        $body = $this->inUseBody();
        $body['console']['instance_id'] = 'another-console';
        $this->fakeVerify($body);

        $this->artisan('license:check')->assertExitCode(0);

        $this->assertFalse(License::isActive());
        $this->assertStringContainsString('another AtGlance console', License::summary()['check_message']);
    }

    public function test_daily_check_keeps_licence_when_server_unreachable(): void
    {
        $this->activateLicense();
        $this->fakeVerify(['message' => 'Server Error'], 503);

        $this->artisan('license:check')->assertExitCode(0);

        $this->assertTrue(License::isActive());
    }

    public function test_daily_check_is_scheduled(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'license:check'));

        $this->assertCount(1, $events);
        $this->assertSame('15 2 * * *', $events->first()->expression);
    }

    public function test_failed_activation_does_not_save(): void
    {
        $this->actingAsRole(101);
        $this->fakeVerify($this->availableBody());
        $this->fakeActivate(['valid' => false, 'status' => 'in_use_elsewhere'], 409);

        $this->post(route('admin.settings.licence'), ['license_key' => self::KEY])
            ->assertSessionHasErrors('license_key');

        $this->assertFalse(License::isActive());
    }

    public function test_licence_tab_renders(): void
    {
        $this->actingAsRole(100);

        $this->get(route('admin.settings', ['tab' => 'licence']))
            ->assertOk()
            ->assertSee('No active licence.');
    }

    public function test_admin_cannot_create_user_without_licence(): void
    {
        $this->actingAsRole(100);

        $this->post(route('admin.users.store'), [
            'username' => 'newbie',
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newbie@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'user',
        ])->assertSessionHasErrors('licence');

        $this->assertDatabaseMissing('users', ['email' => 'newbie@example.test']);
    }

    public function test_admin_creates_user_with_licence(): void
    {
        $this->activateLicense();
        $this->actingAsRole(100);

        $this->post(route('admin.users.store'), [
            'username' => 'newbie',
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newbie@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'user',
        ])->assertSessionDoesntHaveErrors('licence');

        $this->assertDatabaseHas('users', ['email' => 'newbie@example.test']);
    }

    public function test_api_key_creation_blocked_without_licence(): void
    {
        $user = $this->actingAsRole(102);

        $this->post(route('settings.api-keys.create'), ['name' => 'prod'])
            ->assertStatus(403)
            ->assertJson(['success' => false]);

        $this->assertSame(0, PatToken::query()->where('user_id', $user->id)->count());
    }

    public function test_api_key_creation_allowed_with_licence(): void
    {
        $this->activateLicense();
        $this->actingAsRole(102);

        $this->post(route('settings.api-keys.create'), ['name' => 'prod', 'expiration_date' => now()->addMonth()->toDateString()])
            ->assertOk()
            ->assertJson(['success' => true]);
    }

    public function test_web_registration_blocked_without_licence(): void
    {
        $this->post(route('register'), [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertSessionHasErrors('register');

        $this->assertDatabaseMissing('users', ['email' => 'someone@example.test']);
    }

    public function test_api_registration_blocked_without_licence(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'Someone',
            'email' => 'someone@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(403);

        $this->assertSame(0, User::query()->where('email', 'someone@example.test')->count());
    }

    public function test_sidebar_licence_card_shows_upgrade_to_admins_when_active(): void
    {
        License::store(self::KEY, ['status' => LicenseClient::VERIFIED_STATUS, 'details' => ['name' => 'Ops licence', 'plan' => 'free']]);

        $this->actingAsRole(100);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('ag-licence-card', false)
            ->assertDontSee('Ops licence')
            ->assertSee('Free')
            ->assertSee('Upgrade')
            ->assertSee(License::portalUrl(), false);
    }

    public function test_sidebar_licence_card_asks_admins_to_add_a_missing_licence(): void
    {
        $this->actingAsRole(101);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Not licensed')
            ->assertSee('Add licence')
            ->assertSee(route('admin.settings', ['tab' => 'licence']), false);
    }

    public function test_regular_users_see_licence_status_without_admin_buttons(): void
    {
        License::store(self::KEY, ['status' => LicenseClient::VERIFIED_STATUS, 'details' => ['plan' => 'free']]);

        $this->actingAsRole(102);
        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('ag-licence-card', false)
            ->assertDontSee('Upgrade')
            ->assertDontSee('Add licence');
    }
}
