<?php

namespace Tests\Feature\Concerns;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Users, keys, workspaces, service accounts and systems for the service account tests.
 * Call setUpServiceAccountWorld() from setUp().
 */
trait MakesServiceAccounts
{
    protected const SYSTEM_KEY = 'k1-0123456789abcdef0123456789abcdef01234567';

    protected Workspace $workspace;
    protected Workspace $otherWorkspace;
    protected Workspace $foreignWorkspace;

    protected function setUpServiceAccountWorld(): void
    {
        config(['app.key' => 'base64:' . base64_encode(str_repeat('k', 32))]);

        Organization::query()->updateOrCreate(['id' => 200], ['name' => 'Acme Ops', 'status' => 'active']);
        Organization::query()->updateOrCreate(['id' => 300], ['name' => 'Other Org', 'status' => 'active']);
        $this->workspace = Workspace::create(['org_id' => 200, 'name' => 'production', 'status' => 'active']);
        $this->otherWorkspace = Workspace::create(['org_id' => 200, 'name' => 'staging', 'status' => 'active']);
        $this->foreignWorkspace = Workspace::create(['org_id' => 300, 'name' => 'theirs', 'status' => 'active']);
    }

    protected function makeUser(string $email, int $rbacId = 102, int $orgId = 200, string $status = 'active'): User
    {
        $user = new User();
        $user->forceFill([
            'name' => $email,
            'email' => $email,
            'password_hash' => Hash::make('secret-pass'),
            'password' => Hash::make('secret-pass'),
            'pin' => Hash::make('1234'),
            'dob' => '1990-01-01',
            'rbac_id' => $rbacId,
            'org_id' => $orgId,
            'status' => $status,
        ])->save();

        return $user;
    }

    /**
     * @return array{0: string, 1: PatToken} plaintext atgla- key and its row
     */
    protected function userKey(User $user): array
    {
        $plain = PatToken::generateCustomToken();
        $token = PatToken::create([
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => 'cli-' . Str::random(6),
            'token' => hash('sha256', $plain),
            'abilities' => ['*'],
            'status' => 'active',
            'expires_at' => now()->addDays(30),
        ]);

        return [$plain, $token];
    }

    protected function makeAccount(string $scope = ServiceAccount::SCOPE_WORKSPACE, ?Workspace $workspace = null, array $attributes = []): ServiceAccount
    {
        $workspace ??= $this->workspace;
        $account = ServiceAccount::create(array_merge([
            'name' => 'backup-agent-' . Str::random(4),
            'scope' => $scope,
            'org_id' => (int) $workspace->org_id,
            'workspace_id' => $scope === ServiceAccount::SCOPE_WORKSPACE ? $workspace->id : null,
            'status' => 'active',
        ], $attributes));

        if ($scope === ServiceAccount::SCOPE_WORKSPACE) {
            $account->grants()->create([
                'workspace_id' => $workspace->id,
                'permissions' => ServiceAccount::DEFAULT_PERMISSIONS,
                'status' => 'active',
            ]);
        }

        return $account;
    }

    protected function grant(ServiceAccount $account, Workspace $workspace, ?array $permissions = null): void
    {
        $account->grants()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            ['permissions' => $permissions ?? ServiceAccount::DEFAULT_PERMISSIONS, 'status' => 'active'],
        );
    }

    /**
     * @return array{0: string, 1: PatToken} plaintext atgsa- key and its row
     */
    protected function accountKey(ServiceAccount $account, array $overrides = []): array
    {
        $plain = 'atgsa-' . Str::random(32);
        $token = PatToken::create(array_merge([
            'tokenable_type' => ServiceAccount::class,
            'tokenable_id' => $account->id,
            'name' => 'fleet-' . Str::random(6),
            'token' => hash('sha256', $plain),
            'abilities' => [],
            'status' => 'active',
            'last_four' => substr($plain, -4),
            'expires_at' => now()->addDays(90),
        ], $overrides));

        return [$plain, $token];
    }

    protected function makeSystem(User $registeredBy, array $overrides = []): SystemRegister
    {
        $system = SystemRegister::create(array_merge([
            'pat_token_id' => 1,
            'user_id' => $registeredBy->id,
            'org_id' => $registeredBy->org_id,
            'workspace_id' => $this->workspace->id,
            'system_name' => 'web-01',
            'os_type' => 'linux',
            'distro' => 'ubuntu',
            'version' => '24.04',
            'ip_address' => '10.0.0.5',
            'status' => 'active',
            'validation_hash' => self::SYSTEM_KEY,
        ], $overrides));
        $this->systemKeys[$system->id] = (string) ($overrides['validation_hash'] ?? self::SYSTEM_KEY);

        return $system;
    }

    /** Plaintext keys of systems made by makeSystem(), by id: the database keeps only a hash. */
    protected array $systemKeys = [];

    /**
     * @return array<string, string>
     */
    protected function systemHeaders(\App\Models\SystemRegister $system, ?string $key = null): array
    {
        return ['X-Atglance-System-Id' => (string) $system->id, 'X-Atglance-System-Key' => $key ?? $this->systemKeys[$system->id] ?? self::SYSTEM_KEY];
    }
}
