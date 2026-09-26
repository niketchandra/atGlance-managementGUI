<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ConfigurationFile;
use App\Models\User;
use App\Support\ActivityFeed;
use App\Support\UserAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class RecentActivityTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const CHROME_WINDOWS = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36';

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

    public function test_login_is_recorded_once_with_device_and_shown_on_profile(): void
    {
        $user = $this->makeUser('me@example.test');

        $this->withHeader('User-Agent', self::CHROME_WINDOWS)
            ->post('/login', ['email' => 'me@example.test', 'password' => 'secret-pass'])
            ->assertRedirect();

        $rows = ActivityLog::query()->where('path', '/login')->get();
        $this->assertCount(1, $rows, 'The middleware must not add a raw duplicate');
        $this->assertSame('auth.login', $rows[0]->event);
        $this->assertSame('success', $rows[0]->outcome);
        $this->assertSame($user->id, (int) $rows[0]->user_id);

        $this->get(route('profile'))
            ->assertOk()
            ->assertSee('Signed in from Chrome on Windows')
            ->assertDontSee('Updated API Rate Limits');
    }

    public function test_failed_login_is_recorded_as_failure(): void
    {
        $user = $this->makeUser('me@example.test');

        $this->post('/login', ['email' => 'me@example.test', 'password' => 'wrong-pass']);
        $this->post('/login', ['email' => 'nobody@example.test', 'password' => 'wrong-pass']);

        $mine = ActivityLog::query()->where('user_id', $user->id)->sole();
        $this->assertSame('auth.login_failed', $mine->event);
        $this->assertSame('failure', $mine->outcome);
        $this->assertSame(1, ActivityLog::query()->whereNull('user_id')->where('event', 'auth.login_failed')->count());

        $items = ActivityFeed::latest($user->id);
        $this->assertSame('failure', $items[0]['outcome']);
        $this->assertStringStartsWith('Failed sign-in attempt', $items[0]['text']);
    }

    public function test_password_change_and_api_key_creation_appear(): void
    {
        $user = $this->makeUser('me@example.test');
        $this->actingAs($user);

        $this->post(route('password.update'), [
            'current_password' => 'secret-pass',
            'password' => 'new-secret-pass',
            'password_confirmation' => 'new-secret-pass',
        ])->assertSessionHasNoErrors();

        $this->postJson(route('settings.api-keys.create'), ['name' => 'laptop', 'expiration_date' => now()->addMonth()->toDateString()])
            ->assertOk();

        $texts = ActivityFeed::latest($user->id)->pluck('text')->all();
        $this->assertContains('Changed password', $texts);
        $this->assertContains('Created API key "laptop"', $texts);

        $this->get(route('profile'))->assertSee('Changed password')->assertSee('Created API key &quot;laptop&quot;', false);
    }

    public function test_only_own_events_are_shown_and_payload_is_never_rendered(): void
    {
        $me = $this->makeUser('me@example.test');
        $other = $this->makeUser('other@example.test');

        ActivityLog::create(['user_id' => $other->id, 'event' => 'password.changed', 'description' => 'Other user secret event', 'outcome' => 'success', 'method' => 'POST', 'path' => '/password/update']);
        ActivityLog::create(['user_id' => $me->id, 'method' => 'POST', 'path' => '/settings/update', 'status_code' => 302, 'request_payload' => '{"phone":"PAYLOAD-MARKER"}']);

        $this->actingAs($me);
        $this->get(route('profile.activity'))
            ->assertOk()
            ->assertSee('Updated profile')
            ->assertDontSee('Other user secret event')
            ->assertDontSee('PAYLOAD-MARKER');
    }

    public function test_legacy_rows_are_described_from_path(): void
    {
        $user = $this->makeUser('me@example.test');
        ActivityLog::create(['user_id' => $user->id, 'method' => 'POST', 'path' => '/settings/api-keys/revoke', 'status_code' => 200]);
        ActivityLog::create(['user_id' => $user->id, 'method' => 'GET', 'path' => '/dashboard', 'status_code' => 200]);

        $items = ActivityFeed::latest($user->id);

        $this->assertCount(1, $items, 'Unknown legacy paths are not shown');
        $this->assertSame('Revoked an API key', $items[0]['text']);
        $this->assertNull($items[0]['outcome']);
    }

    public function test_uploads_to_one_system_are_grouped(): void
    {
        $user = $this->makeUser('me@example.test');
        DB::table('system_register')->insert([
            'id' => 777001, 'pat_token_id' => 1, 'user_id' => $user->id, 'org_id' => 200, 'workspace_id' => 0,
            'system_name' => 'web-01', 'os_type' => 'linux', 'ip_address' => '10.0.0.5', 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (['nginx.conf', 'sshd_config', 'hosts'] as $name) {
            ConfigurationFile::create([
                'user_id' => $user->id,
                'system_register_id' => 777001,
                'file_name' => $name,
                'service_name' => 'svc',
                'file_location' => 'config_files/' . $name,
            ]);
        }

        $items = ActivityFeed::latest($user->id);

        $this->assertCount(1, $items);
        $this->assertSame('Backed up 3 configuration files on web-01', $items[0]['text']);
    }

    public function test_activity_page_filters_by_type(): void
    {
        $user = $this->makeUser('me@example.test');
        ActivityLog::create(['user_id' => $user->id, 'event' => 'auth.login', 'description' => 'Signed in from Firefox on Linux', 'outcome' => 'success', 'method' => 'POST', 'path' => '/login']);
        ActivityLog::create(['user_id' => $user->id, 'event' => 'apikey.revoked', 'description' => 'Revoked API key "old"', 'outcome' => 'success', 'method' => 'POST', 'path' => '/settings/api-keys/revoke']);

        $this->actingAs($user);

        $this->get(route('profile.activity', ['type' => 'apikeys']))
            ->assertOk()
            ->assertSee('Revoked API key &quot;old&quot;', false)
            ->assertDontSee('Signed in from Firefox on Linux');

        $this->get(route('profile.activity', ['type' => 'bogus']))
            ->assertOk()
            ->assertSee('Signed in from Firefox on Linux');
    }

    public function test_admin_change_is_recorded_on_the_target_and_visible_to_admin(): void
    {
        $admin = $this->actingAsRole(100);
        $target = $this->makeUser('target@example.test');

        $this->put(route('admin.users.update', ['user' => $target->id]), [
            'username' => 'target',
            'email' => 'target@example.test',
            'status' => 'active',
            'role' => 'admin',
        ])->assertSessionHasNoErrors();

        $event = ActivityLog::query()->where('user_id', $target->id)->where('event', 'account.changed_by_admin')->sole();
        $this->assertSame($admin->name . ' changed your role to Admin', $event->description);

        $this->get(route('admin.users.profile', ['user' => $target->id]))
            ->assertOk()
            ->assertSee('changed your role to Admin');
    }

    public function test_prune_removes_old_entries(): void
    {
        $user = $this->makeUser('me@example.test');
        $old = ActivityLog::create(['user_id' => $user->id, 'event' => 'auth.login', 'description' => 'old', 'method' => 'POST', 'path' => '/login']);
        $old->forceFill(['created_at' => now()->subDays(200)])->save();
        ActivityLog::create(['user_id' => $user->id, 'event' => 'auth.login', 'description' => 'new', 'method' => 'POST', 'path' => '/login']);

        $this->artisan('activity:prune', ['--days' => 180])->assertExitCode(0);

        $this->assertSame(['new'], ActivityLog::query()->pluck('description')->all());
    }

    public function test_user_agent_descriptions(): void
    {
        $this->assertSame('Chrome on Windows', UserAgent::describe(self::CHROME_WINDOWS));
        $this->assertSame('Edge on Windows', UserAgent::describe('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/129.0 Safari/537.36 Edg/129.0'));
        $this->assertSame('Safari on macOS', UserAgent::describe('Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Safari/605.1.15'));
        $this->assertSame('Firefox on Linux', UserAgent::describe('Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0'));
        $this->assertSame('Safari on iOS', UserAgent::describe('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'));
        $this->assertSame('atglance CLI', UserAgent::describe('python-requests/2.31.0'));
        $this->assertSame('Unknown device', UserAgent::describe(''));
    }
}
