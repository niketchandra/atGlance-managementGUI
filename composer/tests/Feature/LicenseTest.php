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
            'console' => ['instance_id' => 'abc', 'org_name' => 'Acme Corp', 'hostname' => 'ops-01', 'version' => '1.0.0'],
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

    public function test_verify_names_the_org_using_the_licence(): void
    {
        $this->fakeVerify($this->inUseBody());

        $result = app(LicenseClient::class)->verify(self::KEY);

        $this->assertTrue($result['ok']);
        $this->assertSame('Licence is valid. It is in use by "Acme Corp".', $result['message']);
    }

    public function test_activate_sends_org_and_console_identity(): void
    {
        $this->fakeActivate($this->inUseBody(), 201);

        $result = app(LicenseClient::class)->activate(self::KEY, 'Acme Corp');

        $this->assertTrue($result['ok']);
        $this->assertSame('in_use', $result['status']);
        $this->assertSame('Ops licence', $result['details']['name']);
        $this->assertSame('free', $result['details']['plan']);
        $this->assertSame('ops@company.com', $result['details']['extra']['user.email']);
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
        $this->assertStringContainsString('another AtGlance console', $result['message']);
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
            ->assertSee('ops@company.com');
    }

    public function test_failed_activation_does_not_save(): void
    {
        $this->actingAsRole(101);
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
            ->assertSee('Ops licence')
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
