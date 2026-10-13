<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Notifications\NotificationEvents;
use App\Services\CredentialSweep;
use App\Services\Notifier;
use App\Services\ServiceAccounts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountNotificationsTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    /** @var list<array{event: string, workspace: ?int, title: string, facts: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();

        // Records what would be sent; delivery itself is covered by NotificationsTest.
        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('notify')->andReturnUsing(function (string $event, ?int $workspaceId, string $title, array $facts = []) {
            $this->sent[] = ['event' => $event, 'workspace' => $workspaceId, 'title' => $title, 'facts' => $facts];
        });
        $this->app->instance(Notifier::class, $notifier);
    }

    private function events(): array
    {
        return array_column($this->sent, 'event');
    }

    public function test_events_have_scopes(): void
    {
        $this->assertSame('workspace', NotificationEvents::scope(NotificationEvents::SERVICE_ACCOUNT_KEY_EXPIRING));
        $this->assertSame('organization', NotificationEvents::scope(NotificationEvents::ORG_SERVICE_ACCOUNT_KEY_EXPIRING));
        $this->assertSame('organization', NotificationEvents::scope(NotificationEvents::ORG_USER_KEYS_REVOKED));
        $this->assertSame('workspace', NotificationEvents::scope(NotificationEvents::SYSTEM_REACTIVATED));
    }

    public function test_create_routes_by_scope(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $accounts = app(ServiceAccounts::class);

        $accounts->create($admin, ['name' => 'ws', 'scope' => 'workspace', 'workspace_id' => $this->workspace->id]);
        $accounts->create($admin, ['name' => 'org', 'scope' => 'organization']);

        $created = collect($this->sent)->firstWhere('event', NotificationEvents::SERVICE_ACCOUNT_CREATED);
        $this->assertSame($this->workspace->id, $created['workspace']);
        $this->assertContains(NotificationEvents::ORG_SERVICE_ACCOUNT_CREATED, $this->events());
    }

    public function test_org_account_key_revoked_goes_to_org_and_each_granted_workspace(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($account, $this->workspace);
        $this->grant($account, $this->otherWorkspace);
        [$plain, $key] = $this->accountKey($account);

        app(ServiceAccounts::class)->revokeKey($key, $admin, 'leaked');

        $this->assertContains(NotificationEvents::ORG_SERVICE_ACCOUNT_KEY_REVOKED, $this->events());
        $workspaces = array_column(array_filter($this->sent, fn ($s) => $s['event'] === NotificationEvents::SERVICE_ACCOUNT_KEY_REVOKED), 'workspace');
        $this->assertEqualsCanonicalizing([$this->workspace->id, $this->otherWorkspace->id], $workspaces);

        foreach ($this->sent as $notice) {
            $this->assertStringNotContainsString($plain, json_encode($notice));
        }
    }

    public function test_sweep_sends_each_notice_once(): void
    {
        Queue::fake();
        $owner = $this->makeUser('owner@example.test', 101);
        $account = $this->makeAccount(attributes: ['owner_user_id' => $owner->id]);
        [, $soon] = $this->accountKey($account, ['expires_at' => now()->addDays(10)]);
        [, $late] = $this->accountKey($account, ['expires_at' => now()->addDays(2)]);
        $user = $this->makeUser('u@example.test');
        [, $userKey] = $this->userKey($user);
        $userKey->update(['expires_at' => now()->addDays(13)]);
        $expiredUser = $this->makeUser('e@example.test');
        [, $expiredKey] = $this->userKey($expiredUser);
        $expiredKey->update(['expires_at' => now()->subHour()]);

        $first = app(CredentialSweep::class)->run();
        $this->assertSame(['expiring' => 3, 'expired' => 1], $first);
        $this->assertSame(14, (int) $soon->fresh()->expiry_notified_stage);
        $this->assertSame(3, (int) $late->fresh()->expiry_notified_stage);
        $this->assertNotNull($expiredKey->fresh()->expired_notified_at);

        $this->assertSame(['expiring' => 0, 'expired' => 0], app(CredentialSweep::class)->run());

        // 8 days on: $soon has 2 days left (enters the 3-day window), $late has expired,
        // $userKey has 5 days left (still in the 14-day window it was already told about).
        $this->travel(8)->days();
        $this->assertSame(['expiring' => 1, 'expired' => 1], app(CredentialSweep::class)->run());
    }

    public function test_user_keys_revoked_notice(): void
    {
        $user = $this->makeUser('leaver@example.test');
        $this->userKey($user);
        $user->status = 'inactive';
        $user->save();

        $notice = collect($this->sent)->firstWhere('event', NotificationEvents::ORG_USER_KEYS_REVOKED);
        $this->assertNotNull($notice);
        $this->assertSame('1', $notice['facts']['Keys revoked']);
    }

    public function test_owner_missing_notice(): void
    {
        $owner = $this->makeUser('owner@example.test', 101);
        $this->makeAccount(attributes: ['owner_user_id' => $owner->id]);

        $owner->status = 'inactive';
        $owner->save();

        $this->assertContains(NotificationEvents::ORG_SERVICE_ACCOUNT_OWNER_MISSING, $this->events());
    }

    public function test_reactivation_uses_its_own_event_with_actor_facts(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $system = $this->makeSystem($owner, ['status' => 'inactive']);
        $this->actingAs($this->makeUser('admin@example.test', 101));

        $system->status = 'active';
        $system->save();

        $notice = collect($this->sent)->firstWhere('event', NotificationEvents::SYSTEM_REACTIVATED);
        $this->assertSame('admin@example.test', $notice['facts']['Actor']);
        $this->assertSame('owner@example.test', $notice['facts']['Original owner']);
    }
}
