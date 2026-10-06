<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Everything that happened in one workspace, newest first, for the workspace's Recent Activity tab.
 *
 * Read from the records themselves (systems, services, config uploads, AI reviews, backup and
 * Vulnerability Check runs, all tied to the workspace through its systems), plus activity log
 * entries tagged with the workspace (deregistrations, members, settings, notification groups).
 * Request payloads and secrets are never read.
 */
class WorkspaceActivity
{
    public const TYPES = [
        'systems' => 'Systems & services',
        'configs' => 'Config backups',
        'ai' => 'AI reviews & checks',
        'backups' => 'Workspace backups',
        'admin' => 'Members & settings',
    ];

    public const PERIODS = ['1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days', 'all' => 'All time'];

    /** Log events read from activity_logs, by type and icon. Others in the table are covered by the records above. */
    private const LOG_EVENTS = [
        'system.deregistered' => ['systems', 'fa-power-off', 'warning'],
        'system.reactivated' => ['systems', 'fa-server', 'success'],
        'config.ai_validated' => ['ai', 'fa-robot', null],
        'config.ai_validation_deleted' => ['ai', 'fa-trash-alt', 'info'],
        'workspace.updated' => ['admin', 'fa-pen', 'info'],
        'workspace.tags_updated' => ['admin', 'fa-tags', 'info'],
        'workspace.ai_settings_updated' => ['admin', 'fa-shield-alt', 'info'],
        'workspace.ai_queue_reset' => ['ai', 'fa-rotate-left', 'warning'],
        'workspace.backup_settings_updated' => ['admin', 'fa-clock-rotate-left', 'info'],
        'workspace.notification_settings_updated' => ['admin', 'fa-bell', 'info'],
        'workspace.member_added' => ['admin', 'fa-user-plus', 'success'],
        'workspace.member_removed' => ['admin', 'fa-user-minus', 'warning'],
        'notification.group_added' => ['admin', 'fa-bell', 'success'],
        'notification.group_updated' => ['admin', 'fa-bell', 'info'],
        'notification.group_deleted' => ['admin', 'fa-bell-slash', 'warning'],
    ];

    /**
     * @param array{type?: ?string, system?: ?int, period?: ?string} $filters
     */
    public static function paginate(int $workspaceId, array $filters, int $perPage = 50, int $page = 1): LengthAwarePaginator
    {
        $type = isset(self::TYPES[$filters['type'] ?? '']) ? $filters['type'] : null;
        $period = isset(self::PERIODS[$filters['period'] ?? '']) ? (string) $filters['period'] : '30';
        $since = $period === 'all' ? null : now()->subDays((int) $period);

        $systems = DB::table('system_register')->where('workspace_id', $workspaceId)->get(['id', 'system_name', 'ip_address'])->keyBy('id');
        $systemIds = $systems->keys()->all();
        if (!empty($filters['system']) && $systems->has((int) $filters['system'])) {
            $systemIds = [(int) $filters['system']];
        }

        // Each source returns at most enough rows to fill the requested page; the merge keeps the newest.
        $limit = $perPage * $page;
        $ctx = compact('workspaceId', 'systemIds', 'systems', 'since', 'limit');
        $systemFiltered = !empty($filters['system']);

        $sources = [
            'systems' => fn () => self::systems($ctx)->merge(self::services($ctx)),
            'configs' => fn () => self::uploads($ctx),
            'ai' => fn () => self::reviews($ctx)->merge($systemFiltered ? collect() : self::aiRuns($ctx)),
            'backups' => fn () => $systemFiltered ? collect() : self::backups($ctx),
        ];

        $entries = collect();
        foreach ($sources as $key => $source) {
            if ($type === null || $type === $key) {
                $entries = $entries->merge($source());
            }
        }
        $entries = $entries->merge(self::logEntries($ctx, $type, $systemFiltered));

        $users = DB::table('users')->whereIn('id', $entries->pluck('user_id')->filter()->unique()->all())->pluck('name', 'id');
        $sorted = $entries
            ->map(fn (array $entry) => $entry + ['actor' => $entry['user_id'] ? ($users[$entry['user_id']] ?? 'Deleted user') : null])
            ->sortByDesc(fn (array $entry) => $entry['at']->getTimestamp() . str_pad((string) $entry['sort'], 12, '0', STR_PAD_LEFT))
            ->values();

        return new LengthAwarePaginator(
            $sorted->slice(($page - 1) * $perPage, $perPage)->values(),
            // Total is a lower bound once a source hits its limit; enough for "next page" links.
            min($sorted->count(), $limit + ($sorted->count() >= $limit ? 1 : 0)),
            $perPage,
            $page,
            ['path' => request()->url(), 'query' => request()->query(), 'pageName' => 'activity_page'],
        );
    }

    private static function entry(string $type, string $icon, Carbon|string $at, string $title, array $extra = []): array
    {
        return $extra + [
            'type' => $type,
            'icon' => $icon,
            'at' => $at instanceof Carbon ? $at : Carbon::parse($at),
            'title' => $title,
            'detail' => null,
            'system' => null,
            'outcome' => 'info',
            'url' => null,
            'user_id' => null,
            'sort' => 0,
        ];
    }

    private static function since($query, ?Carbon $since, string $column = 'created_at')
    {
        return $since ? $query->where($column, '>=', $since) : $query;
    }

    private static function systems(array $ctx): Collection
    {
        $rows = self::since(DB::table('system_register')->whereIn('id', $ctx['systemIds']), $ctx['since'])
            ->orderByDesc('created_at')->limit($ctx['limit'])
            ->get(['id', 'system_name', 'ip_address', 'os_type', 'distro', 'user_id', 'created_at']);

        return $rows->map(fn ($row) => self::entry('systems', 'fa-server', $row->created_at, 'System registered: ' . $row->system_name, [
            'detail' => trim(implode(' · ', array_filter([$row->ip_address, trim(($row->distro ?: $row->os_type) ?? '')]))) ?: null,
            'system' => $row->system_name,
            'outcome' => 'success',
            'url' => route('systems-registered.services', $row->id),
            'user_id' => $row->user_id,
            'sort' => $row->id,
        ]));
    }

    private static function services(array $ctx): Collection
    {
        $rows = self::since(DB::table('services')->whereIn('system_id', $ctx['systemIds']), $ctx['since'])
            ->orderByDesc('created_at')->limit($ctx['limit'])
            ->get(['service_id', 'service_name', 'system_id', 'user_id', 'created_at']);

        return $rows->map(fn ($row) => self::entry('systems', 'fa-cubes', $row->created_at, 'Service added: ' . $row->service_name, [
            'system' => $ctx['systems'][$row->system_id]->system_name ?? null,
            'url' => route('systems-registered.services', $row->system_id),
            'user_id' => $row->user_id,
            'sort' => $row->service_id,
        ]));
    }

    private static function uploads(array $ctx): Collection
    {
        $rows = self::since(DB::table('configuration_files')->whereIn('system_register_id', $ctx['systemIds']), $ctx['since'])
            ->orderByDesc('created_at')->orderByDesc('id')->limit($ctx['limit'])
            ->get(['id', 'file_name', 'service_name', 'version', 'system_register_id', 'user_id', 'created_at']);

        return $rows->map(fn ($row) => self::entry('configs', 'fa-file-code', $row->created_at, 'Config backed up: ' . $row->file_name, [
            'detail' => trim(implode(' · ', array_filter([$row->service_name, $row->version ? 'version ' . $row->version : null]))) ?: null,
            'system' => $ctx['systems'][$row->system_register_id]->system_name ?? null,
            'url' => route('configuration-backups.view', $row->id),
            'user_id' => $row->user_id,
            'sort' => $row->id,
        ]));
    }

    private static function reviews(array $ctx): Collection
    {
        $rows = self::since(
            DB::table('config_ai_validations as v')
                ->join('configuration_files as f', 'f.id', '=', 'v.configuration_file_id')
                ->whereIn('f.system_register_id', $ctx['systemIds']),
            $ctx['since'],
            'v.created_at',
        )->orderByDesc('v.created_at')->orderByDesc('v.id')->limit($ctx['limit'])
            ->get(['v.id', 'v.configuration_file_id', 'v.status', 'v.trigger', 'v.model', 'v.user_id', 'v.created_at', 'f.file_name', 'f.version', 'f.system_register_id']);

        $outcome = ['error' => 'error', 'warning' => 'warning', 'ok' => 'success'];
        $labels = ['error' => 'issues found (high)', 'warning' => 'issues found (medium)', 'ok' => 'no issues'];

        return $rows->map(fn ($row) => self::entry('ai', 'fa-robot', $row->created_at, 'AI review of ' . $row->file_name . ': ' . ($labels[$row->status] ?? $row->status), [
            'detail' => trim(implode(' · ', array_filter([
                $row->version ? 'version ' . $row->version : null,
                ['manual' => 'run by hand', 'upload' => 'automatic on upload', 'schedule' => 'scheduled check'][$row->trigger] ?? null,
                $row->model,
            ]))) ?: null,
            'system' => $ctx['systems'][$row->system_register_id]->system_name ?? null,
            'outcome' => $outcome[$row->status] ?? 'info',
            'url' => route('configuration-backups.ai-validations.show', [$row->configuration_file_id, $row->id]),
            'user_id' => $row->user_id,
            'sort' => $row->id,
        ]));
    }

    private static function aiRuns(array $ctx): Collection
    {
        if (!Schema::hasTable('workspace_ai_runs')) {
            return collect();
        }
        $rows = self::since(DB::table('workspace_ai_runs')->where('workspace_id', $ctx['workspaceId']), $ctx['since'])
            ->orderByDesc('created_at')->limit($ctx['limit'])->get();

        return $rows->map(function ($row) {
            $state = $row->cancelled_at ? 'stopped' : ($row->finished_at ? 'finished' : 'in progress');

            return self::entry('ai', 'fa-shield-alt', $row->created_at, 'Vulnerability Check ' . $state . ' (' . (['manual' => 'Run now', 'schedule' => 'scheduled'][$row->trigger] ?? $row->trigger) . ')', [
                'detail' => sprintf('%d of %d files reviewed, %d failed, %d skipped', $row->done, $row->total, $row->failed, $row->skipped),
                'outcome' => $row->cancelled_at ? 'warning' : ($row->failed > 0 ? 'warning' : ($row->finished_at ? 'success' : 'info')),
                'user_id' => $row->started_by,
                'sort' => $row->id,
            ]);
        });
    }

    private static function backups(array $ctx): Collection
    {
        if (!Schema::hasTable('workspace_backup_runs')) {
            return collect();
        }
        $rows = self::since(DB::table('workspace_backup_runs')->where('workspace_id', $ctx['workspaceId']), $ctx['since'])
            ->orderByDesc('created_at')->limit($ctx['limit'])->get();

        return $rows->map(fn ($row) => self::entry('backups', 'fa-box-archive', $row->started_at ?? $row->created_at,
            'Workspace backup ' . (['success' => 'finished', 'failed' => 'failed', 'skipped' => 'skipped'][$row->status] ?? $row->status), [
                'detail' => $row->message ? mb_strimwidth((string) $row->message, 0, 160, '…') : null,
                'outcome' => ['success' => 'success', 'failed' => 'error', 'skipped' => 'warning'][$row->status] ?? 'info',
                'user_id' => $row->triggered_by,
                'sort' => $row->id,
            ]));
    }

    private static function logEntries(array $ctx, ?string $type, bool $systemFiltered): Collection
    {
        if (!Schema::hasColumn('activity_logs', 'workspace_id')) {
            return collect();
        }
        $events = array_keys(array_filter(self::LOG_EVENTS, fn (array $meta) => $type === null || $meta[0] === $type));
        if ($events === []) {
            return collect();
        }

        $query = DB::table('activity_logs')->whereIn('event', $events)
            ->where(fn ($q) => $systemFiltered
                ? $q->whereIn('system_register_id', $ctx['systemIds'])
                : $q->where('workspace_id', $ctx['workspaceId'])->orWhereIn('system_register_id', $ctx['systemIds']));
        // Successful AI reviews come from config_ai_validations; only failed attempts come from the log.
        $query->where(fn ($q) => $q->where('event', '!=', 'config.ai_validated')->orWhere('outcome', ActivityRecorder::FAILURE));

        $rows = self::since($query, $ctx['since'])->orderByDesc('created_at')->limit($ctx['limit'])
            ->get(['id', 'event', 'description', 'outcome', 'user_id', 'system_register_id', 'created_at']);

        return $rows->map(function ($row) use ($ctx) {
            [$type, $icon, $outcome] = self::LOG_EVENTS[$row->event];

            return self::entry($type, $icon, $row->created_at, (string) $row->description, [
                'system' => $ctx['systems'][$row->system_register_id]->system_name ?? null,
                'outcome' => $outcome ?? ($row->outcome === ActivityRecorder::FAILURE ? 'error' : 'info'),
                'user_id' => $row->user_id,
                'sort' => $row->id,
            ]);
        });
    }
}
