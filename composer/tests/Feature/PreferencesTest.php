<?php

namespace Tests\Feature;

use App\Jobs\SendAccountAlert;
use App\Models\ActivityLog;
use App\Models\AdminSetting;
use App\Models\User;
use App\Support\UserPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class PreferencesTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36';
    private const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';

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

    private function makeUser(string $email, int $rbac = 102): User
    {
        $user = new User();
        $user->forceFill([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret-pass',
            'rbac_id' => $rbac,
            'org_id' => 200,
            'status' => 'active',
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
        ])->save();

        return $user;
    }

    private function configureMail(): void
    {
        AdminSetting::putValue('email', 'mail_host', 'mailpit');
        AdminSetting::putValue('email', 'mail_port', '1025');
        AdminSetting::putValue('email', 'mail_from_address', 'noreply@example.test');
    }

    public function test_preferences_are_saved_and_password_alert_stays_on(): void
    {
        $user = $this->makeUser('me@example.test');

        $this->actingAs($user)->post(route('settings.preferences'), [
            'timezone' => 'Asia/Kolkata',
            'date_format' => 'iso',
            'per_page' => 10,
            'alerts' => ['new_signin' => '0', 'api_keys' => '1', 'system_deregistered' => '0', 'password_changed' => '0'],
        ])->assertSessionHasNoErrors();

        $prefs = UserPreferences::all($user->fresh());
        $this->assertSame('Asia/Kolkata', $prefs['timezone']);
        $this->assertSame('iso', $prefs['date_format']);
        $this->assertSame(10, $prefs['per_page']);
        $this->assertFalse($prefs['alerts']['new_signin']);
        $this->assertTrue($prefs['alerts']['api_keys']);
        $this->assertTrue($prefs['alerts']['password_changed'], 'Password alerts cannot be turned off');
    }

    public function test_invalid_values_are_rejected(): void
    {
        $user = $this->makeUser('me@example.test');

        $this->actingAs($user)->post(route('settings.preferences'), [
            'timezone' => 'Mars/Olympus',
            'date_format' => 'nope',
            'per_page' => 1000,
        ])->assertSessionHasErrors(['timezone', 'date_format', 'per_page']);
    }

    public function test_browser_time_zone_is_saved_only_when_unset(): void
    {
        $user = $this->makeUser('me@example.test');
        $this->actingAs($user);

        $this->post(route('settings.preferences.timezone'), ['timezone' => 'Europe/Berlin'])->assertNoContent();
        $this->assertSame('Europe/Berlin', UserPreferences::get($user->fresh(), 'timezone'));

        $this->post(route('settings.preferences.timezone'), ['timezone' => 'America/New_York'])->assertNoContent();
        $this->assertSame('Europe/Berlin', UserPreferences::get($user->fresh(), 'timezone'));
    }

    public function test_dates_use_the_users_zone_and_format(): void
    {
        $user = $this->makeUser('me@example.test');
        UserPreferences::save($user, ['timezone' => 'Asia/Kolkata', 'date_format' => 'day_first']);
        $this->actingAs($user->fresh());

        $this->assertSame('27 Sep 2026 15:30', UserPreferences::datetime('2026-09-27 10:00:00'));
        $this->assertSame('27 Sep 2026', UserPreferences::date('2026-09-27 10:00:00'));
        $this->assertSame('', UserPreferences::datetime(null));
    }

    public function test_profile_shows_saved_preferences_and_no_placeholders(): void
    {
        $user = $this->makeUser('me@example.test');
        UserPreferences::save($user, ['timezone' => 'America/New_York', 'date_format' => 'iso', 'per_page' => 50]);

        $this->actingAs($user->fresh())->get(route('profile'))
            ->assertOk()
            ->assertSee('America/New York')
            ->assertSee('2026-09-27 14:05')
            ->assertDontSee('English (US)');

        $this->get(route('settings'))
            ->assertOk()
            ->assertSee('Save Preferences')
            ->assertDontSee('High Response Time Alert');
    }

    public function test_new_device_sign_in_sends_an_alert_but_known_device_does_not(): void
    {
        Queue::fake();
        $this->configureMail();
        $user = $this->makeUser('me@example.test');

        // First sign-in ever: nothing to compare against, no alert.
        $this->withHeader('User-Agent', self::CHROME_WINDOWS)
            ->post('/login', ['email' => 'me@example.test', 'password' => 'secret-pass']);
        Queue::assertNothingPushed();
        $this->post('/logout');

        // Same browser and IP: no alert.
        $this->withHeader('User-Agent', self::CHROME_WINDOWS)
            ->post('/login', ['email' => 'me@example.test', 'password' => 'secret-pass']);
        Queue::assertNothingPushed();
        $this->post('/logout');

        // New browser: alert.
        $this->withHeader('User-Agent', self::FIREFOX_LINUX)
            ->post('/login', ['email' => 'me@example.test', 'password' => 'secret-pass']);
        Queue::assertPushed(SendAccountAlert::class, fn (SendAccountAlert $job) => $job->userId === $user->id
            && $job->subject === 'New sign-in to your account'
            && $job->details['Device'] === 'Firefox on Linux');
    }

    public function test_optional_alerts_follow_preferences_and_need_mail(): void
    {
        Queue::fake();
        $user = $this->makeUser('me@example.test');
        $this->actingAs($user);

        // No SMTP configured: nothing is queued.
        $this->postJson(route('settings.api-keys.create'), ['name' => 'laptop', 'expiration_date' => now()->addMonth()->toDateString()])->assertOk();
        Queue::assertNothingPushed();

        $this->configureMail();
        UserPreferences::save($user, ['alerts' => ['new_signin' => true, 'password_changed' => true, 'api_keys' => false, 'system_deregistered' => true]]);

        $this->postJson(route('settings.api-keys.create'), ['name' => 'desktop', 'expiration_date' => now()->addMonth()->toDateString()])->assertOk();
        Queue::assertNothingPushed();

        $this->post(route('password.update'), [
            'current_password' => 'secret-pass',
            'password' => 'another-pass',
            'password_confirmation' => 'another-pass',
        ])->assertSessionHasNoErrors();
        Queue::assertPushed(SendAccountAlert::class, fn (SendAccountAlert $job) => $job->subject === 'Your password was changed');
    }

    public function test_list_pages_use_rows_per_page(): void
    {
        $admin = $this->actingAsRole(100);
        UserPreferences::save($admin, ['per_page' => 10]);

        foreach (range(1, 12) as $i) {
            DB::table('system_register')->insert([
                'id' => 880000 + $i, 'pat_token_id' => 1, 'user_id' => $admin->id, 'org_id' => 200, 'workspace_id' => 0,
                'system_name' => 'host-' . $i, 'os_type' => 'linux', 'ip_address' => '10.0.1.' . $i,
                'created_at' => now()->subMinutes($i), 'updated_at' => now(),
            ]);
        }

        $this->actingAs($admin->fresh())->get(route('systems-registered'))
            ->assertOk()
            ->assertSee('Showing 1–10 of 12')
            ->assertSee('host-1')
            ->assertDontSee('host-12<', false);
    }
}
