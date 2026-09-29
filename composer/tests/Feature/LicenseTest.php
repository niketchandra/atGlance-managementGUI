<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
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

    private function inUseBody(): array
    {
        return [
            'valid' => true,
            'status' => 'in_use',
            'user' => ['id' => '1', 'email' => 'ops@company.com', 'name' => 'ops'],
            'license' => ['name' => 'Ops licence'],
            'plan' => 'free',
            'console' => ['instance_id' => 'abc', 'hostname' => 'ops-01', 'version' => '1.0.0'],
        ];
    }

    public function test_client_accepts_in_use_and_reads_details(): void
    {
        $this->fakeVerify($this->inUseBody(), 201);

        $result = app(LicenseClient::class)->verify(self::KEY);

        $this->assertTrue($result['ok']);
        $this->assertSame('Ops licence', $result['details']['name']);
        $this->assertSame('free', $result['details']['plan']);
        $this->assertSame('ops@company.com', $result['details']['extra']['user.email']);
        $this->assertSame('ops-01', $result['details']['extra']['console.hostname']);
        Http::assertSent(fn (HttpRequest $request) => $request->hasHeader('Authorization', 'Bearer ' . self::KEY)
            && $request->hasHeader('Accept', 'application/json')
            && $request['instance_id'] === License::instanceId()
            && isset($request['hostname'], $request['version']));
    }

    public function test_instance_id_is_stable(): void
    {
        $this->assertSame(License::instanceId(), License::instanceId());
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', License::instanceId());
    }

    public function test_client_explains_licence_used_by_another_console(): void
    {
        $this->fakeVerify(['valid' => false, 'status' => 'in_use_elsewhere'], 409);

        $result = app(LicenseClient::class)->verify(self::KEY);

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

    public function test_admin_saves_verified_licence(): void
    {
        $this->actingAsRole(100);
        $this->fakeVerify($this->inUseBody(), 201);

        $this->post(route('admin.settings.licence'), ['license_key' => self::KEY])
            ->assertRedirect(route('admin.settings', ['tab' => 'licence']))
            ->assertSessionHasNoErrors();

        $this->assertTrue(License::isActive());
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

    public function test_failed_verification_does_not_save(): void
    {
        $this->actingAsRole(101);
        $this->fakeVerify(['valid' => false, 'status' => 'in_use_elsewhere'], 409);

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
}
