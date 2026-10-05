<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ScopesWorkspaceData;
use App\Http\Controllers\Controller;
use App\Http\Controllers\DashboardController;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiRun;
use App\Services\ConfigAiReviewer;
use App\Services\ConfigAiValidator;
use App\Support\WorkspaceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Read-only API for the AtGlance MCP server (mcp/ in this repository).
 *
 * Every route needs a PAT (auth.pat) and answers as that user: the same
 * workspace and role rules as the web console (ScopesWorkspaceData).
 * `workspace_id` narrows to one workspace; leave it out for every workspace
 * the user can see; 0 means the user's systems with no workspace.
 * See docs/mcp.md.
 */
class McpController extends Controller
{
    use ScopesWorkspaceData;

    private const MAX_LIMIT = 200;
    private const ROLES = [100 => 'super_admin', 101 => 'admin', 102 => 'user'];

    public function me(Request $request): JsonResponse
    {
        $user = $this->actor($request);

        return response()->json([
            'id' => (int) $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => self::ROLES[(int) $user->rbac_id] ?? 'user',
            'workspace_ids' => $this->workspaceIdsForVisibility($user),
        ]);
    }

    public function workspaces(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $workspaces = Workspace::query()
            ->whereIn('id', $this->workspaceIdsForVisibility($user))
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $workspaces->map(function (Workspace $workspace) use ($user) {
            $settings = WorkspaceSettings::get((int) $workspace->id);

            return [
                'id' => (int) $workspace->id,
                'name' => $workspace->name,
                'description' => $workspace->description,
                'status' => $workspace->status,
                'tags' => $settings['tags'],
                'your_role' => $workspace->hasUserAsAdmin($user->id) ? 'workspace_admin' : ((int) $user->rbac_id === 100 ? 'super_admin' : 'member'),
                'members' => $workspace->users()->count(),
                'systems' => DB::table('system_register')->where('workspace_id', $workspace->id)->count(),
                'ai_on_upload' => (bool) $settings['ai_on_upload'],
                'ai_schedule' => $settings['ai_sweep_enabled'] ? WorkspaceSettings::frequencyLabel($settings, 'ai_sweep') : null,
                'backup_schedule' => $settings['backup_enabled'] ? WorkspaceSettings::frequencyLabel($settings, 'backup') : null,
            ];
        })->values()]);
    }

    public function systems(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $request->validate([
            'workspace_id' => ['nullable', 'integer', 'min:0'],
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,inactive'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $query = DB::table('system_register as sr')
            ->leftJoin('workspaces as w', 'w.id', '=', 'sr.workspace_id')
            ->select('sr.id', 'sr.system_name', 'sr.status', 'sr.os_type', 'sr.distro', 'sr.ip_address', 'sr.public_ip', 'sr.tags', 'sr.workspace_id', 'w.name as workspace_name', 'sr.created_at');
        $this->applyWorkspaceScopeToSystemsQuery($query, 'sr', $user, $this->workspaceFilter($request));

        if ($request->filled('search')) {
            $term = '%' . $request->input('search') . '%';
            $query->where(fn ($q) => $q->where('sr.system_name', 'like', $term)->orWhere('sr.ip_address', 'like', $term)->orWhere('sr.tags', 'like', $term));
        }
        if ($request->filled('status')) {
            $query->where('sr.status', $request->input('status'));
        }

        $systems = $query->orderBy('sr.system_name')->limit($this->limit($request))->get();
        $stats = $this->systemCardStats($systems->pluck('id')->all());

        return response()->json(['data' => $systems->map(fn ($system) => [
            'id' => (int) $system->id,
            'name' => $system->system_name,
            'status' => $system->status,
            'os' => trim(($system->os_type ?? '') . ' ' . ($system->distro ?? '')),
            'ip_address' => $system->ip_address,
            'public_ip' => $system->public_ip,
            'tags' => $system->tags,
            'workspace' => $system->workspace_id ? ['id' => (int) $system->workspace_id, 'name' => $system->workspace_name] : null,
            'services' => $stats[$system->id]['services'] ?? 0,
            'config_backups' => $stats[$system->id]['configs'] ?? 0,
            'ai_errors' => $stats[$system->id]['error'] ?? 0,
            'ai_warnings' => $stats[$system->id]['warning'] ?? 0,
            'registered_at' => $system->created_at,
        ])->values()]);
    }

    /**
     * Config files: by default only the latest version of each file on each system.
     */
    public function configFiles(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $request->validate([
            'workspace_id' => ['nullable', 'integer', 'min:0'],
            'system_id' => ['nullable', 'integer'],
            'service' => ['nullable', 'string', 'max:255'],
            'ai_status' => ['nullable', 'in:error,warning,ok,unknown,not_checked'],
            'all_versions' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $query = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->select('cf.id', 'cf.file_name', 'cf.service_name', 'cf.version', 'cf.system_register_id', 'sr.system_name', 'sr.workspace_id', 'cf.created_at');
        $this->applyWorkspaceScopeToConfigurationQuery($query, 'cf', 'sr', $user, $this->workspaceFilter($request));

        if (!$request->boolean('all_versions')) {
            $query->whereIn('cf.id', DB::table('configuration_files')->selectRaw('MAX(id)')->groupBy('system_register_id', 'file_name'));
        }
        if ($request->filled('system_id')) {
            $query->where('cf.system_register_id', (int) $request->input('system_id'));
        }
        if ($request->filled('service')) {
            $query->where('cf.service_name', 'like', '%' . $request->input('service') . '%');
        }

        $files = $query->orderByDesc('cf.id')->get();
        $aiStatus = $this->latestAiStatus('cf.id', $files->pluck('id')->all());

        if ($request->filled('ai_status')) {
            $wanted = $request->input('ai_status');
            $files = $files->filter(fn ($file) => ($aiStatus[$file->id] ?? 'not_checked') === $wanted);
        }

        return response()->json(['data' => $files->take($this->limit($request))->map(fn ($file) => [
            'id' => (int) $file->id,
            'file_name' => $file->file_name,
            'service' => $file->service_name,
            'version' => $file->version,
            'system' => ['id' => (int) $file->system_register_id, 'name' => $file->system_name],
            'workspace_id' => $file->workspace_id ? (int) $file->workspace_id : null,
            'ai_status' => $aiStatus[$file->id] ?? 'not_checked',
            'uploaded_at' => $file->created_at,
        ])->values()]);
    }

    /**
     * One config file with its content (secrets masked) and its latest AI review.
     */
    public function configFile(Request $request, int $id, ConfigAiValidator $validator): JsonResponse
    {
        $user = $this->actor($request);
        $config = $this->accessibleConfig($user, $id);

        $content = (string) ($config->data ?? '');
        try {
            [$disk, $path] = ConfigAiReviewer::diskAndPath($config->storage_disk ?? null, (string) ($config->file_location ?? ''));
            if ($path !== '' && Storage::disk($disk)->exists($path)) {
                $content = (string) Storage::disk($disk)->get($path);
            }
        } catch (\Throwable) {
            // Use the raw_data copy.
        }

        // Secret-looking values are always masked: MCP answers go to an AI client.
        [$masked, $maskedCount] = $validator->maskSecrets($content);
        $review = DB::table('config_ai_validations')->where('configuration_file_id', $id)->latest('id')->first();

        return response()->json([
            'id' => (int) $config->id,
            'file_name' => $config->file_name,
            'service' => $config->service_name,
            'version' => $config->version,
            'system' => ['id' => (int) $config->system_register_id, 'name' => $config->system_name],
            'uploaded_at' => $config->created_at,
            'content' => $masked,
            'masked_values' => $maskedCount,
            'latest_review' => $review ? [
                'status' => $review->status,
                'summary' => $review->summary,
                'reviewed_at' => $review->created_at,
                'trigger' => $review->trigger ?? 'manual',
                'model' => $review->model,
                'findings' => json_decode((string) $review->result, true)['findings'] ?? [],
            ] : null,
        ]);
    }

    public function configVersions(Request $request, int $id): JsonResponse
    {
        $user = $this->actor($request);
        $config = $this->accessibleConfig($user, $id);

        $versions = DB::table('configuration_files')
            ->where('system_register_id', $config->system_register_id)
            ->where('file_name', $config->file_name)
            ->orderByDesc('id')
            ->get(['id', 'version', 'created_at']);
        $aiStatus = $this->latestAiStatus('cf.id', $versions->pluck('id')->all());

        return response()->json(['data' => $versions->values()->map(fn ($version, $index) => [
            'id' => (int) $version->id,
            'version' => $version->version,
            'latest' => $index === 0,
            'ai_status' => $aiStatus[$version->id] ?? 'not_checked',
            'uploaded_at' => $version->created_at,
        ])]);
    }

    public function vulnerabilities(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $request->validate([
            'workspace_id' => ['nullable', 'integer', 'min:0'],
            'severity' => ['nullable', 'in:error,warning'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
        ]);

        $rows = $this->vulnerableConfigurationsQuery($user, $this->workspaceFilter($request))
            ->when($request->filled('severity'), fn ($q) => $q->where('v.status', $request->input('severity')))
            ->orderByRaw("CASE WHEN v.status = 'error' THEN 0 ELSE 1 END")
            ->orderByDesc('v.created_at')
            ->limit($this->limit($request))
            ->get(['cf.id', 'cf.file_name', 'cf.service_name', 'cf.version', 'sr.id as system_id', 'sr.system_name', 'sr.workspace_id', 'v.status', 'v.summary', 'v.result', 'v.created_at']);

        return response()->json(['data' => $rows->map(fn ($row) => [
            'config_file_id' => (int) $row->id,
            'file_name' => $row->file_name,
            'service' => $row->service_name,
            'version' => $row->version,
            'system' => ['id' => (int) $row->system_id, 'name' => $row->system_name],
            'workspace_id' => $row->workspace_id ? (int) $row->workspace_id : null,
            'severity' => $row->status,
            'summary' => $row->summary,
            'findings' => collect(json_decode((string) $row->result, true)['findings'] ?? [])
                ->map(fn ($finding) => array_intersect_key((array) $finding, array_flip(['severity', 'line', 'issue', 'standard', 'fix'])))
                ->values(),
            'reviewed_at' => $row->created_at,
        ])->values()]);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $this->actor($request);
        $request->validate(['workspace_id' => ['nullable', 'integer', 'min:0']]);
        $workspaceId = $this->workspaceFilter($request);

        $systems = DB::table('system_register as sr');
        $this->applyWorkspaceScopeToSystemsQuery($systems, 'sr', $user, $workspaceId);

        $services = DB::table('services as s')->join('system_register as sr', 's.system_id', '=', 'sr.id');
        $this->applyWorkspaceScopeToSystemsQuery($services, 'sr', $user, $workspaceId);

        $configs = DB::table('configuration_files as cf')->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id');
        $this->applyWorkspaceScopeToConfigurationQuery($configs, 'cf', 'sr', $user, $workspaceId);

        $dashboard = app(DashboardController::class);
        $trend = $dashboard->validationTrend($user, $workspaceId);

        return response()->json([
            'workspace_id' => $workspaceId,
            'systems' => (clone $systems)->count('sr.id'),
            'active_systems' => (clone $systems)->where('sr.status', 'active')->count('sr.id'),
            'services' => $services->count('s.service_id'),
            'config_backups' => $configs->count('cf.id'),
            'vulnerabilities' => $dashboard->vulnerabilityStats($user, $workspaceId),
            'last_7_days' => $trend['days'],
            'last_ai_check' => $trend['last_check'],
        ]);
    }

    public function aiProgress(Request $request, int $workspaceId): JsonResponse
    {
        $user = $this->actor($request);
        if (!in_array($workspaceId, $this->workspaceIdsForVisibility($user), true)) {
            abort(404);
        }

        $run = WorkspaceAiRun::where('workspace_id', $workspaceId)->latest('id')->first();

        return response()->json($run?->progress() ?? ['total' => 0, 'message' => 'No check has been queued yet.']);
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    private function workspaceFilter(Request $request): ?int
    {
        return $request->filled('workspace_id') ? (int) $request->input('workspace_id') : null;
    }

    private function limit(Request $request): int
    {
        return min(self::MAX_LIMIT, (int) $request->input('limit', 50));
    }

    private function accessibleConfig(User $user, int $id): object
    {
        $config = DB::table('configuration_files as cf')
            ->leftJoin('raw_data as rd', 'cf.id', '=', 'rd.file_id')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->where('cf.id', $id)
            ->select('cf.*', 'rd.file_data as data', 'sr.system_name', 'sr.workspace_id as system_workspace_id', 'sr.user_id as system_user_id')
            ->first();

        // 404 for both "missing" and "not yours", so ids cannot be probed.
        if (!$config || !$this->canAccessConfigurationRecord($user, $config)) {
            abort(404);
        }

        return $config;
    }
}
