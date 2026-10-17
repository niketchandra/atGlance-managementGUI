<?php

namespace Tests\Feature;

use App\Models\ServiceAccount;
use App\Notifications\NotificationEvents;
use App\Services\CredentialSweep;
use App\Services\Notifier;
use App\Support\ServiceAccountSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

/**
 * Spec section 16: notices 15, 7, 3, 2, 1 and 0 days before expiry at user, workspace
 * and organization level, and the CLI's credential status and expiry-notice API.
 */
class CredentialExpiryTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    /** @var list<array{event: string, workspace: ?int, facts: array}> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Queue::fake();

        $notifier = Mockery::mock(Notifier::class);
        $notifier->shouldReceive('notify')->andReturnUsing(function (string $event, ?int $workspaceId, string $title, array $facts = []) {
            $this->sent[] = ['event' => $event, 'workspace' => $workspaceId, 'facts' => $facts];
        });
        $this->app->instance(Notifier::class, $notifier);
    }

    private function sentFor(string $event): array
    {
        return array_values(array_filter($this->sent, fn ($s) => $s['event'] === $event));
    }

    public function test_default_schedule(): void
    {
        $this->assertSame([15, 7, 3, 2, 1, 0], ServiceAccountSettings::noticeDays());
    }

    public function test_person_key_notices_go_to_org_and_workspaces_of_its_systems(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);
        $key->update(['expires_at' => now()->addDays(6)->setTime(12, 0)]);
        $this->makeSystem($user, ['pat_token_id' => $key->id]);
        $this->makeSystem($user, ['pat_token_id' => $key->id, 'workspace_id' => $this->otherWorkspace->id, 'system_name' => 'web-02']);

        $this->assertSame(1, app(CredentialSweep::class)->run()['expiring']);

        $this->assertSame(7, (int) $key->fresh()->expiry_notified_stage);
        $this->assertCount(1, $this->sentFor(NotificationEvents::ORG_USER_KEY_EXPIRING));
        $this->assertEqualsCanonicalizing(
            [$this->workspace->id, $this->otherWorkspace->id],
            array_column($this->sentFor(NotificationEvents::USER_KEY_EXPIRING), 'workspace'),
        );
    }

    public function test_each_day_of_the_schedule_fires_once_including_expiry_day(): void
    {
        $account = $this->makeAccount();
        [, $key] = $this->accountKey($account, ['expires_at' => now()->addDays(15)->setTime(18, 0)]);

        $stages = [];
        foreach ([0, 8, 12, 13, 14, 15] as $day) {
            $this->travelTo(now()->startOfDay()->addDays($day)->setTime(1, 0));
            app(CredentialSweep::class)->run();
            app(CredentialSweep::class)->run();
            $stages[] = (int) $key->fresh()->expiry_notified_stage;
            $this->travelBack();
        }

        $this->assertSame([15, 7, 3, 2, 1, 0], $stages);
        $this->assertCount(6, $this->sentFor(NotificationEvents::SERVICE_ACCOUNT_KEY_EXPIRING));
    }

    public function test_status_endpoint_for_service_account_with_system(): void
    {
        $registrar = $this->makeUser('r@example.test');
        $account = $this->makeAccount();
        [$plain, $key] = $this->accountKey($account, ['name' => 'fleet-a', 'expires_at' => now()->addDays(7)->setTime(12, 0)]);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);

        $this->getJson('/api/v1/credentials/status', ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system))
            ->assertOk()
            ->assertJsonPath('identity_type', 'service_account')
            ->assertJsonPath('key.id', $key->id)
            ->assertJsonPath('key.name', 'fleet-a')
            ->assertJsonPath('key.last_four', $key->last_four)
            ->assertJsonPath('key.days_left', 7)
            ->assertJsonPath('key.state', 'expiring')
            ->assertJsonPath('notice_days', [15, 7, 3, 2, 1, 0])
            ->assertJsonPath('system.id', $system->id)
            ->assertJsonPath('system.workspace_id', $this->workspace->id);
    }

    public function test_status_endpoint_for_person_key_far_from_expiry(): void
    {
        $user = $this->makeUser('u@example.test');
        [$plain, $key] = $this->userKey($user);
        $key->update(['expires_at' => now()->addDays(60)]);

        $this->getJson('/api/v1/credentials/status', ['Authorization' => 'Bearer ' . $plain])
            ->assertOk()
            ->assertJsonPath('identity_type', 'user')
            ->assertJsonPath('key.state', 'active')
            ->assertJsonPath('system', null);
    }

    public function test_status_with_bad_system_headers_is_404(): void
    {
        $account = $this->makeAccount();
        [$plain] = $this->accountKey($account);
        $other = $this->makeSystem($this->makeUser('r@example.test'));

        $this->getJson('/api/v1/credentials/status', ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($other))
            ->assertNotFound();
    }

    public function test_expiry_notice_endpoint_sends_once_per_stage(): void
    {
        $user = $this->makeUser('u@example.test');
        $this->workspace->addUser($user->id);
        [$plain, $key] = $this->userKey($user);
        $key->update(['expires_at' => now()->addDays(2)->setTime(12, 0)]);
        $system = $this->makeSystem($user, ['pat_token_id' => $key->id]);
        $headers = ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system);

        $this->postJson('/api/v1/credentials/expiry-notice', [], $headers)
            ->assertOk()->assertJsonPath('sent', true)->assertJsonPath('stage', 2);
        $this->postJson('/api/v1/credentials/expiry-notice', [], $headers)
            ->assertOk()->assertJsonPath('sent', false)->assertJsonPath('reason', 'already_sent');

        $workspaceNotice = $this->sentFor(NotificationEvents::USER_KEY_EXPIRING)[0];
        $this->assertSame($this->workspace->id, $workspaceNotice['workspace']);
        $this->assertSame('web-01', $workspaceNotice['facts']['System']);
        $this->assertCount(1, $this->sentFor(NotificationEvents::ORG_USER_KEY_EXPIRING));
    }

    public function test_expiry_notice_endpoint_when_not_expiring(): void
    {
        $account = $this->makeAccount();
        [$plain] = $this->accountKey($account, ['expires_at' => now()->addDays(60)]);

        $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => 'Bearer ' . $plain])
            ->assertOk()->assertJsonPath('sent', false)->assertJsonPath('reason', 'not_expiring');
        $this->assertSame([], $this->sent);
    }

    public function test_notices_never_contain_the_key(): void
    {
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($account, $this->workspace);
        [$plain] = $this->accountKey($account, ['expires_at' => now()->addDay()->setTime(12, 0)]);

        $this->postJson('/api/v1/credentials/expiry-notice', [], ['Authorization' => 'Bearer ' . $plain])->assertOk()->assertJsonPath('sent', true);

        $this->assertNotEmpty($this->sent);
        $this->assertStringNotContainsString($plain, json_encode($this->sent));
    }
}
