<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\ConfigurationFile;
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
        $this->assertSame(str_repeat('b', 40), $system->fresh()->validation_hash);
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
}
