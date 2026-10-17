<?php

namespace Tests\Feature\Api\V1;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityEventApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;
    private User $admin;
    private User $superAdmin;
    private User $nonAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::create(['id' => 1, 'name' => 'Test Org']);
        Organization::create(['id' => 100, 'name' => 'Super Org']);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
            'password_hash' => bcrypt('password'),
            'pin' => bcrypt('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => 101,
            'org_id' => $this->org->id,
            'status' => 'active',
        ]);

        $this->superAdmin = User::create([
            'name' => 'Super Admin',
            'email' => 'super@example.test',
            'password_hash' => bcrypt('password'),
            'pin' => bcrypt('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => 100,
            'org_id' => 100,
            'status' => 'active',
        ]);

        $this->nonAdmin = User::create([
            'name' => 'User',
            'email' => 'user@example.test',
            'password_hash' => bcrypt('password'),
            'pin' => bcrypt('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => 102,
            'org_id' => $this->org->id,
            'status' => 'active',
        ]);
    }

    private function pat(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'test',
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
        ]);

        return ['Authorization' => 'Bearer ' . $plain];
    }

    public function test_security_events_requires_admin_pat(): void
    {
        // Non-admin gets 403
        $this->getJson('/api/v1/security/events', $this->pat($this->nonAdmin))
            ->assertForbidden();

        // No auth gets 401
        $this->getJson('/api/v1/security/events')
            ->assertUnauthorized();
    }

    public function test_security_events_scoped_to_organization(): void
    {
        $otherOrg = Organization::create(['id' => 2, 'name' => 'Other Org']);
        $otherUser = User::create([
            'name' => 'Other Admin',
            'email' => 'other@example.test',
            'password_hash' => bcrypt('password'),
            'pin' => bcrypt('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => 101,
            'org_id' => $otherOrg->id,
            'status' => 'active',
        ]);

        // Create events in both orgs
        SecurityEvent::create([
            'org_id' => $this->org->id,
            'type' => 'force.failed',
            'user_id' => $this->admin->id,
            'ip' => '127.0.0.1',
        ]);

        SecurityEvent::create([
            'org_id' => $otherOrg->id,
            'type' => 'force.failed',
            'user_id' => $otherUser->id,
            'ip' => '127.0.0.1',
        ]);

        // Admin sees only their org's events
        $response = $this->getJson('/api/v1/security/events', $this->pat($this->admin))
            ->assertOk();

        $this->assertCount(1, $response->json('events'));
        $this->assertSame($this->admin->id, $response->json('events.0.user.id'));
    }

    public function test_super_admin_sees_all_events(): void
    {
        $otherOrg = Organization::create(['id' => 3, 'name' => 'Other Org']);
        $otherUser = User::create([
            'name' => 'Other Admin',
            'email' => 'other@example.test',
            'password_hash' => bcrypt('password'),
            'pin' => bcrypt('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => 101,
            'org_id' => $otherOrg->id,
            'status' => 'active',
        ]);

        SecurityEvent::create([
            'org_id' => $this->org->id,
            'type' => 'force.failed',
            'user_id' => $this->admin->id,
            'ip' => '127.0.0.1',
        ]);

        SecurityEvent::create([
            'org_id' => $otherOrg->id,
            'type' => 'force.failed',
            'user_id' => $otherUser->id,
            'ip' => '127.0.0.1',
        ]);

        // Super admin sees all events
        $response = $this->getJson('/api/v1/security/events', $this->pat($this->superAdmin))
            ->assertOk();

        $this->assertCount(2, $response->json('events'));
    }

    public function test_security_events_can_be_filtered_by_type(): void
    {
        SecurityEvent::create([
            'org_id' => $this->org->id,
            'type' => 'force.failed',
            'user_id' => $this->admin->id,
            'ip' => '127.0.0.1',
        ]);

        SecurityEvent::create([
            'org_id' => $this->org->id,
            'type' => 'force.locked',
            'user_id' => $this->admin->id,
            'ip' => '127.0.0.1',
        ]);

        $response = $this->getJson('/api/v1/security/events?type=force.failed', $this->pat($this->admin))
            ->assertOk();

        $this->assertCount(1, $response->json('events'));
        $this->assertSame('force.failed', $response->json('events.0.type'));
    }

    public function test_security_events_return_user_info(): void
    {
        SecurityEvent::create([
            'org_id' => $this->org->id,
            'type' => 'force.failed',
            'user_id' => $this->admin->id,
            'ip' => '127.0.0.1',
        ]);

        $response = $this->getJson('/api/v1/security/events', $this->pat($this->admin))
            ->assertOk();

        $event = $response->json('events.0');
        $this->assertNotNull($event['user']);
        $this->assertSame($this->admin->id, $event['user']['id']);
        $this->assertSame('Admin', $event['user']['name']);
    }

    public function test_security_events_pagination(): void
    {
        // Create 60 events
        for ($i = 0; $i < 60; $i++) {
            SecurityEvent::create([
                'org_id' => $this->org->id,
                'type' => 'force.failed',
                'user_id' => $this->admin->id,
                'ip' => '127.0.0.1',
            ]);
        }

        // First page
        $response1 = $this->getJson('/api/v1/security/events?page=1', $this->pat($this->admin))
            ->assertOk();

        $this->assertCount(50, $response1->json('events'));
        $this->assertTrue((bool) $response1->json('next'));

        // Second page
        $response2 = $this->getJson('/api/v1/security/events?page=2', $this->pat($this->admin))
            ->assertOk();

        $this->assertCount(10, $response2->json('events'));
        $this->assertFalse((bool) $response2->json('next'));
    }

    public function test_locked_accounts_requires_admin_pat(): void
    {
        // Non-admin gets 403
        $this->getJson('/api/v1/security/lockouts', $this->pat($this->nonAdmin))
            ->assertForbidden();

        // No auth gets 401
        $this->getJson('/api/v1/security/lockouts')
            ->assertUnauthorized();
    }

    public function test_locked_accounts_returns_locked_users(): void
    {
        $this->admin->update([
            'force_locked_until' => now()->addHours(1),
            'force_lock_level' => 1,
        ]);

        $response = $this->getJson('/api/v1/security/lockouts', $this->pat($this->admin))
            ->assertOk();

        $locked = $response->json('locked_accounts');
        $this->assertCount(1, $locked);
        $this->assertSame($this->admin->id, $locked[0]['id']);
        $this->assertSame(1, $locked[0]['lock_level']);
    }
}
