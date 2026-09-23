<?php

namespace App\Http\Controllers;

use App\Models\SystemRegister;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
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

        $isSuperAdmin = (int) ($actor->rbac_id ?? 0) === 100;
        $alertsBaseQuery = DB::table('activity_logs')
            ->where('status_code', '>=', 400);

        if (!$isSuperAdmin) {
            $alertsBaseQuery->where('user_id', (int) $actor->id);
        }

        $totalPotentialVulnerabilities = (clone $alertsBaseQuery)->count('id');
        $currentWeekPotentialVulnerabilities = (clone $alertsBaseQuery)
            ->whereBetween('created_at', [$currentWeekStart, $now])
            ->count('id');
        $previousWeekPotentialVulnerabilities = (clone $alertsBaseQuery)
            ->whereBetween('created_at', [$previousWeekStart, $previousWeekEnd])
            ->count('id');

        $servicesChange = $this->calculateWeeklyChange($currentWeekServicesMonitored, $previousWeekServicesMonitored);
        $vulnerabilitiesChange = $this->calculateWeeklyChange($currentWeekPotentialVulnerabilities, $previousWeekPotentialVulnerabilities);

        return view('dashboard', [
            'totalConfigBackups' => $totalConfigBackups,
            'totalSystemsRegistered' => $totalSystemsRegistered,
            'totalServicesMonitored' => $totalServicesMonitored,
            'totalPotentialVulnerabilities' => $totalPotentialVulnerabilities,
            'configChange' => $configChange,
            'systemsChange' => $systemsChange,
            'servicesChange' => $servicesChange,
            'vulnerabilitiesChange' => $vulnerabilitiesChange,
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

        // Subquery to get the latest version for each service
        $latestVersionsSubquery = DB::table('configuration_files')
            ->select('service_name', DB::raw('MAX(id) as latest_id'))
            ->groupBy('service_name');

        $query = DB::table('configuration_files as cf')
            ->joinSub($latestVersionsSubquery, 'latest', function ($join) {
                $join->on('cf.service_name', '=', 'latest.service_name')
                     ->on('cf.id', '=', 'latest.latest_id');
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
                DB::raw('(SELECT COUNT(*) FROM configuration_files WHERE service_name = cf.service_name) as version_count')
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
            ->limit(50)
            ->get();

        return view('configuration-backups', compact('items'));
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
            $query->where('system_name', 'like', '%' . $request->name . '%');
        }

        if ($request->filled('ip')) {
            $query->where('ip_address', 'like', '%' . $request->ip . '%');
        }

        if ($request->filled('tags')) {
            $query->where('tags', 'like', '%' . $request->tags . '%');
        }

        if ($request->filled('os')) {
            $query->where('os_type', 'like', '%' . $request->os . '%');
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('hash')) {
            $query->where('validation_hash', 'like', '%' . $request->hash . '%');
        }

        $items = $query->orderByDesc('created_at')
            ->limit(50)
            ->get();

        return view('systems-registered', compact('items'));
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

        return view('system-services', compact('system', 'services'));
    }

    public function liveServiceMonitoring()
    {
        return view('live-service-monitoring');
    }

    public function vulnerabilitiesIdentified()
    {
        return view('vulnerabilities-identified');
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
                        $ownUnassigned->where('cf.user_id', (int) $actor->id)
                            ->where(function ($unassigned) {
                                $unassigned->whereNull('cf.system_register_id')
                                    ->orWhere('cf.system_register_id', 0);
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

        // Return view with configuration data
        return view('view-configuration', compact('config'));
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

        return view('settings', compact('apiKeys'));
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

        return redirect()->back()->with('success', 'Settings updated successfully');
    }

    /**
     * Create a new API key from settings page.
     */
    public function createApiKey(Request $request)
    {
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

        return response()->json([
            'success' => true,
            'message' => 'API key revoked successfully',
        ]);
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

        return redirect()->back()->with('success', 'Password updated successfully');
    }

    /**
     * Show the profile view
     */
    public function profile()
    {
        return view('profile');
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

        if (empty($workspaceIds)) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->whereIn($systemAlias . '.workspace_id', $workspaceIds);
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

            $scoped->orWhere(function ($ownUnassigned) use ($configAlias, $actor) {
                $ownUnassigned->where($configAlias . '.user_id', (int) $actor->id)
                    ->where(function ($unassigned) use ($configAlias) {
                        $unassigned->whereNull($configAlias . '.system_register_id')
                            ->orWhere($configAlias . '.system_register_id', 0);
                    });
            });
        });
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
        $s3Enabled = filter_var((string) env('S3_ENABLED', 'false'), FILTER_VALIDATE_BOOL);
        if (!$s3Enabled) {
            return 'local';
        }

        $key = trim((string) config('filesystems.disks.s3.key', env('AWS_ACCESS_KEY_ID', '')));
        $secret = trim((string) config('filesystems.disks.s3.secret', $this->resolveAwsSecretFromEnv()));
        $region = trim((string) config('filesystems.disks.s3.region', env('AWS_DEFAULT_REGION', '')));
        $bucket = trim((string) config('filesystems.disks.s3.bucket', env('AWS_BUCKET', '')));

        if ($key === '' || $secret === '' || $region === '' || $bucket === '') {
            return 'local';
        }

        Config::set('filesystems.disks.s3.key', $key);
        Config::set('filesystems.disks.s3.secret', $secret);
        Config::set('filesystems.disks.s3.region', $region);
        Config::set('filesystems.disks.s3.bucket', $bucket);

        return 's3';
    }

    private function resolveAwsSecretFromEnv(): string
    {
        $raw = trim((string) env('AWS_SECRET_ACCESS_KEY', ''));
        if ($raw === '') {
            return '';
        }

        if (!str_starts_with($raw, 'ENC:')) {
            return $raw;
        }

        try {
            return trim(Crypt::decryptString(substr($raw, 4)));
        } catch (\Throwable) {
            return '';
        }
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
