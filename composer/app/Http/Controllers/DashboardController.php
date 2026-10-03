<?php

namespace App\Http\Controllers;

use App\Models\ConfigAiValidation;
use App\Models\SystemRegister;
use App\Models\Workspace;
use App\Services\ConfigAiReviewer;
use App\Support\AccountAlerts;
use App\Support\AiSettings;
use App\Support\ActivityRecorder;
use App\Support\License;
use App\Support\UserPreferences;
use App\Support\WorkspaceSettings;
use App\Models\WorkspaceNotificationPreference;
use App\Notifications\NotificationEvents;
use App\Support\WebSessions;
use App\Support\S3Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\PatToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    public function selectWorkspace(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'min:0'],
        ]);

        if (!array_key_exists('workspace_id', $validated) || $validated['workspace_id'] === null || $validated['workspace_id'] === '') {
            $request->session()->forget('selected_workspace_id');

            return redirect()->back();
        }

        $workspaceId = (int) $validated['workspace_id'];
        $user = Auth::user();
        $userRole = (int) ($user->rbac_id ?? 0);

        $isAllowed = false;

        if ($workspaceId === 0) {
            $isAllowed = DB::table('system_register')
                ->where('user_id', (int) $user->id)
                ->where(function ($query) {
                    $query->where('workspace_id', 0)
                        ->orWhereNull('workspace_id');
                })
                ->exists();
        } else {
            $workspace = Workspace::findOrFail($workspaceId);

            if ($userRole === 100) {
                $isAllowed = (int) $workspace->org_id === (int) ($user->org_id ?? 200)
                    && (string) ($workspace->status ?? '') === 'active';
            } else {
                $isAllowed = $user->workspaces()->where('workspaces.id', $workspaceId)->exists();
            }
        }

        if (!$isAllowed) {
            return redirect()->back()->withErrors([
                'workspace_id' => 'You are not allowed to access the selected workspace.',
            ]);
        }

        $request->session()->put('selected_workspace_id', $workspaceId);

        return redirect()->back();
    }

    /**
     * Show the dashboard view
     */
    public function index(Request $request)
    {
        /** @var User $actor */
        $actor = Auth::user();

        if (in_array((int) $actor->rbac_id, [100, 101], true)) {
            return redirect()->route('admin.dashboard');
        }

        $now = Carbon::now();
        $currentWeekStart = $now->copy()->startOfWeek();
        $previousWeekStart = $currentWeekStart->copy()->subWeek();
        $previousWeekEnd = $currentWeekStart->copy()->subSecond();

        $selectedWorkspaceId = $request->session()->has('selected_workspace_id')
            ? (int) $request->session()->get('selected_workspace_id')
            : null;

        $workspaceIds = $this->workspaceIdsForVisibility($actor);

        $configBaseQuery = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id');
        $this->applyWorkspaceScopeToConfigurationQuery($configBaseQuery, 'cf', 'sr', $actor, $selectedWorkspaceId);

        $totalConfigBackups = (clone $configBaseQuery)->count('cf.id');
        $currentWeekConfigBackups = (clone $configBaseQuery)
            ->whereBetween('cf.created_at', [$currentWeekStart, $now])
            ->count('cf.id');
        $previousWeekConfigBackups = (clone $configBaseQuery)
            ->whereBetween('cf.created_at', [$previousWeekStart, $previousWeekEnd])
            ->count('cf.id');

        $systemsBaseQuery = DB::table('system_register as sr');
        $this->applyWorkspaceScopeToSystemsQuery($systemsBaseQuery, 'sr', $actor, $selectedWorkspaceId);

        $totalSystemsRegistered = (clone $systemsBaseQuery)->count('sr.id');
        $currentWeekSystemsRegistered = (clone $systemsBaseQuery)
            ->whereBetween('sr.created_at', [$currentWeekStart, $now])
            ->count('sr.id');
        $previousWeekSystemsRegistered = (clone $systemsBaseQuery)
            ->whereBetween('sr.created_at', [$previousWeekStart, $previousWeekEnd])
            ->count('sr.id');

        $configChange = $this->calculateWeeklyChange($currentWeekConfigBackups, $previousWeekConfigBackups);
        $systemsChange = $this->calculateWeeklyChange($currentWeekSystemsRegistered, $previousWeekSystemsRegistered);

        $servicesBaseQuery = DB::table('services as s')
            ->join('system_register as sr', 's.system_id', '=', 'sr.id');
        $this->applyWorkspaceScopeToSystemsQuery($servicesBaseQuery, 'sr', $actor, $selectedWorkspaceId);

        $totalServicesMonitored = (clone $servicesBaseQuery)->count('s.service_id');
        $currentWeekServicesMonitored = (clone $servicesBaseQuery)
            ->whereBetween('s.created_at', [$currentWeekStart, $now])
            ->count('s.service_id');
        $previousWeekServicesMonitored = (clone $servicesBaseQuery)
            ->whereBetween('s.created_at', [$previousWeekStart, $previousWeekEnd])
            ->count('s.service_id');

        $servicesChange = $this->calculateWeeklyChange($currentWeekServicesMonitored, $previousWeekServicesMonitored);
        $vulnerabilityStats = $this->vulnerabilityStats($actor, $selectedWorkspaceId);

        return view('dashboard', [
            'totalConfigBackups' => $totalConfigBackups,
            'totalSystemsRegistered' => $totalSystemsRegistered,
            'totalServicesMonitored' => $totalServicesMonitored,
            'vulnerabilityStats' => $vulnerabilityStats,
            'validationTrend' => $this->validationTrend($actor, $selectedWorkspaceId),
            'configChange' => $configChange,
            'systemsChange' => $systemsChange,
            'servicesChange' => $servicesChange,
        ]);
    }

    private function calculateWeeklyChange(int $current, int $previous): array
    {
        if ($previous === 0) {
            if ($current === 0) {
                return ['percent' => 0, 'direction' => 'flat'];
            }

            return ['percent' => 100, 'direction' => 'up'];
        }

        $delta = (($current - $previous) / $previous) * 100;

        if ($delta > 0) {
            return ['percent' => (int) round(abs($delta)), 'direction' => 'up'];
        }

        if ($delta < 0) {
            return ['percent' => (int) round(abs($delta)), 'direction' => 'down'];
        }

        return ['percent' => 0, 'direction' => 'flat'];
    }

    public function configurationBackups(Request $request)
    {
        /** @var User $actor */
        $actor = Auth::user();

        // Latest version of each service on each system
        $latestVersionsSubquery = DB::table('configuration_files')
            ->select(DB::raw('MAX(id) as latest_id'))
            ->groupBy('system_register_id', 'service_name');

        $query = DB::table('configuration_files as cf')
            ->joinSub($latestVersionsSubquery, 'latest', function ($join) {
                $join->on('cf.id', '=', 'latest.latest_id');
            })
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->select(
                'cf.id',
                'cf.file_name',
                'cf.service_id',
                'cf.service_name',
                'cf.system_register_id',
                'cf.validation_hash',
                'cf.version',
                'cf.status',
                'cf.file_location',
                'cf.created_at',
                'sr.system_name',
                'sr.status as system_status',
                DB::raw("(SELECT COUNT(*) FROM configuration_files AS cfv WHERE COALESCE(cfv.service_name, '') = COALESCE(cf.service_name, '') AND COALESCE(cfv.system_register_id, 0) = COALESCE(cf.system_register_id, 0)) as version_count")
            );

            $this->applyWorkspaceScopeToConfigurationQuery($query, 'cf', 'sr', $actor);

        if ($request->filled('service_name')) {
            $query->where('cf.service_name', 'like', '%' . $request->service_name . '%');
        }

        if ($request->filled('status')) {
            $query->where('cf.status', $request->status);
        }

        if ($request->filled('date')) {
            $query->whereDate('cf.created_at', $request->date);
        }

        if ($request->filled('system_id')) {
            $query->where('cf.system_register_id', $request->system_id);
        }

        if ($request->filled('hash')) {
            $query->where('cf.validation_hash', 'like', '%' . $request->hash . '%');
        }

        $items = $query->orderByDesc('cf.created_at')
            ->paginate(UserPreferences::get($actor, 'per_page'))
            ->withQueryString();

        $aiStatus = $this->latestAiStatus('cf.id', $items->pluck('id')->all());

        return view('configuration-backups', compact('items', 'aiStatus'));
    }

    public function systemsRegistered(Request $request)
    {
        /** @var User $actor */
        $actor = Auth::user();
        $actorRole = (int) ($actor->rbac_id ?? 0);
        $selectedWorkspaceId = $request->session()->has('selected_workspace_id')
            ? (int) $request->session()->get('selected_workspace_id')
            : null;

        $query = DB::table('system_register as sr')
            ->leftJoin('workspaces as w', 'w.id', '=', 'sr.workspace_id')
            ->select('sr.*', 'w.name as workspace_name');

        $this->applyWorkspaceScopeToSystemsQuery($query, 'sr', $actor, $selectedWorkspaceId);

        // Apply filters
        if ($request->filled('name')) {
            $query->where('sr.system_name', 'like', '%' . $request->name . '%');
        }

        if ($request->filled('ip')) {
            $query->where('sr.ip_address', 'like', '%' . $request->ip . '%');
        }

        if ($request->filled('tags')) {
            $query->where('sr.tags', 'like', '%' . $request->tags . '%');
        }

        if ($request->filled('os')) {
            $query->where('sr.os_type', 'like', '%' . $request->os . '%');
        }

        if ($request->filled('status')) {
            $query->where('sr.status', $request->status);
        }

        if ($request->filled('hash')) {
            $query->where('sr.validation_hash', 'like', '%' . $request->hash . '%');
        }

        $items = $query->orderByDesc('sr.created_at')
            ->paginate(UserPreferences::get($actor, 'per_page'))
            ->withQueryString();

        $systemStats = $this->systemCardStats($items->pluck('id')->all());

        // Tag filter suggestions: the system tag lists of the selected workspace, or of every visible one.
        $catalogueWorkspaceIds = $selectedWorkspaceId !== null && $selectedWorkspaceId > 0
            ? [$selectedWorkspaceId]
            : $this->workspaceIdsForVisibility($actor);
        $tagCatalogue = collect($catalogueWorkspaceIds)
            ->flatMap(fn (int $id) => WorkspaceSettings::tagLabels(WorkspaceSettings::get($id)['tags']))
            ->unique(fn (string $tag) => mb_strtolower($tag))
            ->sort()
            ->values()
            ->all();

        return view('systems-registered', compact('items', 'systemStats', 'tagCatalogue'));
    }

    public function editRegisteredSystem(int $systemId)
    {
        /** @var User $actor */
        $actor = Auth::user();
        $actorRole = (int) ($actor->rbac_id ?? 0);
        $isAdmin = in_array($actorRole, [100, 101], true);

        $query = SystemRegister::query();
        if (!in_array($actorRole, [100, 101], true)) {
            $query->where('user_id', $actor->id);
        } elseif ($actorRole === 101) {
            $query->where('org_id', $actor->org_id);
        }

        $system = $query->findOrFail($systemId);

        if ((bool) ($system->is_locked ?? false) && !$isAdmin) {
            return redirect()
                ->route('systems-registered')
                ->withErrors(['system' => 'System Info is locked. Contact an admin to edit details.']);
        }

        $workspaces = $this->editableWorkspacesForActor($actor);

        return view('systems-registered-edit', [
            'system' => $system,
            'workspaces' => $workspaces,
            'isAdmin' => $isAdmin,
            'tagCatalogue' => (int) ($system->workspace_id ?? 0) > 0
                ? WorkspaceSettings::tagLabels(WorkspaceSettings::get((int) $system->workspace_id)['tags'])
                : [],
        ]);
    }

    public function updateRegisteredSystem(Request $request, int $systemId): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();
        $actorRole = (int) ($actor->rbac_id ?? 0);
        $isAdmin = in_array($actorRole, [100, 101], true);

        $query = SystemRegister::query();
        if (!$isAdmin) {
            $query->where('user_id', $actor->id);
        } elseif ($actorRole === 101) {
            $query->where('org_id', $actor->org_id);
        }

        $system = $query->findOrFail($systemId);

        if ((bool) ($system->is_locked ?? false) && !$isAdmin) {
            return redirect()->back()->withErrors([
                'system' => 'This system is locked. Contact an admin to modify details.',
            ]);
        }

        $rules = [
            'workspace_id' => ['nullable', 'integer', 'min:0'],
            'tags' => ['nullable', 'string', 'max:512'],
            'public_ip' => ['nullable', 'string', 'max:45'],
            'public_facing' => ['required', 'in:0,1'],
            'description' => ['nullable', 'string', 'max:4000'],
            'distro' => ['nullable', 'string', 'max:1000'],
            'version' => ['nullable', 'string', 'max:1000'],
        ];

        if ($isAdmin) {
            $rules['status'] = ['required', 'in:active,inactive'];
            $rules['is_locked'] = ['required', 'in:0,1'];
        }

        $validated = $request->validate($rules);

        $workspaceIds = $this->editableWorkspacesForActor($actor)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $workspaceIdInput = isset($validated['workspace_id']) && $validated['workspace_id'] !== ''
            ? (int) $validated['workspace_id']
            : 0;

        if ($workspaceIdInput !== 0 && !in_array($workspaceIdInput, $workspaceIds, true)) {
            return redirect()->back()->withErrors([
                'workspace_id' => 'You are not allowed to assign this workspace.',
            ])->withInput();
        }

        $updateData = [
            'workspace_id' => $workspaceIdInput,
            'tags' => $validated['tags'] ?? null,
            'public_ip' => $validated['public_ip'] ?? null,
            'public_facing' => ((string) $validated['public_facing']) === '1',
            'description' => $validated['description'] ?? null,
            'distro' => $validated['distro'] ?? null,
            'version' => $validated['version'] ?? null,
        ];

        if ($isAdmin) {
            $updateData['status'] = $validated['status'];
            $updateData['is_locked'] = ((string) $validated['is_locked']) === '1';
        }

        $system->update($updateData);

        return redirect()
            ->route('systems-registered')
            ->with('success', 'System details updated successfully.');
    }

    private function editableWorkspacesForActor(User $actor)
    {
        $role = (int) ($actor->rbac_id ?? 0);

        if ($role === 100) {
            return Workspace::query()
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        if ($role === 101) {
            return Workspace::query()
                ->where('org_id', $actor->org_id)
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        return $actor->workspaces()
            ->where('workspaces.status', 'active')
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name']);
    }

    public function deleteRegisteredSystem(int $systemId): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();
        if (!in_array((int) ($actor->rbac_id ?? 0), [100, 101], true)) {
            abort(403);
        }

        $system = SystemRegister::findOrFail($systemId);

        // Admin can delete systems within their own org. Super admin can delete globally.
        if ((int) ($actor->rbac_id ?? 0) === 101 && (int) ($system->org_id ?? 0) !== (int) ($actor->org_id ?? 0)) {
            abort(403);
        }

        DB::table('configuration_files')
            ->where('system_register_id', $system->id)
            ->update(['system_register_id' => null]);

        DB::table('raw_data')
            ->where('system_register_id', $system->id)
            ->update(['system_register_id' => null]);

        $systemName = (string) ($system->system_name ?? $system->id);
        $system->delete();

        return redirect()
            ->route('systems-registered')
            ->with('success', "System '{$systemName}' deleted successfully.");
    }

    /**
     * List all services for a specific registered system
     */
    public function systemServices(Request $request, $systemId)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $system = DB::table('system_register')->where('id', $systemId)->first();

        if (!$system) {
            abort(404, 'System not found');
        }

        if (!$this->canAccessSystemRecord($actor, $system)) {
            abort(403);
        }

        $query = DB::table('services as s')
            ->leftJoin('configuration_files as cf', 's.service_id', '=', 'cf.service_id')
            ->where('s.system_id', $systemId)
            ->select(
                's.service_id',
                's.service_name',
                's.system_id',
                's.system_hash',
                's.org_id',
                's.share_with',
                's.status',
                's.created_at',
                DB::raw('COUNT(cf.id) as config_count'),
                DB::raw('MAX(cf.version) as latest_version')
            )
            ->groupBy(
                's.service_id',
                's.service_name',
                's.system_id',
                's.system_hash',
                's.org_id',
                's.share_with',
                's.status',
                's.created_at'
            );

        if ($request->filled('service_name')) {
            $query->where('s.service_name', 'like', '%' . $request->service_name . '%');
        }

        if ($request->filled('status')) {
            $query->where('s.status', $request->status);
        }

        $services = $query->orderByDesc('s.created_at')->get();

        $aiStatus = $this->latestAiStatus('cf.service_id', $services->pluck('service_id')->all());

        return view('system-services', compact('system', 'services', 'aiStatus'));
    }

    public function liveServiceMonitoring()
    {
        return view('live-service-monitoring');
    }

    public function vulnerabilitiesIdentified(Request $request)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $selectedWorkspaceId = $request->session()->has('selected_workspace_id')
            ? (int) $request->session()->get('selected_workspace_id')
            : null;

        $severity = in_array($request->query('severity'), ['warning', 'error'], true)
            ? $request->query('severity')
            : null;

        $findings = $this->vulnerableConfigurationsQuery($actor, $selectedWorkspaceId)
            ->when($severity, fn ($query) => $query->where('v.status', $severity))
            ->select(
                'cf.id',
                'cf.service_id',
                'cf.service_name',
                'cf.file_name',
                'cf.version',
                'sr.system_name',
                'v.id as validation_id',
                'v.status as severity',
                'v.summary',
                'v.provider',
                'v.model',
                'v.created_at as validated_at'
            )
            ->orderByRaw("CASE WHEN v.status = 'error' THEN 0 ELSE 1 END")
            ->orderByDesc('v.created_at')
            ->paginate(25)
            ->withQueryString();

        return view('vulnerabilities-identified', [
            'findings' => $findings,
            'severity' => $severity,
            'vulnerabilityStats' => $this->vulnerabilityStats($actor, $selectedWorkspaceId),
        ]);
    }

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

    /**
     * Config state at the end of each of the last 7 days: how many configs had
     * a latest AI validation of error, warning or ok ("no issues") by then.
     * Days without checks carry the previous state forward instead of dropping to 0.
     */
    public function validationTrend(User $actor, ?int $selectedWorkspaceId): array
    {
        $query = DB::table('config_ai_validations as v')
            ->join('configuration_files as cf', 'v.configuration_file_id', '=', 'cf.id')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id');
        $this->applyWorkspaceScopeToConfigurationQuery($query, 'cf', 'sr', $actor, $selectedWorkspaceId);

        $validations = $query->orderBy('v.created_at')->orderBy('v.id')
            ->get(['v.configuration_file_id', 'v.status', 'v.created_at']);

        $days = [];
        $latestByConfig = [];
        $next = 0;
        $start = now()->subDays(6)->startOfDay();

        for ($i = 0; $i < 7; $i++) {
            $day = $start->copy()->addDays($i);
            $dayEnd = $day->copy()->endOfDay()->toDateTimeString();

            while ($next < $validations->count() && $validations[$next]->created_at <= $dayEnd) {
                $latestByConfig[$validations[$next]->configuration_file_id] = $validations[$next]->status;
                $next++;
            }

            $counts = array_count_values($latestByConfig);
            $days[] = [
                'label' => $day->format('D j'),
                'error' => $counts['error'] ?? 0,
                'warning' => $counts['warning'] ?? 0,
                'ok' => $counts['ok'] ?? 0,
            ];
        }

        $lastCheck = $validations->last()?->created_at;

        return [
            'days' => $days,
            'last_check' => $lastCheck ? UserPreferences::datetime($lastCheck) : null,
        ];
    }

    /**
     * Warning and error counts plus week-over-week change, keyed by severity.
     * Shared with the admin dashboard.
     */
    public function vulnerabilityStats(User $actor, ?int $selectedWorkspaceId = null): array
    {
        $now = now();
        $currentWeekStart = $now->copy()->startOfWeek();
        $previousWeekStart = $currentWeekStart->copy()->subWeek();
        $previousWeekEnd = $currentWeekStart->copy()->subSecond();

        $stats = [];
        foreach (['error', 'warning'] as $severity) {
            $base = $this->vulnerableConfigurationsQuery($actor, $selectedWorkspaceId)->where('v.status', $severity);

            $current = (clone $base)->whereBetween('v.created_at', [$currentWeekStart, $now])->count('cf.id');
            $previous = (clone $base)->whereBetween('v.created_at', [$previousWeekStart, $previousWeekEnd])->count('cf.id');

            $stats[$severity] = [
                'total' => (clone $base)->count('cf.id'),
                'change' => $this->calculateWeeklyChange($current, $previous),
            ];
        }

        return $stats;
    }

    /**
     * View all versions of a service configuration
     */
    public function viewServiceVersions($serviceId)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $service = DB::table('services')->where('service_id', $serviceId)->first();

        if (!$service) {
            abort(404, 'Service not found');
        }

        $system = DB::table('system_register')->where('id', $service->system_id)->first();
        if (!$system || !$this->canAccessSystemRecord($actor, $system)) {
            abort(403);
        }
        
        $versions = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->where('cf.service_id', $serviceId)
            ->select(
                'cf.id',
                'cf.service_id',
                'cf.file_name',
                'cf.service_name',
                'cf.version',
                'cf.validation_hash',
                'cf.status',
                'cf.created_at',
                'cf.updated_at',
                'sr.system_name',
                'sr.status as system_status'
            )
            ->orderByDesc('cf.created_at')
            ->get();

        if ($versions->isEmpty()) {
            abort(404, 'No configuration files found for this service');
        }

        $versions = $this->applyDisplayVersionFallback($versions);

        $serviceName = $service->service_name;
        $systemId = $service->system_id;

        return view('view-service-versions', compact('versions', 'serviceName', 'systemId'));
    }

    /**
     * Backward-compatible versions view by service name
     */
    public function viewServiceVersionsByName($serviceName)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $serviceName = urldecode($serviceName);

        $workspaceIds = $this->workspaceIdsForVisibility($actor);
        $isSuperAdmin = (int) ($actor->rbac_id ?? 0) === 100;

        $versions = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->where('cf.service_name', $serviceName)
            ->when(!$isSuperAdmin, function ($query) use ($actor, $workspaceIds) {
                $query->where(function ($scoped) use ($actor, $workspaceIds) {
                    if (!empty($workspaceIds)) {
                        $scoped->whereIn('sr.workspace_id', $workspaceIds);
                    }

                    $scoped->orWhere(function ($ownUnassigned) use ($actor) {
                        $ownUnassigned->where(function ($owner) use ($actor) {
                            $owner->where('cf.user_id', (int) $actor->id)
                                ->orWhere('sr.user_id', (int) $actor->id);
                        })->where(function ($unassigned) {
                            $unassigned->whereNull('sr.workspace_id')
                                ->orWhere('sr.workspace_id', 0);
                        });
                    });
                });
            })
            ->select(
                'cf.id',
                'cf.service_id',
                'cf.file_name',
                'cf.service_name',
                'cf.version',
                'cf.validation_hash',
                'cf.status',
                'cf.created_at',
                'cf.updated_at',
                'sr.system_name',
                'sr.status as system_status',
                'cf.system_register_id'
            )
            ->orderByDesc('cf.created_at')
            ->get();

        if ($versions->isEmpty()) {
            abort(404, 'No configuration files found for this service');
        }

        $versions = $this->applyDisplayVersionFallback($versions);

        $systemId = $versions->first()->system_register_id;

        return view('view-service-versions', compact('versions', 'serviceName', 'systemId'));
    }

    /**
     * View configuration file content
     */
    public function viewConfigurationFile($id)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $config = DB::table('configuration_files')
            ->leftJoin('raw_data', 'configuration_files.id', '=', 'raw_data.file_id')
            ->leftJoin('system_register as sr', 'configuration_files.system_register_id', '=', 'sr.id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'raw_data.file_data as data', 'sr.workspace_id as system_workspace_id', 'sr.user_id as system_user_id')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        if (!$this->canAccessConfigurationRecord($actor, $config)) {
            abort(403);
        }

        [$disk, $path] = $this->resolveDiskAndPathForRead($config->storage_disk ?? null, (string) ($config->file_location ?? ''));
        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            $content = Storage::disk($disk)->get($path);
            if ($content !== false && $content !== null) {
                $config->data = $content;
            }
        }

        $aiEnabled = AiSettings::enabled();
        $aiProviderLabel = AiSettings::PROVIDERS[AiSettings::provider()]['label'];
        $aiModel = AiSettings::model();

        $aiHistory = ConfigAiValidation::with('user:id,name')
            ->where('configuration_file_id', $config->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        // A shared link (?validation=ID) opens that saved result straight away.
        $aiSelected = null;
        if (request()->filled('validation')) {
            $aiSelected = $aiHistory->firstWhere('id', (int) request('validation'))
                ?? ConfigAiValidation::with('user:id,name')
                    ->where('configuration_file_id', $config->id)
                    ->find((int) request('validation'));
        }

        return view('view-configuration', [
            'config' => $config,
            'aiEnabled' => $aiEnabled,
            'aiProviderLabel' => $aiProviderLabel,
            'aiModel' => $aiModel,
            'aiHistory' => $aiHistory,
            'aiSelected' => $aiSelected ? $aiSelected->toPayload() + [
                'share_url' => route('configuration-backups.view', ['id' => $config->id, 'validation' => $aiSelected->id]),
                'can_delete' => $this->canDeleteAiValidation($actor, $aiSelected),
            ] : null,
        ]);
    }

    /**
     * Review a configuration file with the AI provider set in AI Connect.
     */
    public function validateConfigurationWithAi(Request $request, int $id, ConfigAiReviewer $reviewer)
    {
        /** @var User $actor */
        $actor = Auth::user();

        if (!AiSettings::enabled()) {
            return response()->json(['success' => false, 'message' => 'AI Connect is not enabled. A super admin can turn it on in Admin Settings.'], 403);
        }

        $config = DB::table('configuration_files')
            ->leftJoin('system_register as sr', 'configuration_files.system_register_id', '=', 'sr.id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'sr.workspace_id as system_workspace_id', 'sr.user_id as system_user_id')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        if (!$this->canAccessConfigurationRecord($actor, $config)) {
            abort(403);
        }

        try {
            $validation = $reviewer->review((int) $config->id, (int) $actor->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 502);
        }

        $validation->setRelation('user', $actor);

        return response()->json($validation->toPayload() + [
            'share_url' => route('configuration-backups.view', ['id' => $config->id, 'validation' => $validation->id]),
            'can_delete' => true,
        ]);
    }

    /**
     * A saved AI validation result, for anyone who can open the configuration file.
     */
    public function showConfigurationAiValidation(int $id, int $validationId)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $config = DB::table('configuration_files')
            ->leftJoin('raw_data', 'configuration_files.id', '=', 'raw_data.file_id')
            ->leftJoin('system_register as sr', 'configuration_files.system_register_id', '=', 'sr.id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'raw_data.file_data as data', 'sr.workspace_id as system_workspace_id', 'sr.user_id as system_user_id')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        if (!$this->canAccessConfigurationRecord($actor, $config)) {
            abort(403);
        }

        $validation = ConfigAiValidation::with('user:id,name')
            ->where('configuration_file_id', $config->id)
            ->findOrFail($validationId);

        return response()->json($validation->toPayload() + [
            'share_url' => route('configuration-backups.view', ['id' => $config->id, 'validation' => $validation->id]),
            'can_delete' => $this->canDeleteAiValidation($actor, $validation),
        ]);
    }

    /**
     * Delete a saved AI validation result: the person who ran it, or an admin who can open the file.
     */
    public function deleteConfigurationAiValidation(Request $request, int $id, int $validationId)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $config = DB::table('configuration_files')
            ->leftJoin('system_register as sr', 'configuration_files.system_register_id', '=', 'sr.id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'sr.workspace_id as system_workspace_id', 'sr.user_id as system_user_id')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        if (!$this->canAccessConfigurationRecord($actor, $config)) {
            abort(403);
        }

        $validation = ConfigAiValidation::query()
            ->where('configuration_file_id', $config->id)
            ->findOrFail($validationId);

        if (!$this->canDeleteAiValidation($actor, $validation)) {
            return response()->json(['success' => false, 'message' => 'Only the person who ran this validation or an admin can delete it.'], 403);
        }

        $validation->delete();
        ActivityRecorder::record($actor->id, 'config.ai_validation_deleted', 'Deleted an AI validation of ' . $config->file_name, ActivityRecorder::SUCCESS, $request);

        return response()->json(['success' => true]);
    }

    private function canDeleteAiValidation(User $actor, ConfigAiValidation $validation): bool
    {
        return (int) $validation->user_id === (int) $actor->id
            || in_array((int) ($actor->rbac_id ?? 0), [100, 101], true);
    }

    /**
     * Download configuration file
     */
    public function downloadConfigurationFile($id)
    {
        /** @var User $actor */
        $actor = Auth::user();

        $config = DB::table('configuration_files')
            ->leftJoin('raw_data', 'configuration_files.id', '=', 'raw_data.file_id')
            ->leftJoin('system_register as sr', 'configuration_files.system_register_id', '=', 'sr.id')
            ->where('configuration_files.id', $id)
            ->select('configuration_files.*', 'raw_data.file_data as data', 'sr.workspace_id as system_workspace_id', 'sr.user_id as system_user_id')
            ->first();

        if (!$config) {
            abort(404, 'Configuration file not found');
        }

        if (!$this->canAccessConfigurationRecord($actor, $config)) {
            abort(403);
        }

        [$disk, $path] = $this->resolveDiskAndPathForRead($config->storage_disk ?? null, (string) ($config->file_location ?? ''));

        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            $content = Storage::disk($disk)->get($path);
            $mimeType = 'application/octet-stream';
            $fileName = $config->file_name ?: basename($path);

            if (!empty($config->version)) {
                $fileNameParts = pathinfo($fileName);
                $fileName = $fileNameParts['filename'] . '-' . $config->version . '.' . ($fileNameParts['extension'] ?? 'txt');
            }

            return response($content)
                ->header('Content-Type', $mimeType)
                ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
        }

        // Prepare download content
        $content = $config->data ?? 'No configuration data available';
        $fileName = $config->file_name ?: 'config_' . $id . '.txt';
        
        // Add version suffix if version exists
        if (!empty($config->version)) {
            $fileNameParts = pathinfo($fileName);
            $fileName = $fileNameParts['filename'] . '-' . $config->version . '.' . ($fileNameParts['extension'] ?? 'txt');
        }

        return response($content)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="' . $fileName . '"');
    }

    /**
     * Show the settings view
     */
    public function settings()
    {
        $apiKeys = PatToken::where('user_id', Auth::id())
            ->where('status', 'active')
            ->latest()
            ->get();

        $webSessions = WebSessions::forUser(Auth::user(), request()->session()->getId());
        $sessionsListed = WebSessions::isAvailable();

        $notificationWorkspaces = $this->notificationWorkspacesFor(Auth::user());

        return view('settings', compact('apiKeys', 'webSessions', 'sessionsListed', 'notificationWorkspaces'));
    }

    /**
     * Settings > Notifications: each workspace the user belongs to, the events it sends,
     * and what the user chose (or the workspace default when they have not chosen).
     */
    private function notificationWorkspacesFor(User $user)
    {
        $labels = NotificationEvents::forScope(NotificationEvents::SCOPE_WORKSPACE);
        $preferences = WorkspaceNotificationPreference::where('user_id', $user->id)->get()->keyBy('workspace_id');

        return $user->workspaces()->where('workspaces.status', 'active')->orderBy('workspaces.name')->get(['workspaces.id', 'workspaces.name'])
            ->map(function ($workspace) use ($labels, $preferences) {
                $settings = WorkspaceSettings::get((int) $workspace->id);
                $sent = $settings['events'] === null ? array_keys($labels) : (array) $settings['events'];
                $preference = $preferences->get($workspace->id);

                return (object) [
                    'id' => (int) $workspace->id,
                    'name' => $workspace->name,
                    'events' => array_intersect_key($labels, array_flip($sent)),
                    'chosen' => $preference ? (array) $preference->events : (array) $settings['member_email_default_events'],
                    'email_enabled' => $preference ? (bool) $preference->email_enabled : true,
                    'customised' => $preference !== null,
                ];
            });
    }

    public function updateNotificationPreferences(Request $request): RedirectResponse
    {
        /** @var User $actor */
        $actor = Auth::user();

        $validated = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'max:64'],
        ]);

        $workspace = $this->notificationWorkspacesFor($actor)->firstWhere('id', (int) $validated['workspace_id']);
        if ($workspace === null) {
            abort(403);
        }

        WorkspaceNotificationPreference::updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $actor->id],
            [
                'events' => array_values(array_intersect($validated['events'] ?? [], array_keys($workspace->events))),
                'email_enabled' => $request->boolean('email_enabled'),
            ]
        );

        return redirect()->to(route('settings') . '#notifications')->with('success', 'Notification preferences saved for ' . $workspace->name . '.');
    }

    /**
     * Update user settings
     */
    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'dob' => 'required|date|before:today',
        ]);

        $updateData = [
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'dob' => $validated['dob'],
        ];

        /** @var User $user */
        $user = Auth::user();
        $user->update($updateData);
        ActivityRecorder::record($user->id, 'profile.updated', 'Updated profile', ActivityRecorder::SUCCESS, $request);

        return redirect()->back()->with('success', 'Settings updated successfully');
    }

    /**
     * Create a new API key from settings page.
     */
    public function createApiKey(Request $request)
    {
        if (!License::isActive()) {
            return response()->json(['success' => false, 'message' => License::REQUIRED_MESSAGE], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'expiration_date' => 'nullable|date|after:today',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $plainToken = PatToken::generateCustomToken();
        $expiresAt = $validated['expiration_date'] 
            ? Carbon::parse($validated['expiration_date'])->endOfDay()
            : Carbon::create(2099, 12, 31, 23, 59, 59);

        $token = PatToken::create([
            'user_id' => $user->id,
            'tokenable_type' => User::class,
            'tokenable_id' => $user->id,
            'name' => $validated['name'],
            'token' => hash('sha256', $plainToken),
            'token_encrypted' => Crypt::encryptString($plainToken),
            'abilities' => ['*'],
            'expires_at' => $expiresAt,
            'status' => 'active',
        ]);

        ActivityRecorder::record($user->id, 'apikey.created', 'Created API key "' . $token->name . '"', ActivityRecorder::SUCCESS, $request);
        AccountAlerts::apiKey($user, 'created', (string) $token->name);

        return response()->json([
            'success' => true,
            'token' => $plainToken,
            'name' => $token->name,
            'key_id' => $token->id,
            'expires_at' => $expiresAt->format('F j, Y'),
        ]);
    }

    /**
     * View a specific API key (requires password confirmation)
     */
    public function viewApiKey(Request $request)
    {
        $validated = $request->validate([
            'password' => 'nullable|string',
            'pin' => 'nullable|string',
            'key_id' => 'required|integer',
        ]);

        // Require at least one authentication method
        if (empty($validated['password']) && empty($validated['pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Password or PIN is required',
            ], 400);
        }

        /** @var User $user */
        $user = Auth::user();

        $authenticated = false;

        // Verify password if provided
        if (!empty($validated['password'])) {
            $userPasswordHash = $user->password_hash ?? $user->password;
            if ($userPasswordHash && Hash::check($validated['password'], $userPasswordHash)) {
                $authenticated = true;
            }
        }

        // Verify PIN if provided and password didn't authenticate
        if (!$authenticated && !empty($validated['pin'])) {
            $userPinHash = $user->pin;
            if ($userPinHash && Hash::check($validated['pin'], $userPinHash)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            ActivityRecorder::record($user->id, $request->routeIs('settings.api-keys.revoke') ? 'apikey.revoked' : 'apikey.viewed', 'Wrong password or PIN while confirming an API key action', ActivityRecorder::FAILURE, $request);

            return response()->json([
                'success' => false,
                'message' => 'Invalid password or PIN',
            ], 401);
        }

        $token = PatToken::where('id', $validated['key_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'API key not found',
            ], 404);
        }

        $plainToken = null;
        if (!empty($token->token_encrypted)) {
            try {
                $plainToken = Crypt::decryptString($token->token_encrypted);
            } catch (\Throwable $e) {
                $plainToken = null;
            }
        }

        if ($plainToken === null) {
            return response()->json([
                'success' => false,
                'message' => 'This key was created before secure display was enabled. Please create a new key to view full token value.',
            ], 422);
        }

        ActivityRecorder::record($user->id, 'apikey.viewed', 'Viewed API key "' . $token->name . '"', ActivityRecorder::SUCCESS, $request);

        return response()->json([
            'success' => true,
            'token' => $plainToken,
            'name' => $token->name,
        ]);
    }

    /**
     * Revoke an API key (requires password confirmation)
     */
    public function revokeApiKey(Request $request)
    {
        $validated = $request->validate([
            'password' => 'nullable|string',
            'pin' => 'nullable|string',
            'key_id' => 'required|integer',
        ]);

        // Require at least one authentication method
        if (empty($validated['password']) && empty($validated['pin'])) {
            return response()->json([
                'success' => false,
                'message' => 'Password or PIN is required',
            ], 400);
        }

        /** @var User $user */
        $user = Auth::user();

        $authenticated = false;

        // Verify password if provided
        if (!empty($validated['password'])) {
            $userPasswordHash = $user->password_hash ?? $user->password;
            if ($userPasswordHash && Hash::check($validated['password'], $userPasswordHash)) {
                $authenticated = true;
            }
        }

        // Verify PIN if provided and password didn't authenticate
        if (!$authenticated && !empty($validated['pin'])) {
            $userPinHash = $user->pin;
            if ($userPinHash && Hash::check($validated['pin'], $userPinHash)) {
                $authenticated = true;
            }
        }

        if (!$authenticated) {
            ActivityRecorder::record($user->id, $request->routeIs('settings.api-keys.revoke') ? 'apikey.revoked' : 'apikey.viewed', 'Wrong password or PIN while confirming an API key action', ActivityRecorder::FAILURE, $request);

            return response()->json([
                'success' => false,
                'message' => 'Invalid password or PIN',
            ], 401);
        }

        $token = PatToken::where('id', $validated['key_id'])
            ->where('user_id', $user->id)
            ->first();

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'API key not found',
            ], 404);
        }

        $token->update(['status' => 'revoked']);
        ActivityRecorder::record($user->id, 'apikey.revoked', 'Revoked API key "' . $token->name . '"', ActivityRecorder::SUCCESS, $request);
        AccountAlerts::apiKey($user, 'revoked', (string) $token->name);

        return response()->json([
            'success' => true,
            'message' => 'API key revoked successfully',
        ]);
    }

    /**
     * Sign out one of the user's other browser sessions.
     */
    public function endSession(Request $request, string $session): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $back = redirect()->to(route('settings') . '#security');

        if (!WebSessions::end($user, $session, $request)) {
            return $back->withErrors(['session' => 'That session was not found. It may have already ended.']);
        }

        ActivityRecorder::record($user->id, 'session.ended', 'Signed out another session', ActivityRecorder::SUCCESS, $request);

        return $back->with('success', 'Session signed out.');
    }

    /**
     * Sign out every other browser session. Needs the password or PIN.
     */
    public function endOtherSessions(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'password' => 'nullable|string',
            'pin' => 'nullable|string',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $back = redirect()->to(route('settings') . '#security');

        $confirmed = (!empty($validated['password']) && Hash::check($validated['password'], (string) ($user->password_hash ?? $user->password)))
            || (!empty($validated['pin']) && !empty($user->pin) && Hash::check($validated['pin'], (string) $user->pin));

        if (!$confirmed) {
            ActivityRecorder::record($user->id, 'session.ended_others', 'Wrong password or PIN while signing out other sessions', ActivityRecorder::FAILURE, $request);

            return $back->withErrors(['session' => 'Enter your current password or PIN to sign out other sessions.']);
        }

        $ended = WebSessions::endOthers($user, $request);
        ActivityRecorder::record($user->id, 'session.ended_others', 'Signed out all other sessions (' . $ended . ')', ActivityRecorder::SUCCESS, $request);

        return $back->with('success', $ended === 0 ? 'No other sessions were active.' : 'Signed out ' . $ended . ' other ' . ($ended === 1 ? 'session.' : 'sessions.'));
    }

    /**
     * Save the Preferences tab of /settings.
     */
    public function updatePreferences(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'timezone' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if ($value !== null && $value !== '' && !UserPreferences::isValidTimezone($value)) {
                    $fail('Choose a time zone from the list.');
                }
            }],
            'date_format' => ['required', \Illuminate\Validation\Rule::in(array_keys(UserPreferences::DATE_FORMATS))],
            'per_page' => ['required', 'integer', \Illuminate\Validation\Rule::in(UserPreferences::PAGE_SIZES)],
            'alerts' => ['nullable', 'array'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        $alerts = [];
        foreach (array_keys(UserPreferences::ALERTS) as $alert) {
            $alerts[$alert] = $request->boolean('alerts.' . $alert);
        }

        UserPreferences::save($user, [
            'timezone' => ($validated['timezone'] ?? '') !== '' ? $validated['timezone'] : null,
            'date_format' => $validated['date_format'],
            'per_page' => (int) $validated['per_page'],
            'alerts' => $alerts,
        ]);
        ActivityRecorder::record($user->id, 'preferences.updated', 'Updated preferences', ActivityRecorder::SUCCESS, $request);

        return redirect()->to(route('settings') . '#preferences')->with('success', 'Preferences saved.');
    }

    /**
     * Saves the browser's time zone the first time, when none is set yet.
     */
    public function detectTimezone(Request $request)
    {
        /** @var User $user */
        $user = Auth::user();
        $timezone = (string) $request->input('timezone', '');

        if (UserPreferences::get($user, 'timezone') === null && UserPreferences::isValidTimezone($timezone)) {
            UserPreferences::save($user, ['timezone' => $timezone]);
        }

        return response()->noContent();
    }

    /**
     * Update user password
     */
    public function updatePassword(Request $request)
    {
        $validated = $request->validate([
            'current_password' => 'required|current_password',
            'password' => 'required|string|min:8|confirmed',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->password = Hash::make($validated['password']);
        $user->save();
        ActivityRecorder::record($user->id, 'password.changed', 'Changed password', ActivityRecorder::SUCCESS, $request);
        AccountAlerts::passwordChanged($user, $request);

        $ended = WebSessions::endOthers($user, $request);

        return redirect()->back()->with('success', 'Password updated successfully' . ($ended > 0 ? '. Signed out ' . $ended . ' other ' . ($ended === 1 ? 'session' : 'sessions') . '.' : '.'));
    }

    /**
     * Show the profile view
     */
    public function profile()
    {
        /** @var User $user */
        $user = Auth::user();

        $overview = \App\Support\ProfileOverview::for($user);
        $apiKeyLastUsed = PatToken::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->max('last_used_at');
        $session = request()->session();

        return view('profile', [
            'security' => [
                'password_changed_at' => $user->password_changed_at,
                // No chosen password yet and signed in through SSO: show the provider instead.
                'sso_provider' => $user->password_changed_at === null && $session->get('auth_method') === 'sso'
                    ? ucfirst((string) $session->get('auth_sso_provider', 'SSO'))
                    : null,
                'last_login_at' => $user->last_login_at,
                'last_login_ip' => $user->last_login_ip,
                'previous_login_at' => $user->previous_login_at,
                'previous_login_ip' => $user->previous_login_ip,
                'api_keys' => $overview['mine']['api_keys'],
                'api_key_last_used' => $apiKeyLastUsed ? Carbon::parse($apiKeyLastUsed) : null,
                'sessions' => WebSessions::isAvailable() ? WebSessions::count($user) : null,
            ],
            'overview' => $overview,
            'roleLabel' => \App\Support\ProfileOverview::roleLabel($user),
            'recentActivity' => \App\Support\ActivityFeed::latest((int) $user->id, 10),
        ]);
    }

    /**
     * The signed-in user's full activity history, filterable by type.
     */
    public function profileActivity(Request $request)
    {
        $type = $request->query('type');
        $type = is_string($type) && isset(\App\Support\ActivityFeed::TYPES[$type]) ? $type : null;

        return view('profile-activity', [
            'activityPage' => \App\Support\ActivityFeed::paginate((int) Auth::id(), $type),
            'activityType' => $type,
        ]);
    }

    /**
     * Save mandatory profile setup fields.
     */
    public function updateProfileSetup(Request $request)
    {
        $validated = $request->validate([
            'dob' => 'required|date|before:today',
            'pin' => 'required|digits:5|confirmed',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $user->dob = $validated['dob'];
        $user->pin = Hash::make($validated['pin']);
        $user->save();
        ActivityRecorder::record($user->id, 'profile.setup', 'Set date of birth and PIN', ActivityRecorder::SUCCESS, $request);

        return redirect()->route('dashboard')->with('success', 'Profile setup completed successfully.');
    }

    /**
     * Reset user PIN from settings.
     */
    public function resetPin(Request $request)
    {
        $validated = $request->validate([
            'pin' => 'required|digits:5|confirmed',
            'current_password' => 'nullable|string',
        ]);

        /** @var User $user */
        $user = Auth::user();
        $isSsoSession = (string) $request->session()->get('auth_method', 'password') === 'sso';

        $userPasswordHash = $user->password_hash ?? $user->password;
        $passwordVerified = false;
        if (!empty($validated['current_password']) && !empty($userPasswordHash)) {
            $passwordVerified = Hash::check($validated['current_password'], $userPasswordHash);
        }

        $verifiedAtTimestamp = (int) $request->session()->get('sso_pin_verified_at', 0);
        $ssoVerifiedRecently = $verifiedAtTimestamp > 0
            && Carbon::createFromTimestamp($verifiedAtTimestamp)->greaterThanOrEqualTo(now()->subMinutes(5));

        if ($isSsoSession) {
            if (!$passwordVerified && !$ssoVerifiedRecently) {
                return redirect()->back()->withErrors([
                    'current_password' => 'Please verify using your password or re-authenticate with SSO to reset PIN.',
                ]);
            }
        } else {
            if (!$passwordVerified) {
                return redirect()->back()->withErrors([
                    'current_password' => 'Current password is required to reset PIN.',
                ]);
            }
        }

        $user->pin = Hash::make($validated['pin']);
        $user->save();
        $request->session()->forget('sso_pin_verified_at');
        ActivityRecorder::record($user->id, 'pin.reset', 'Reset PIN', ActivityRecorder::SUCCESS, $request);

        return redirect()->back()->with('success', 'PIN reset successfully.');
    }

    /**
     * Begin SSO PIN reset flow by storing pending PIN in session.
     */
    public function beginSsoPinReset(Request $request)
    {
        $validated = $request->validate([
            'provider' => 'required|string',
            'pin' => 'required|digits:5|confirmed',
        ]);

        $request->session()->put('pending_pin_reset_pin', $validated['pin']);

        return redirect()->route('auth.sso.redirect', [
            'provider' => strtolower(trim($validated['provider'])),
            'intent' => 'pin_reset',
        ]);
    }

    /**
     * Show the products view
     */
    public function products()
    {
        return view('products');
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

    private function resolveDiskAndPathForRead(?string $storageDisk, string $storedLocation): array
    {
        $legacy = $this->resolveLegacyDiskAndPath($storageDisk, $storedLocation);
        $path = ltrim((string) ($legacy[1] ?? ''), '/');
        $activeDisk = $this->resolveActiveStorageDisk();

        if ($path === '') {
            return [$activeDisk, $path];
        }

        if (Storage::disk($activeDisk)->exists($path)) {
            return [$activeDisk, $path];
        }

        return [$legacy[0], $path];
    }

    private function resolveActiveStorageDisk(): string
    {
        return S3Settings::activeDisk();
    }

    private function resolveLegacyDiskAndPath(?string $storageDisk, string $storedLocation): array
    {
        $explicitDisk = strtolower(trim((string) $storageDisk));
        if ($explicitDisk !== '') {
            return [$explicitDisk, ltrim($storedLocation, '/')];
        }

        if (preg_match('/^([a-z0-9_-]+):\/\/(.+)$/i', $storedLocation, $matches)) {
            return [strtolower($matches[1]), $matches[2]];
        }

        return ['local', ltrim($storedLocation, '/')];
    }

    private function applyDisplayVersionFallback($versions)
    {
        $counts = $versions
            ->map(function ($item) {
                $label = strtolower(trim((string) ($item->version ?? '')));

                return $label;
            })
            ->filter(fn ($label) => $label !== '')
            ->countBy();

        $sequenceMap = $versions
            ->sortBy(function ($item) {
                return [$item->created_at ?? null, $item->id ?? 0];
            })
            ->values()
            ->mapWithKeys(function ($item, $index) {
                return [(int) $item->id => 'v' . ($index + 1)];
            });

        return $versions->map(function ($item) use ($counts, $sequenceMap) {
            $original = trim((string) ($item->version ?? ''));
            $normalized = strtolower($original);
            $hasDuplicate = $normalized !== '' && (($counts[$normalized] ?? 0) > 1);
            $needsFallback = $original === '' || $hasDuplicate;

            $item->display_version = $needsFallback
                ? ($sequenceMap[(int) $item->id] ?? 'N/A')
                : $original;

            return $item;
        });
    }
}
