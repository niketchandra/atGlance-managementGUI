<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Legacy 1.x routes: deprecation headers on every response, usage recorded once per
 * identifier per day after the response, nothing queued.
 */
class LegacyApiSunsetTest extends TestCase
{
    use RefreshDatabase;

    private function pat(string $email = 'old@example.test'): array
    {
        Organization::query()->updateOrCreate(['id' => 300], ['name' => 'Legacy Org']);
        $user = new User();
        $user->forceFill([
            'name' => $email, 'email' => $email,
            'password_hash' => Hash::make('secret-pass'), 'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'), 'dob' => '1990-01-01',
            'rbac_id' => 102, 'org_id' => 300, 'status' => 'active',
        ])->save();
        $plain = PatToken::generateCustomToken();
        PatToken::create([
            'tokenable_type' => User::class, 'tokenable_id' => $user->id, 'name' => 'cli',
            'token' => hash('sha256', $plain), 'abilities' => ['*'], 'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'];
    }

    private function assertSunsetHeaders($response): void
    {
        $response->assertHeader('Deprecation', 'true');
        $response->assertHeader('Sunset', 'Thu, 31 Dec 2026 23:59:59 GMT');
        $this->assertStringContainsString('rel="successor-version"', $response->headers->get('Link'));
    }

    public function test_successful_call_has_headers_queues_nothing_and_records_once_per_day(): void
    {
        Queue::fake();
        Cache::flush();
        $headers = $this->pat();

        $first = $this->getJson('/api/system-register?foo=bar', $headers)->assertOk();
        $this->assertSunsetHeaders($first);
        $this->assertSunsetHeaders($this->getJson('/api/system-register', $headers)->assertOk());

        Queue::assertNothingPushed();
        $events = SecurityEvent::where('type', 'legacy_api.used')->get();
        $this->assertCount(1, $events);
        $event = $events->first();
        $this->assertSame(300, (int) $event->org_id);
        $this->assertSame('/api/system-register', $event->details['route']);
        $this->assertSame('GET', $event->details['method']);
        $this->assertEqualsCanonicalizing(['route', 'method', 'user_agent'], array_keys($event->details));
        $this->assertStringNotContainsString('foo', json_encode($event->toArray()));
        $this->assertStringNotContainsString(substr($headers['Authorization'], 7), json_encode($event->toArray()));
    }

    public function test_a_different_user_is_recorded_separately(): void
    {
        Cache::flush();
        $this->getJson('/api/system-register', $this->pat('a@example.test'))->assertOk();
        $this->getJson('/api/system-register', $this->pat('b@example.test'))->assertOk();

        $this->assertSame(2, SecurityEvent::where('type', 'legacy_api.used')->count());
    }

    public function test_error_response_keeps_headers_and_records_nothing(): void
    {
        Cache::flush();
        $response = $this->getJson('/api/system-register', ['Authorization' => 'Bearer nope', 'Accept' => 'application/json']);
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
        $this->assertSame(0, SecurityEvent::where('type', 'legacy_api.used')->count());
    }

    public function test_recording_failure_never_changes_the_response(): void
    {
        Cache::flush();
        $headers = $this->pat();
        \Illuminate\Support\Facades\Schema::drop('security_events');

        $this->assertSunsetHeaders($this->getJson('/api/system-register', $headers)->assertOk());
    }
}
