<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\InteractsWithAdminConsole;
use Tests\TestCase;

class SecurityPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use InteractsWithAdminConsole;

    private const FIREFOX_LINUX = 'Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0';
    private const SAFARI_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAdminConsole();
        config(['session.driver' => 'database', 'session.table' => 'web_sessions']);
    }

    protected function tearDown(): void
    {
        $this->tearDownAdminConsole();
        parent::tearDown();
    }

    private function makeUser(string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name' => strstr($email, '@', true),
            'email' => $email,
            'password' => 'secret-pass',
            'rbac_id' => 102,
            'org_id' => 200,
            'status' => 'active',
            'dob' => '1990-01-01',
            'pin' => Hash::make('12345'),
            'remember_token' => 'original-remember-token',
        ])->save();

        return $user;
    }

    private function sessionRow(string $id, ?User $user, string $agent, int $minutesAgo = 1): void
    {
        DB::table('web_sessions')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'ip_address' => '198.51.100.' . strlen($id),
            'user_agent' => $agent,
            'payload' => base64_encode(serialize([])),
            'last_activity' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    public function test_setting_a_password_stamps_password_changed_at(): void
    {
        $this->travelTo('2026-09-01 10:00:00');
        $user = $this->makeUser('me@example.test');
        $this->assertSame('2026-09-01 10:00:00', $user->password_changed_at->format('Y-m-d H:i:s'));

        $this->travelTo('2026-09-20 12:00:00');
        $this->actingAs($user)->post(route('password.update'), [
            'current_password' => 'secret-pass',
            'password' => 'another-pass',
            'password_confirmation' => 'another-pass',
        ])->assertSessionHasNoErrors();

        $this->assertSame('2026-09-20 12:00:00', $user->fresh()->password_changed_at->format('Y-m-d H:i:s'));

        // Saving other fields leaves it alone.
        $this->travelTo('2026-09-25 12:00:00');
        $user->fresh()->forceFill(['phone' => '123'])->save();
        $this->assertSame('2026-09-20 12:00:00', $user->fresh()->password_changed_at->format('Y-m-d H:i:s'));
    }

    public function test_login_keeps_last_and_previous_sign_in(): void
    {
        $user = $this->makeUser('me@example.test');

        $this->travelTo('2026-09-10 08:00:00');
        $this->post('/login', ['email' => 'me@example.test', 'password' => 'secret-pass'], ['REMOTE_ADDR' => '203.0.113.1']);
        $this->post('/logout');

        $this->travelTo('2026-09-12 09:30:00');
        $this->post('/login', ['email' => 'me@example.test', 'password' => 'secret-pass'], ['REMOTE_ADDR' => '203.0.113.2']);

        $user->refresh();
        $this->assertSame('2026-09-12 09:30:00', $user->last_login_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-10 08:00:00', $user->previous_login_at->format('Y-m-d H:i:s'));
        $this->assertNotNull($user->last_login_ip);
    }

    public function test_profile_security_section_shows_real_values(): void
    {
        $this->travelTo('2026-06-01 10:00:00');
        $user = $this->makeUser('me@example.test');
        $user->forceFill(['last_login_at' => '2026-06-02 09:00:00', 'last_login_ip' => '203.0.113.9'])->saveQuietly();
        $this->travelTo('2026-09-27 10:00:00');

        $response = $this->actingAs($user)->get(route('profile'))->assertOk();

        $response->assertSee('Last changed 3 months ago')
            ->assertSee('from 203.0.113.9')
            ->assertSee('0 active keys')
            ->assertSee('Coming soon')
            ->assertDontSee('March 25, 2026')
            ->assertDontSee('2 active keys')
            ->assertDontSee('Enable 2FA');
    }

    public function test_settings_lists_only_my_active_sessions(): void
    {
        $me = $this->makeUser('me@example.test');
        $other = $this->makeUser('other@example.test');
        $this->sessionRow('my-phone-session', $me, self::SAFARI_IPHONE);
        $this->sessionRow('my-old-session', $me, self::FIREFOX_LINUX, 60 * 24);
        $this->sessionRow('their-session', $other, self::FIREFOX_LINUX);

        $this->actingAs($me)->get(route('settings'))
            ->assertOk()
            ->assertSee('Safari on iOS')
            ->assertDontSee('their-session')
            ->assertDontSee('Firefox on Linux', false);
    }

    public function test_ending_one_session_deletes_it_and_rotates_remember_token(): void
    {
        $me = $this->makeUser('me@example.test');
        $other = $this->makeUser('other@example.test');
        $this->sessionRow('my-phone-session', $me, self::SAFARI_IPHONE);
        $this->sessionRow('their-session', $other, self::FIREFOX_LINUX);

        $this->actingAs($me)->delete(route('settings.sessions.end', ['session' => 'their-session']))
            ->assertSessionHasErrors('session');
        $this->assertTrue(DB::table('web_sessions')->where('id', 'their-session')->exists());

        $this->actingAs($me)->delete(route('settings.sessions.end', ['session' => 'my-phone-session']))
            ->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('web_sessions')->where('id', 'my-phone-session')->exists());
        $this->assertNotSame('original-remember-token', $me->fresh()->remember_token);
        $this->assertSame('original-remember-token', $other->fresh()->remember_token);
    }

    public function test_sign_out_others_needs_password_or_pin(): void
    {
        $me = $this->makeUser('me@example.test');
        $this->sessionRow('a-session', $me, self::SAFARI_IPHONE);
        $this->sessionRow('b-session', $me, self::FIREFOX_LINUX);

        $this->actingAs($me)->post(route('settings.sessions.others'), ['password' => 'wrong'])
            ->assertSessionHasErrors('session');
        $this->assertSame(2, DB::table('web_sessions')->whereIn('id', ['a-session', 'b-session'])->count());

        $this->actingAs($me)->post(route('settings.sessions.others'), ['pin' => '12345'])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, DB::table('web_sessions')->where('user_id', $me->id)->whereIn('id', ['a-session', 'b-session'])->count());
    }

    public function test_password_change_signs_out_other_sessions(): void
    {
        $me = $this->makeUser('me@example.test');
        $this->sessionRow('laptop-session', $me, self::FIREFOX_LINUX);

        $this->actingAs($me)->post(route('password.update'), [
            'current_password' => 'secret-pass',
            'password' => 'another-pass',
            'password_confirmation' => 'another-pass',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(DB::table('web_sessions')->where('id', 'laptop-session')->exists());
        $this->assertNotSame('original-remember-token', $me->fresh()->remember_token);
    }
}
