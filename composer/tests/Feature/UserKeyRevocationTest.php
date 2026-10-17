<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\PatToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Concerns\MakesServiceAccounts;
use Tests\TestCase;

class UserKeyRevocationTest extends TestCase
{
    use RefreshDatabase, MakesServiceAccounts;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpServiceAccountWorld();
    }

    public function test_deactivation_revokes_all_keys_and_reactivation_does_not_restore(): void
    {
        $admin = $this->makeUser('admin@example.test', 101);
        $this->actingAs($admin);
        $user = $this->makeUser('leaver@example.test');
        [, $a] = $this->userKey($user);
        [, $b] = $this->userKey($user);

        $user->status = 'inactive';
        $user->save();

        foreach ([$a, $b] as $key) {
            $key->refresh();
            $this->assertSame('revoked', $key->status);
            $this->assertSame('user_deactivated', $key->revoke_reason);
            $this->assertSame($admin->id, (int) $key->revoked_by_user_id);
        }
        $this->assertTrue(ActivityLog::where('event', 'user.keys_revoked')->exists());

        $user->status = 'active';
        $user->save();
        $this->assertSame('revoked', $a->fresh()->status);
    }

    public function test_org_change_and_delete_revoke(): void
    {
        $mover = $this->makeUser('mover@example.test');
        [, $moverKey] = $this->userKey($mover);
        $mover->org_id = 300;
        $mover->save();
        $this->assertSame('user_org_changed', $moverKey->fresh()->revoke_reason);

        $gone = $this->makeUser('gone@example.test');
        [, $goneKey] = $this->userKey($gone);
        $gone->delete();
        $row = PatToken::find($goneKey->id);
        $this->assertNotNull($row);
        $this->assertSame('revoked', $row->status);
        $this->assertSame('user_deleted', $row->revoke_reason);
    }

    public function test_other_changes_do_not_revoke(): void
    {
        $user = $this->makeUser('u@example.test');
        [, $key] = $this->userKey($user);
        $user->name = 'Renamed';
        $user->save();
        $this->workspace->addUser($user->id);
        $this->workspace->removeUser($user->id);

        $this->assertSame('active', $key->fresh()->status);
    }

    public function test_owned_accounts_lose_owner_and_keep_working(): void
    {
        Storage::fake('local');
        $owner = $this->makeUser('owner@example.test', 101);
        $account = $this->makeAccount(attributes: ['owner_user_id' => $owner->id]);
        [$plain] = $this->accountKey($account);
        $system = $this->makeSystem($owner, ['service_account_id' => $account->id]);

        $owner->status = 'inactive';
        $owner->save();

        $this->assertNull($account->fresh()->owner_user_id);
        $this->assertSame('active', $account->fresh()->status);

        $this->post('/api/v1/config-files', [
            'service_name' => 'sshd',
            'file' => UploadedFile::fake()->createWithContent('sshd_config', "x\n"),
        ], ['Authorization' => 'Bearer ' . $plain, 'Accept' => 'application/json'] + $this->systemHeaders($system))->assertCreated();
    }
}
