<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ConfigurationFile;
use App\Models\SecurityEvent;
use App\Models\Service;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class ServiceAccountSystemRoutesTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
        Storage::fake('local');
    }

    private function upload(array $headers, string $service = 'sshd')
    {
        return $this->post('/api/v1/config-files', [
            'service_name' => $service,
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "PermitRootLogin no\n"),
        ], $headers + ['Accept' => 'application/json']);
    }

    /**
     * @return array{0: SystemRegister, 1: array<string, string>, 2: ServiceAccount}
     */
    private function accountSystem(?array $permissions = null): array
    {
        $registrar = User::where('email', 'registrar@example.test')->first() ?? $this->makeUser('registrar@example.test');
        $account = $this->makeAccount();
        if ($permissions !== null) {
            $this->grant($account, $this->workspace, $permissions);
        }
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);

        return [$system, ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system), $account];
    }

    public function test_upload_list_and_show_as_service_account(): void
    {
        [, $headers, $account] = $this->accountSystem();

        $id = $this->upload($headers)->assertCreated()->json('file.id');
        $file = ConfigurationFile::findOrFail($id);
        $this->assertNull($file->user_id);
        $this->assertSame($account->id, (int) $file->service_account_id);

        $this->getJson('/api/v1/config-files', $headers)->assertOk()
            ->assertJsonPath('files.0.id', $id)
            ->assertJsonPath('files.0.created_by.service_account.id', $account->id);
        $this->get('/api/v1/config-files/' . $id, $headers)->assertOk();
        $this->getJson('/api/v1/systems/self', $headers)->assertOk();
    }

    public function test_upload_continues_version_history_of_person_owned_service(): void
    {
        [$system, $headers] = $this->accountSystem();
        $registrar = User::where('email', 'registrar@example.test')->first();
        Service::create(['service_name' => 'sshd', 'system_id' => $system->id, 'user_id' => $registrar->id, 'status' => 'active']);

        $this->upload($headers)->assertCreated();

        $this->assertSame(1, Service::where('system_id', $system->id)->where('service_name', 'sshd')->count());
    }

    public function test_each_permission_is_enforced(): void
    {
        [, $headers] = $this->accountSystem(['configs.read']);
        $this->upload($headers)->assertStatus(403)->assertJsonPath('message', 'Service account lacks configs.write in this workspace');
        $this->getJson('/api/v1/config-files', $headers)->assertOk();

        [, $writeOnly] = $this->accountSystem(['configs.write']);
        $this->getJson('/api/v1/config-files', $writeOnly)->assertStatus(403);

        $this->postJson('/api/v1/systems/self/rotate-key', ['new_key' => str_repeat('b', 40)], $writeOnly)->assertStatus(403);
    }

    public function test_rotate_key_with_permission_needs_no_pin(): void
    {
        [$system, $headers] = $this->accountSystem(['systems.rotate_key', 'configs.read']);

        $this->postJson('/api/v1/systems/self/rotate-key', ['new_key' => str_repeat('b', 40)], $headers)
            ->assertOk()->assertJsonPath('rotated', true);
        $this->assertTrue($system->fresh()->matchesKey(str_repeat('b', 40)));
        $this->assertTrue(ActivityLog::where('event', 'system.key_rotated')->whereNull('user_id')->where('description', 'like', '%service account%')->exists());
    }

    public function test_deregister_and_reactivate_refuse_service_accounts(): void
    {
        [, $headers] = $this->accountSystem();

        foreach (['deregister', 'reactivate'] as $action) {
            $this->postJson('/api/v1/systems/self/' . $action, ['pin' => '1234'], $headers)
                ->assertStatus(403)
                ->assertJsonPath('message', 'Deregister and reactivate need a user key with password or PIN');
        }
    }

    public function test_account_cannot_use_system_of_other_account(): void
    {
        [$system] = $this->accountSystem();
        $other = $this->makeAccount();
        [$otherKey] = $this->accountKey($other);

        $this->getJson('/api/v1/systems/self', ['Authorization' => 'Bearer ' . $otherKey] + $this->systemHeaders($system))->assertNotFound();
    }

    public function test_revoked_grant_blocks_next_upload_with_404(): void
    {
        $registrar = $this->makeUser('registrar@example.test');
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($account, $this->workspace);
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($registrar, ['service_account_id' => $account->id]);
        $headers = ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system);

        $this->upload($headers)->assertCreated();
        $account->grants()->update(['status' => 'revoked']);
        $this->upload($headers)->assertNotFound();
    }

    public function test_workspace_admin_may_deregister_after_owner_left(): void
    {
        $owner = $this->makeUser('owner@example.test');
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($owner->id);
        $this->workspace->addUser($wsAdmin->id, true);
        $system = $this->makeSystem($owner);
        $owner->update(['status' => 'inactive']);
        [$plain] = $this->userKey($wsAdmin);

        $this->postJson('/api/v1/systems/self/deregister', ['pin' => '1234'], ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system))
            ->assertOk();

        $log = ActivityLog::where('event', 'system.deregistered')->latest('id')->first();
        $this->assertSame($wsAdmin->id, (int) $log->user_id);
        $this->assertStringContainsString('owner owner@example.test', $log->description);
    }

    /**
     * A system owned by a person, with no service account yet.
     *
     * @return array{0: SystemRegister, 1: User, 2: array<string, string>} system, owner, owner's PAT + system headers
     */
    private function personSystem(): array
    {
        $owner = $this->makeUser('owner@example.test');
        [$plain] = $this->userKey($owner);
        $system = $this->makeSystem($owner);

        return [$system, $owner, ['Authorization' => 'Bearer ' . $plain] + $this->systemHeaders($system)];
    }

    private function setAccount(array $headers, string $accountKey)
    {
        return $this->postJson('/api/v1/systems/self/service-account', [], $headers + ['X-Atglance-Service-Account-Key' => $accountKey]);
    }

    public function test_attach_service_account_to_existing_system(): void
    {
        [$system, $owner, $headers] = $this->personSystem();
        $account = $this->makeAccount();
        [$saKey] = $this->accountKey($account);

        $this->setAccount($headers, $saKey)->assertOk()->assertExactJson(['system' => [
            'id' => $system->id,
            'system_name' => 'web-01',
            'service_account_id' => $account->id,
            'workspace_id' => $this->workspace->id,
        ]]);

        $fresh = $system->fresh();
        $this->assertSame($account->id, (int) $fresh->service_account_id);
        $this->assertTrue($fresh->matchesKey(self::SYSTEM_KEY));

        $log = ActivityLog::where('event', 'system.service_account_changed')->sole();
        $this->assertSame($owner->id, (int) $log->user_id);
        $this->assertSame($system->id, (int) $log->system_register_id);
        $this->assertStringContainsString('from none to ' . $account->id, $log->description);

        $event = SecurityEvent::where('type', 'system.service_account_changed')->sole();
        $this->assertSame(200, (int) $event->org_id);
        $this->assertSame($owner->id, (int) $event->user_id);
        $this->assertSame(['system_id' => $system->id, 'old_service_account_id' => null, 'new_service_account_id' => $account->id], $event->details);

        // The account's key now works for the system.
        $this->getJson('/api/v1/systems/self', ['Authorization' => 'Bearer ' . $saKey] + $this->systemHeaders($system))->assertOk();

        // Repeating the call is harmless (the CLI may retry) and records nothing new.
        $this->setAccount($headers, $saKey)->assertOk()->assertJsonPath('system.service_account_id', $account->id);
        $this->assertSame(1, ActivityLog::where('event', 'system.service_account_changed')->count());
    }

    public function test_replace_service_account_keeps_system_and_history(): void
    {
        [$system, $headers, $old] = $this->accountSystem();
        $registrar = User::where('email', 'registrar@example.test')->first();
        [$ownerKey] = $this->userKey($registrar);
        $ownerHeaders = ['Authorization' => 'Bearer ' . $ownerKey] + $this->systemHeaders($system);
        $fileId = $this->upload($headers)->assertCreated()->json('file.id');

        $new = $this->makeAccount();
        [$newKey] = $this->accountKey($new);
        $this->setAccount($ownerHeaders, $newKey)->assertOk()
            ->assertJsonPath('system.id', $system->id)
            ->assertJsonPath('system.service_account_id', $new->id);

        $this->getJson('/api/v1/systems/self', $headers)->assertNotFound();
        $this->getJson('/api/v1/config-files', $headers)->assertNotFound();

        $newHeaders = ['Authorization' => 'Bearer ' . $newKey] + $this->systemHeaders($system);
        $this->getJson('/api/v1/systems/self', $newHeaders)->assertOk()->assertJsonPath('system.id', $system->id);
        $this->getJson('/api/v1/config-files', $newHeaders)->assertOk()->assertJsonPath('files.0.id', $fileId);

        $this->assertSame(['system_id' => $system->id, 'old_service_account_id' => $old->id, 'new_service_account_id' => $new->id],
            SecurityEvent::where('type', 'system.service_account_changed')->sole()->details);
    }

    public function test_service_account_change_needs_a_manager(): void
    {
        [$system] = $this->personSystem();
        $member = $this->makeUser('member@example.test');
        $this->workspace->addUser($member->id);
        [$memberKey] = $this->userKey($member);
        [$saKey] = $this->accountKey($this->makeAccount());
        $headers = ['Authorization' => 'Bearer ' . $memberKey] + $this->systemHeaders($system);

        $this->setAccount($headers, $saKey)->assertStatus(403)
            ->assertJsonPath('message', 'Only the system owner, a workspace admin of its workspace or an admin of its organisation can change this system');
        $this->deleteJson('/api/v1/systems/self/service-account', [], $headers)->assertStatus(403);
        $this->assertNull($system->fresh()->service_account_id);

        // A workspace admin and an org admin may.
        $wsAdmin = $this->makeUser('wsadmin@example.test');
        $this->workspace->addUser($wsAdmin->id, true);
        [$wsAdminKey] = $this->userKey($wsAdmin);
        $this->setAccount(['Authorization' => 'Bearer ' . $wsAdminKey] + $this->systemHeaders($system), $saKey)->assertOk();

        $orgAdmin = $this->makeUser('admin@example.test', 101);
        [$orgAdminKey] = $this->userKey($orgAdmin);
        $this->deleteJson('/api/v1/systems/self/service-account', [], ['Authorization' => 'Bearer ' . $orgAdminKey] + $this->systemHeaders($system))->assertOk();
        $this->assertNull($system->fresh()->service_account_id);
    }

    public function test_service_account_without_register_grant_is_refused(): void
    {
        [$system, , $headers] = $this->personSystem();
        $account = $this->makeAccount(ServiceAccount::SCOPE_ORGANIZATION);
        $this->grant($account, $this->workspace, ['configs.read', 'configs.write']);
        [$saKey] = $this->accountKey($account);

        $this->setAccount($headers, $saKey)->assertStatus(403)
            ->assertJsonPath('message', 'Service account lacks systems.register in this workspace');

        // A grant on another workspace does not count either.
        $other = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->otherWorkspace);
        [$otherKey] = $this->accountKey($other);
        $this->setAccount($headers, $otherKey)->assertStatus(403);

        $this->assertNull($system->fresh()->service_account_id);
        $this->assertSame(0, ActivityLog::where('event', 'system.service_account_changed')->count());
    }

    public function test_invalid_or_foreign_service_account_key_is_401(): void
    {
        [$system, , $headers] = $this->personSystem();
        $foreign = $this->makeAccount(ServiceAccount::SCOPE_WORKSPACE, $this->foreignWorkspace);
        [$foreignKey] = $this->accountKey($foreign);
        [$revokedKey] = $this->accountKey($this->makeAccount(), ['status' => 'revoked', 'revoked_at' => now()]);

        foreach ([$foreignKey, $revokedKey, 'atgsa-' . str_repeat('x', 32), 'atgla-not-a-service-key'] as $key) {
            $this->setAccount($headers, $key)->assertStatus(401)->assertJsonPath('message', 'Invalid service account key');
        }
        $this->assertNull($system->fresh()->service_account_id);

        $this->postJson('/api/v1/systems/self/service-account', [], $headers)->assertStatus(422)
            ->assertJsonPath('message', 'The X-Atglance-Service-Account-Key header is required');
    }

    public function test_service_account_key_cannot_call_the_route(): void
    {
        [$system, $headers, $account] = $this->accountSystem();
        [$saKey] = $this->accountKey($account);

        $this->setAccount($headers, $saKey)->assertStatus(401)
            ->assertJsonPath('message', 'Service account keys work only with the atglance CLI API (/api/v1)');
        $this->deleteJson('/api/v1/systems/self/service-account', [], $headers)->assertStatus(401);
        $this->assertSame($account->id, (int) $system->fresh()->service_account_id);
    }

    public function test_wrong_system_key_is_404(): void
    {
        [$system, , $headers] = $this->personSystem();
        [$saKey] = $this->accountKey($this->makeAccount());

        $this->setAccount(['X-Atglance-System-Key' => str_repeat('z', 40)] + $headers, $saKey)->assertNotFound()
            ->assertJsonPath('message', 'System not found');
        $this->assertNull($system->fresh()->service_account_id);
    }

    public function test_detach_service_account(): void
    {
        [$system, $headers, $account] = $this->accountSystem();
        $registrar = User::where('email', 'registrar@example.test')->first();
        [$ownerKey] = $this->userKey($registrar);

        $this->deleteJson('/api/v1/systems/self/service-account', [], ['Authorization' => 'Bearer ' . $ownerKey] + $this->systemHeaders($system))
            ->assertOk()
            ->assertExactJson(['system' => [
                'id' => $system->id,
                'system_name' => 'web-01',
                'service_account_id' => null,
                'workspace_id' => $this->workspace->id,
            ]]);

        $this->assertNull($system->fresh()->service_account_id);
        $this->getJson('/api/v1/systems/self', $headers)->assertNotFound();
        $log = ActivityLog::where('event', 'system.service_account_changed')->sole();
        $this->assertStringContainsString('from ' . $account->id . ' to none', $log->description);
        $this->assertSame(['system_id' => $system->id, 'old_service_account_id' => $account->id, 'new_service_account_id' => null],
            SecurityEvent::where('type', 'system.service_account_changed')->sole()->details);
    }

    public function test_no_key_values_in_activity_or_security_events(): void
    {
        [, , $headers] = $this->personSystem();
        [$saKey] = $this->accountKey($this->makeAccount());
        [$saKey2] = $this->accountKey($this->makeAccount());

        $this->setAccount($headers, $saKey)->assertOk();
        $this->setAccount($headers, $saKey2)->assertOk();
        $this->deleteJson('/api/v1/systems/self/service-account', [], $headers)->assertOk();

        $rows = json_encode(ActivityLog::all()->toArray()) . json_encode(SecurityEvent::all()->toArray());
        foreach ([$saKey, $saKey2, self::SYSTEM_KEY, substr($headers['Authorization'], 7)] as $secret) {
            $this->assertStringNotContainsString($secret, $rows);
        }
        $this->assertSame(3, SecurityEvent::where('type', 'system.service_account_changed')->count());
    }
}
