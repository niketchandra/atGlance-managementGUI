<?php

namespace App\Support;

use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who may see and change service accounts. Super admin and admin: everything in their
 * organization. Workspace admin with the service_accounts permission: workspace accounts
 * of that workspace only. Nobody else, and never another organization.
 */
final class ServiceAccountAccess
{
    public static function canCreateOrganizationAccount(User $actor): bool
    {
        return self::isOrgAdmin($actor);
    }

    public static function canManageWorkspaceAccounts(User $actor, Workspace $workspace): bool
    {
        if ((int) ($workspace->org_id ?? 200) !== self::orgId($actor)) {
            return false;
        }

        return self::isOrgAdmin($actor) || $workspace->allows($actor, 'service_accounts');
    }

    public static function canManage(User $actor, ServiceAccount $account): bool
    {
        if ((int) $account->org_id !== self::orgId($actor)) {
            return false;
        }
        if (self::isOrgAdmin($actor)) {
            return true;
        }
        if ($account->isOrganizationScope() || !$account->workspace) {
            return false;
        }

        return $account->workspace->allows($actor, 'service_accounts');
    }

    public static function canGrant(User $actor, ServiceAccount $account, Workspace $workspace): bool
    {
        if ((int) ($workspace->org_id ?? 200) !== (int) $account->org_id) {
            return false;
        }
        if ($account->isOrganizationScope()) {
            return self::isOrgAdmin($actor) && (int) $account->org_id === self::orgId($actor);
        }

        return (int) $account->workspace_id === (int) $workspace->id && self::canManage($actor, $account);
    }

    public static function canAssignSystem(User $actor, SystemRegister $system): bool
    {
        $workspace = $system->workspace;
        if (!$workspace || (int) ($system->org_id ?? 200) !== self::orgId($actor)) {
            return false;
        }

        return self::isOrgAdmin($actor) || $workspace->allows($actor, 'service_accounts');
    }

    public static function findForActor(User $actor, int $id): ?ServiceAccount
    {
        $account = self::visibleQuery($actor)->whereKey($id)->first();

        return $account && self::canManage($actor, $account) ? $account : null;
    }

    /**
     * Accounts the actor may list: all of the organization for admins; for others, the
     * workspace accounts of workspaces where they hold the service_accounts permission.
     */
    public static function visibleQuery(User $actor): Builder
    {
        $query = ServiceAccount::query()->where('org_id', self::orgId($actor));
        if (self::isOrgAdmin($actor)) {
            return $query;
        }

        $workspaceIds = $actor->workspaces()->get()
            ->filter(fn (Workspace $workspace) => $workspace->allows($actor, 'service_accounts'))
            ->pluck('id')
            ->all();

        return $query->where('scope', ServiceAccount::SCOPE_WORKSPACE)->whereIn('workspace_id', $workspaceIds ?: [0]);
    }

    private static function isOrgAdmin(User $actor): bool
    {
        return in_array((int) ($actor->rbac_id ?? 0), [100, 101], true);
    }

    private static function orgId(User $actor): int
    {
        return (int) ($actor->org_id ?? 200);
    }
}
