<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Who sees which systems, config files and AI results: super admin everything,
 * others their workspaces plus their own unassigned systems. Shared by the web
 * pages (DashboardController) and the read-only MCP API (Api\McpController).
 */
trait ScopesWorkspaceData
{
    /**
     * Configuration files the actor can see whose latest AI validation
     * reported warning (medium) or error (high), joined as `v`.
     */
    private function vulnerableConfigurationsQuery(User $actor, ?int $selectedWorkspaceId)
    {
        $latestValidations = DB::table('config_ai_validations')
            ->selectRaw('MAX(id) as id')
            ->groupBy('configuration_file_id');

        $query = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->join('config_ai_validations as v', 'v.configuration_file_id', '=', 'cf.id')
            ->joinSub($latestValidations, 'lv', 'lv.id', '=', 'v.id')
            ->whereIn('v.status', ['warning', 'error']);

        $this->applyWorkspaceScopeToConfigurationQuery($query, 'cf', 'sr', $actor, $selectedWorkspaceId);

        return $query;
    }

    private function workspaceIdsForVisibility(User $actor): array
    {
        if ((int) ($actor->rbac_id ?? 0) === 100) {
            return Workspace::query()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $actor->workspaces()
            ->pluck('workspaces.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function applyWorkspaceScopeToSystemsQuery($query, string $systemAlias, User $actor, ?int $selectedWorkspaceId = null): void
    {
        $isSuperAdmin = (int) ($actor->rbac_id ?? 0) === 100;
        $workspaceIds = $this->workspaceIdsForVisibility($actor);

        if ($selectedWorkspaceId === 0) {
            if ($isSuperAdmin) {
                $query->where(function ($unassignedQuery) use ($systemAlias) {
                    $unassignedQuery->where($systemAlias . '.workspace_id', 0)
                        ->orWhereNull($systemAlias . '.workspace_id');
                });
            } else {
                $query->where($systemAlias . '.user_id', (int) $actor->id)
                    ->where(function ($unassignedQuery) use ($systemAlias) {
                        $unassignedQuery->where($systemAlias . '.workspace_id', 0)
                            ->orWhereNull($systemAlias . '.workspace_id');
                    });
            }

            return;
        }

        if ($selectedWorkspaceId !== null && $selectedWorkspaceId > 0) {
            if (!$isSuperAdmin && !in_array($selectedWorkspaceId, $workspaceIds, true)) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($systemAlias . '.workspace_id', $selectedWorkspaceId);

            return;
        }

        if ($isSuperAdmin) {
            return;
        }

        // Same rule as canAccessSystemRecord: workspace members, plus the owner of an unassigned system.
        $query->where(function ($scoped) use ($workspaceIds, $systemAlias, $actor) {
            if (!empty($workspaceIds)) {
                $scoped->whereIn($systemAlias . '.workspace_id', $workspaceIds);
            }

            $scoped->orWhere(function ($ownUnassigned) use ($systemAlias, $actor) {
                $ownUnassigned->where($systemAlias . '.user_id', (int) $actor->id)
                    ->where(function ($unassigned) use ($systemAlias) {
                        $unassigned->whereNull($systemAlias . '.workspace_id')
                            ->orWhere($systemAlias . '.workspace_id', 0);
                    });
            });
        });
    }

    private function applyWorkspaceScopeToConfigurationQuery($query, string $configAlias, string $systemAlias, User $actor, ?int $selectedWorkspaceId = null): void
    {
        $isSuperAdmin = (int) ($actor->rbac_id ?? 0) === 100;
        $workspaceIds = $this->workspaceIdsForVisibility($actor);

        if ($selectedWorkspaceId === 0) {
            if ($isSuperAdmin) {
                $query->where(function ($unassignedQuery) use ($systemAlias) {
                    $unassignedQuery->where($systemAlias . '.workspace_id', 0)
                        ->orWhereNull($systemAlias . '.workspace_id');
                });
            } else {
                $query->where($configAlias . '.user_id', (int) $actor->id)
                    ->where(function ($unassignedQuery) use ($configAlias) {
                        $unassignedQuery->where($configAlias . '.system_register_id', 0)
                            ->orWhereNull($configAlias . '.system_register_id');
                    });
            }

            return;
        }

        if ($selectedWorkspaceId !== null && $selectedWorkspaceId > 0) {
            if (!$isSuperAdmin && !in_array($selectedWorkspaceId, $workspaceIds, true)) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($systemAlias . '.workspace_id', $selectedWorkspaceId);

            return;
        }

        if ($isSuperAdmin) {
            return;
        }

        $query->where(function ($scoped) use ($workspaceIds, $systemAlias, $configAlias, $actor) {
            if (!empty($workspaceIds)) {
                $scoped->whereIn($systemAlias . '.workspace_id', $workspaceIds);
            }

            // Same rule as canAccessConfigurationRecord: the owner sees configs of an unassigned system.
            $scoped->orWhere(function ($ownUnassigned) use ($configAlias, $systemAlias, $actor) {
                $ownUnassigned->where(function ($owner) use ($configAlias, $systemAlias, $actor) {
                    $owner->where($configAlias . '.user_id', (int) $actor->id)
                        ->orWhere($systemAlias . '.user_id', (int) $actor->id);
                })->where(function ($unassigned) use ($systemAlias) {
                    $unassigned->whereNull($systemAlias . '.workspace_id')
                        ->orWhere($systemAlias . '.workspace_id', 0);
                });
            });
        });
    }

    /**
     * Per-system counts for the Systems Registered cards: services, config
     * backups, and configs whose latest AI validation is error or warning.
     */
    private function systemCardStats(array $systemIds): array
    {
        if (empty($systemIds)) {
            return [];
        }

        $services = DB::table('services')->whereIn('system_id', $systemIds)
            ->groupBy('system_id')->pluck(DB::raw('COUNT(*)'), 'system_id');

        $configs = DB::table('configuration_files')->whereIn('system_register_id', $systemIds)
            ->groupBy('system_register_id')->pluck(DB::raw('COUNT(*)'), 'system_register_id');

        $latestValidations = DB::table('config_ai_validations')
            ->selectRaw('MAX(id) as id')
            ->groupBy('configuration_file_id');

        $findings = DB::table('configuration_files as cf')
            ->join('config_ai_validations as v', 'v.configuration_file_id', '=', 'cf.id')
            ->joinSub($latestValidations, 'lv', 'lv.id', '=', 'v.id')
            ->whereIn('cf.system_register_id', $systemIds)
            ->whereIn('v.status', ['error', 'warning'])
            ->groupBy('cf.system_register_id', 'v.status')
            ->get(['cf.system_register_id', 'v.status', DB::raw('COUNT(*) as total')]);

        $stats = [];
        foreach ($systemIds as $id) {
            $stats[$id] = [
                'services' => (int) ($services[$id] ?? 0),
                'configs' => (int) ($configs[$id] ?? 0),
                'error' => 0,
                'warning' => 0,
            ];
        }

        foreach ($findings as $row) {
            $stats[$row->system_register_id][$row->status] = (int) $row->total;
        }

        return $stats;
    }

    /**
     * Status (error, warning, ok, unknown) of the newest AI validation, keyed by
     * $column (cf.id for one config file, cf.service_id for a whole service).
     */
    private function latestAiStatus(string $column, array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $latest = DB::table('config_ai_validations as v')
            ->join('configuration_files as cf', 'v.configuration_file_id', '=', 'cf.id')
            ->whereIn($column, $keys)
            ->groupBy($column)
            ->selectRaw($column . ' as k, MAX(v.id) as id');

        return DB::table('config_ai_validations as v')
            ->joinSub($latest, 'lv', 'lv.id', '=', 'v.id')
            ->pluck('v.status', 'lv.k')
            ->all();
    }

    private function canAccessSystemRecord(User $actor, object $system): bool
    {
        if ((int) ($actor->rbac_id ?? 0) === 100) {
            return true;
        }

        $workspaceId = (int) ($system->workspace_id ?? 0);
        if ($workspaceId > 0) {
            return in_array($workspaceId, $this->workspaceIdsForVisibility($actor), true);
        }

        return (int) ($system->user_id ?? 0) === (int) $actor->id;
    }

    private function canAccessConfigurationRecord(User $actor, object $config): bool
    {
        if ((int) ($actor->rbac_id ?? 0) === 100) {
            return true;
        }

        $workspaceId = (int) ($config->system_workspace_id ?? 0);
        if ($workspaceId > 0) {
            return in_array($workspaceId, $this->workspaceIdsForVisibility($actor), true);
        }

        return (int) ($config->user_id ?? 0) === (int) $actor->id
            || (int) ($config->system_user_id ?? 0) === (int) $actor->id;
    }
}
