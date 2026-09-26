<?php

namespace App\Support;

use App\Models\PatToken;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the Overview section of /profile.
 *
 * "Mine" is always the signed-in user's own usage. Admins also get their
 * scope: the workspaces they administer (rbac 101) or the whole
 * organization (rbac 100).
 */
class ProfileOverview
{
    public const ROLE_LABELS = [
        100 => 'Super admin',
        101 => 'Admin',
        102 => 'User',
    ];

    /**
     * @return array{mine: array, scope: ?array}
     */
    public static function for(User $user): array
    {
        $rbac = (int) $user->rbac_id;

        return [
            'mine' => self::mine($user),
            'scope' => match ($rbac) {
                100 => self::organizationScope($user),
                101 => self::workspaceScope($user),
                default => null,
            },
        ];
    }

    public static function roleLabel(User $user): string
    {
        return self::ROLE_LABELS[(int) $user->rbac_id] ?? 'User';
    }

    private static function mine(User $user): array
    {
        $systems = DB::table('system_register')->where('user_id', $user->id);
        $files = DB::table('configuration_files')->where('user_id', $user->id);

        return [
            'systems_active' => (clone $systems)->where('status', 'active')->count(),
            'systems_inactive' => (clone $systems)->where('status', '!=', 'active')->count(),
            'files' => self::distinctFiles(clone $files),
            'versions' => (clone $files)->count(),
            'latest_backup' => self::toDate((clone $files)->max('created_at')),
            'api_keys' => PatToken::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->count(),
        ];
    }

    private static function organizationScope(User $user): array
    {
        $orgId = (int) ($user->org_id ?? 200);
        $files = DB::table('configuration_files');

        return [
            'label' => 'Organization',
            'description' => 'Everything in your organization.',
            'workspaces' => Workspace::query()->where('org_id', $orgId)->where('status', 'active')->count(),
            'users' => User::query()->where('org_id', $orgId)->count(),
            'systems_active' => DB::table('system_register')->where('status', 'active')->count(),
            'files' => self::distinctFiles(clone $files),
            'versions' => (clone $files)->count(),
            'latest_backup' => self::toDate((clone $files)->max('created_at')),
        ];
    }

    private static function workspaceScope(User $user): array
    {
        $workspaceIds = DB::table('workspace_user as wu')
            ->join('workspaces as w', 'w.id', '=', 'wu.workspace_id')
            ->where('wu.user_id', $user->id)
            ->where('wu.is_admin', true)
            ->where('w.status', 'active')
            ->pluck('w.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $files = DB::table('configuration_files as cf')
            ->join('system_register as sr', 'sr.id', '=', 'cf.system_register_id')
            ->whereIn('sr.workspace_id', $workspaceIds);

        return [
            'label' => count($workspaceIds) === 1 ? 'Your workspace' : 'Your workspaces',
            'description' => $workspaceIds === []
                ? 'You do not administer any workspace yet.'
                : 'Workspaces where you are a workspace admin.',
            'workspaces' => count($workspaceIds),
            'users' => DB::table('workspace_user')->whereIn('workspace_id', $workspaceIds)->distinct()->count('user_id'),
            'systems_active' => DB::table('system_register')
                ->whereIn('workspace_id', $workspaceIds)
                ->where('status', 'active')
                ->count(),
            'files' => self::distinctFiles((clone $files)->select('cf.system_register_id', 'cf.file_name'), false),
            'versions' => (clone $files)->count('cf.id'),
            'latest_backup' => self::toDate((clone $files)->max('cf.created_at')),
        ];
    }

    /**
     * A file is one name on one system; every upload of it is a version.
     */
    private static function distinctFiles(Builder $query, bool $selectColumns = true): int
    {
        if ($selectColumns) {
            $query->select('system_register_id', 'file_name');
        }

        return DB::query()->fromSub($query->distinct(), 'files')->count();
    }

    private static function toDate(mixed $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
