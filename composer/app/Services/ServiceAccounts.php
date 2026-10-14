<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\ServiceAccountGrant;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AccountAlerts;
use App\Support\ActivityRecorder;
use App\Support\ServiceAccountNotices;
use App\Support\ServiceAccountSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Every change to service accounts, their grants and keys. Callers check who may do it
 * (ServiceAccountAccess); this class checks that the change itself is valid (scope,
 * organization, quotas, key limit) and records it.
 */
class ServiceAccounts
{
    public const MAX_ACTIVE_KEYS = 2;
    public const KEY_PREFIX = 'atgsa-';

    public function create(User $actor, array $data): ServiceAccount
    {
        $orgId = (int) ($actor->org_id ?? 200);
        $scope = (string) ($data['scope'] ?? '');
        $name = trim((string) ($data['name'] ?? ''));

        if (!in_array($scope, [ServiceAccount::SCOPE_ORGANIZATION, ServiceAccount::SCOPE_WORKSPACE], true)) {
            throw new ServiceAccountException('Choose organization or workspace scope.');
        }
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The name must be 1 to 100 characters.');
        }

        $workspace = null;
        if ($scope === ServiceAccount::SCOPE_WORKSPACE) {
            $workspace = $this->workspaceInOrg((int) ($data['workspace_id'] ?? 0), $orgId);
        }

        $owner = $actor;
        if (!empty($data['owner_user_id'])) {
            $owner = $this->userInOrg((int) $data['owner_user_id'], $orgId);
        }

        $account = DB::transaction(function () use ($actor, $data, $orgId, $scope, $name, $workspace, $owner) {
            // Serialize creates in the same quota bucket (no-op on SQLite).
            $workspace
                ? Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first()
                : Organization::query()->whereKey($orgId)->lockForUpdate()->first();

            $sameScope = $this->bucket($orgId, $scope, $workspace?->id);

            if ((clone $sameScope)->where('name', $name)->exists()) {
                throw new ServiceAccountException('A service account with this name already exists here.');
            }

            $quota = $workspace ? ServiceAccountSettings::workspaceQuota() : ServiceAccountSettings::orgQuota();
            if ((clone $sameScope)->where('status', 'active')->count() >= $quota) {
                throw new ServiceAccountException("The limit of {$quota} active service accounts is reached.");
            }

            $account = ServiceAccount::create([
                'name' => $name,
                'description' => $data['description'] ?? null,
                'team' => $data['team'] ?? null,
                'scope' => $scope,
                'org_id' => $orgId,
                'workspace_id' => $workspace?->id,
                'owner_user_id' => $owner->id,
                'created_by_user_id' => $actor->id,
                'status' => 'active',
            ]);

            if ($workspace) {
                $permissions = array_key_exists('permissions', $data)
                    ? ServiceAccount::normalizePermissions($data['permissions'])
                    : ServiceAccount::DEFAULT_PERMISSIONS;
                $account->grants()->create([
                    'workspace_id' => $workspace->id,
                    'permissions' => $permissions,
                    'status' => 'active',
                    'granted_by_user_id' => $actor->id,
                ]);
            }

            return $account;
        });

        $this->record($actor, $account, 'service_account.created', 'Created ' . $scope . ' service account ' . $account->name);
        ServiceAccountNotices::accountChanged($account, 'created');

        return $account;
    }

    public function update(ServiceAccount $account, User $actor, array $data): void
    {
        $name = trim((string) ($data['name'] ?? $account->name));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The name must be 1 to 100 characters.');
        }

        $clash = $this->bucket((int) $account->org_id, $account->scope, $account->workspace_id ? (int) $account->workspace_id : null)
            ->where('name', $name)
            ->whereKeyNot($account->id)
            ->exists();
        if ($clash) {
            throw new ServiceAccountException('A service account with this name already exists here.');
        }

        $account->update([
            'name' => $name,
            'description' => $data['description'] ?? null,
            'team' => $data['team'] ?? null,
        ]);

        $this->record($actor, $account, 'service_account.updated', 'Updated service account ' . $account->name);
    }

    public function setStatus(ServiceAccount $account, User $actor, bool $active): void
    {
        if ($active && !$account->isActive()) {
            DB::transaction(function () use ($account) {
                $quota = $account->isOrganizationScope() ? ServiceAccountSettings::orgQuota() : ServiceAccountSettings::workspaceQuota();
                $activeCount = $this->bucket((int) $account->org_id, $account->scope, $account->workspace_id ? (int) $account->workspace_id : null)
                    ->where('status', 'active')
                    ->count();
                if ($activeCount >= $quota) {
                    throw new ServiceAccountException("The limit of {$quota} active service accounts is reached.");
                }
                $account->update(['status' => 'active']);
            });
            $this->record($actor, $account, 'service_account.enabled', 'Enabled service account ' . $account->name);

            return;
        }

        if (!$active && $account->isActive()) {
            $account->update(['status' => 'disabled']);
            $this->record($actor, $account, 'service_account.disabled', 'Disabled service account ' . $account->name);
            ServiceAccountNotices::accountChanged($account, 'disabled');
        }
    }

    public function transferOwner(ServiceAccount $account, User $actor, User $newOwner): void
    {
        $this->userInOrg((int) $newOwner->id, (int) $account->org_id);
        $account->update(['owner_user_id' => $newOwner->id]);

        $this->record($actor, $account, 'service_account.owner_transferred', 'Made ' . $newOwner->email . ' owner of service account ' . $account->name);
        AccountAlerts::serviceAccountOwnerAssigned($newOwner, $account);
    }

    public function setGrant(ServiceAccount $account, User $actor, Workspace $workspace, array $permissions): ServiceAccountGrant
    {
        if ((int) ($workspace->org_id ?? 200) !== (int) $account->org_id) {
            throw new ServiceAccountException('The workspace is not in this organization.');
        }
        if (!$account->isOrganizationScope() && (int) $account->workspace_id !== (int) $workspace->id) {
            throw new ServiceAccountException('A workspace service account works only in its own workspace.');
        }

        $existing = $account->grants()->where('workspace_id', $workspace->id)->first();
        $grant = $account->grants()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'permissions' => ServiceAccount::normalizePermissions($permissions),
                'status' => 'active',
                'granted_by_user_id' => $actor->id,
            ],
        );

        $event = $existing && $existing->status === 'active' ? 'service_account.grant_changed' : 'service_account.grant_added';
        $this->record($actor, $account, $event, 'Set the access of ' . $account->name . ' in workspace ' . $workspace->name, (int) $workspace->id);
        if ($event === 'service_account.grant_added' && $account->isOrganizationScope()) {
            ServiceAccountNotices::grantChanged($account, $workspace, true);
        }

        return $grant;
    }

    public function removeGrant(ServiceAccount $account, User $actor, Workspace $workspace): void
    {
        if (!$account->isOrganizationScope()) {
            throw new ServiceAccountException("A workspace service account's own workspace cannot be removed; disable the account instead.");
        }

        $updated = $account->grants()->where('workspace_id', $workspace->id)->where('status', 'active')->update(['status' => 'revoked']);
        if ($updated > 0) {
            $this->record($actor, $account, 'service_account.grant_removed', 'Removed ' . $account->name . ' from workspace ' . $workspace->name, (int) $workspace->id);
            ServiceAccountNotices::grantChanged($account, $workspace, false);
        }
    }

    /**
     * @return array{token: PatToken, plain: string} plain is shown once and never stored
     */
    public function issueKey(ServiceAccount $account, User $actor, string $name, ?int $days = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new ServiceAccountException('The key name must be 1 to 100 characters.');
        }
        if (!$account->isActive()) {
            throw new ServiceAccountException('Enable the service account before creating keys.');
        }

        $days = max(1, min($days ?? ServiceAccountSettings::defaultKeyDays(), ServiceAccountSettings::maxKeyDays()));

        $issued = DB::transaction(function () use ($account, $name, $days) {
            ServiceAccount::query()->whereKey($account->id)->lockForUpdate()->first();

            if ($account->activeKeys()->count() >= self::MAX_ACTIVE_KEYS) {
                throw new ServiceAccountException('A service account can have at most ' . self::MAX_ACTIVE_KEYS . ' active keys. Revoke one first.');
            }

            do {
                $plain = self::KEY_PREFIX . Str::random(32);
            } while (PatToken::query()->where('token', hash('sha256', $plain))->exists());

            $token = PatToken::create([
                'tokenable_type' => ServiceAccount::class,
                'tokenable_id' => $account->id,
                'user_id' => null,
                'name' => $name,
                'token' => hash('sha256', $plain),
                'token_encrypted' => null,
                'last_four' => substr($plain, -4),
                'abilities' => [],
                'status' => 'active',
                'expires_at' => now()->addDays($days),
            ]);

            return ['token' => $token, 'plain' => $plain];
        });

        $this->record($actor, $account, 'service_account.key_created', 'Created key "' . $name . '" (…' . $issued['token']->last_four . ') for ' . $account->name);

        return $issued;
    }

    public function revokeKey(PatToken $key, ?User $actor, string $reason): void
    {
        if ($key->status === 'revoked') {
            return;
        }

        $key->forceFill([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revoked_by_user_id' => $actor?->id,
            'revoke_reason' => mb_substr($reason, 0, 50),
        ])->save();

        if ($key->isServiceAccountKey() && ($account = ServiceAccount::find($key->tokenable_id))) {
            $this->record($actor, $account, 'service_account.key_revoked', 'Revoked key "' . $key->name . '" (…' . $key->last_four . ') of ' . $account->name . ': ' . $reason);
            ServiceAccountNotices::keyEvent($account, $key, 'revoked');
        }
    }

    public function assignSystem(SystemRegister $system, ServiceAccount $account, User $actor): void
    {
        $workspaceId = (int) ($system->workspace_id ?? 0);
        if ((int) $account->org_id !== (int) ($system->org_id ?? 200) || !$account->can('systems.register', $workspaceId)) {
            throw new ServiceAccountException("The service account needs Register access in the system's workspace.");
        }

        DB::transaction(function () use ($system, $account) {
            $system->service_account_id = $account->id;
            $system->save();

            // The account takes over the system's services, so deleting the person who
            // created them no longer cascades to the rows and cuts the version history.
            DB::table('services')->where('system_id', $system->id)->update([
                'user_id' => null,
                'service_account_id' => $account->id,
            ]);
        });

        ActivityRecorder::record(
            (int) $actor->id,
            'system.assigned_to_service_account',
            'Assigned system ' . $system->system_name . ' to service account ' . $account->name,
            ActivityRecorder::SUCCESS,
            null,
            $workspaceId,
            (int) $system->id,
        );
        ServiceAccountNotices::systemAssigned($system, $account);
    }

    /**
     * Accounts that share a name space and a quota: same organization and scope, and for
     * workspace accounts the same workspace.
     */
    private function bucket(int $orgId, string $scope, ?int $workspaceId)
    {
        return ServiceAccount::query()
            ->where('org_id', $orgId)
            ->where('scope', $scope)
            ->when($scope === ServiceAccount::SCOPE_WORKSPACE, fn ($query) => $query->where('workspace_id', $workspaceId));
    }

    private function workspaceInOrg(int $workspaceId, int $orgId): Workspace
    {
        $workspace = Workspace::query()->find($workspaceId);
        if (!$workspace || (int) ($workspace->org_id ?? 200) !== $orgId) {
            throw new ServiceAccountException('The workspace is not in this organization.');
        }

        return $workspace;
    }

    private function userInOrg(int $userId, int $orgId): User
    {
        $user = User::query()->find($userId);
        if (!$user || (int) ($user->org_id ?? 200) !== $orgId || strtolower((string) ($user->status ?? 'active')) !== 'active') {
            throw new ServiceAccountException('The owner must be an active user of this organization.');
        }

        return $user;
    }

    private function record(?User $actor, ServiceAccount $account, string $event, string $description, ?int $workspaceId = null): void
    {
        ActivityRecorder::record($actor?->id, $event, $description, ActivityRecorder::SUCCESS, null, $workspaceId ?? ($account->workspace_id ? (int) $account->workspace_id : null));
    }
}
