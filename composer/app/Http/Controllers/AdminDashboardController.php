<?php

namespace App\Http\Controllers;

use App\Support\ActivityRecorder;
use App\Support\DomainSettings;
use App\Support\License;
use App\Models\AdminSetting;
use App\Models\ConfigurationFile;
use App\Models\ContactSubmission;
use App\Models\Organization;
use App\Models\Service;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Services\BackupService;
use App\Support\BackupSettings;
use App\Support\McpControl;
use App\Support\S3Settings;
use App\Support\SiteProfile;
use App\Support\SsoProviders;
use App\Support\SsoSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminDashboardController extends Controller
{
    public function index(): View
    {
        $actor = Auth::user();
        $visibleWorkspaceIds = $this->isAdminOnly($actor)
            ? $this->visibleWorkspaceIdsForAdmin($actor)
            : [];

        // Workspace picked in the top bar; null means "All" (the super admin default).
        $selectedWorkspaceId = request()->session()->has('selected_workspace_id')
            ? (int) request()->session()->get('selected_workspace_id')
            : null;
        // Super admin and admins both get "All" (null); a picked workspace narrows every card.
        $isSuperAdmin = in_array((int) ($actor->rbac_id ?? 0), [100, 101], true);
        $superAdminWorkspace = function ($query, string $column) use ($isSuperAdmin, $selectedWorkspaceId) {
            if (!$isSuperAdmin || $selectedWorkspaceId === null) {
                return;
            }

            if ($selectedWorkspaceId === 0) {
                $query->where(fn ($unassigned) => $unassigned->whereNull($column)->orWhere($column, 0));

                return;
            }

            $query->where($column, $selectedWorkspaceId);
        };

        $totalUsers = User::query()
            ->where(function ($query) {
                $query->whereNull('rbac_id')
                    ->orWhere('rbac_id', '!=', 100);
            })
            ->when($this->isAdminOnly($actor), function ($query) use ($visibleWorkspaceIds) {
                if (empty($visibleWorkspaceIds)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereExists(function ($workspaceQuery) use ($visibleWorkspaceIds) {
                    $workspaceQuery->select(DB::raw(1))
                        ->from('workspace_user as wu')
                        ->whereColumn('wu.user_id', 'users.id')
                        ->whereIn('wu.workspace_id', $visibleWorkspaceIds);
                });
            })
            ->count();

        $totalSystems = SystemRegister::query()
            ->when($isSuperAdmin && $selectedWorkspaceId !== null, fn ($query) => $superAdminWorkspace($query, 'workspace_id'))
            ->when($this->isAdminOnly($actor), function ($query) use ($visibleWorkspaceIds) {
                if (empty($visibleWorkspaceIds)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('workspace_id', $visibleWorkspaceIds);
            })
            ->count();

        $totalServices = Service::query()
            ->join('system_register as sr', 'sr.id', '=', 'services.system_id')
            ->when($isSuperAdmin && $selectedWorkspaceId !== null, fn ($query) => $superAdminWorkspace($query, 'sr.workspace_id'))
            ->when($this->isAdminOnly($actor), function ($query) use ($visibleWorkspaceIds) {
                if (empty($visibleWorkspaceIds)) {
                    $query->whereRaw('1 = 0');

                    return;
                }

                $query->whereIn('sr.workspace_id', $visibleWorkspaceIds);
            })
            ->distinct('services.service_id')
            ->count('services.service_id');

        $totalConfigFiles = ConfigurationFile::query()
            ->leftJoin('system_register as sr', 'sr.id', '=', 'configuration_files.system_register_id')
            ->when($isSuperAdmin && $selectedWorkspaceId !== null, fn ($query) => $superAdminWorkspace($query, 'sr.workspace_id'))
            ->when($this->isAdminOnly($actor), function ($query) use ($visibleWorkspaceIds, $actor) {
                $query->where(function ($scoped) use ($visibleWorkspaceIds, $actor) {
                    if (!empty($visibleWorkspaceIds)) {
                        $scoped->whereIn('sr.workspace_id', $visibleWorkspaceIds);
                    }

                    $scoped->orWhere(function ($ownUnassigned) use ($actor) {
                        $ownUnassigned->where('configuration_files.user_id', (int) $actor->id)
                            ->where(function ($unassigned) {
                                $unassigned->whereNull('configuration_files.system_register_id')
                                    ->orWhere('configuration_files.system_register_id', 0);
                            });
                    });
                });
            })
            ->count('configuration_files.id');

        $dashboard = app(DashboardController::class);
        $vulnerabilityStats = $dashboard->vulnerabilityStats($actor, $selectedWorkspaceId);
        $validationTrend = $dashboard->validationTrend($actor, $selectedWorkspaceId);

        return view('admin.dashboard', compact(
            'vulnerabilityStats',
            'validationTrend',
            'totalUsers',
            'totalSystems',
            'totalServices',
            'totalConfigFiles'
        ));
    }

    public function usersIndex(): View
    {
        $actor = Auth::user();
        $visibleWorkspaceIds = $this->visibleWorkspaceIdsForAdmin($actor);

        $usersQuery = User::query()
            ->leftJoin('system_register', 'system_register.user_id', '=', 'users.id')
            ->leftJoin('services', 'services.user_id', '=', 'users.id')
            ->leftJoin('configuration_files', 'configuration_files.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.status',
                'users.created_at',
                DB::raw('COUNT(DISTINCT system_register.id) as system_count'),
                DB::raw('COUNT(DISTINCT services.service_id) as service_count'),
                DB::raw('COUNT(DISTINCT configuration_files.id) as configuration_count')
            );

        if ($this->isAdminOnly($actor)) {
            // The role inside a workspace comes from workspace_user.is_admin, so a manager of
            // another workspace is listed here when they are a plain member of one we manage.
            $managedWorkspaceIds = $this->managedWorkspaceIds($actor);
            $usersQuery->where('users.id', '!=', (int) $actor->id)
                ->where(function ($query) {
                    $query->whereNull('users.rbac_id')
                        ->orWhere('users.rbac_id', '!=', 100);
                });

            if (empty($managedWorkspaceIds)) {
                $usersQuery->whereRaw('1 = 0');
            } else {
                $usersQuery->whereExists(function ($query) use ($managedWorkspaceIds) {
                    $query->select(DB::raw(1))
                        ->from('workspace_user as wu')
                        ->whereColumn('wu.user_id', 'users.id')
                        ->whereIn('wu.workspace_id', $managedWorkspaceIds)
                        ->where('wu.is_admin', false);
                });
            }
        } else {
            $usersQuery->where(function ($query) {
                $query->whereNull('users.rbac_id')
                    ->orWhereNotIn('users.rbac_id', [100, 101]);
            });
        }

        $users = $usersQuery
            ->groupBy('users.id', 'users.name', 'users.email', 'users.status', 'users.created_at')
            ->orderByDesc('users.created_at')
            ->paginate(25, ['*'], 'users_page');

        $adminUsersQuery = User::query()
            ->leftJoin('system_register', 'system_register.user_id', '=', 'users.id')
            ->leftJoin('services', 'services.user_id', '=', 'users.id')
            ->leftJoin('configuration_files', 'configuration_files.user_id', '=', 'users.id')
            ->select(
                'users.id',
                'users.name',
                'users.email',
                'users.status',
                'users.created_at',
                DB::raw('COUNT(DISTINCT system_register.id) as system_count'),
                DB::raw('COUNT(DISTINCT services.service_id) as service_count'),
                DB::raw('COUNT(DISTINCT configuration_files.id) as configuration_count')
            )
            ->where('users.rbac_id', 101);

        if ($this->isAdminOnly($actor)) {
            if (empty($visibleWorkspaceIds)) {
                $adminUsersQuery->whereRaw('1 = 0');
            } else {
                $adminUsersQuery->whereExists(function ($query) use ($visibleWorkspaceIds) {
                    $query->select(DB::raw(1))
                        ->from('workspace_user as wu')
                        ->whereColumn('wu.user_id', 'users.id')
                        ->whereIn('wu.workspace_id', $visibleWorkspaceIds);
                });
            }
        }

        $adminUsers = $adminUsersQuery
            ->groupBy('users.id', 'users.name', 'users.email', 'users.status', 'users.created_at')
            ->orderByDesc('users.created_at')
            ->paginate(25, ['*'], 'admin_page');

        return view('admin.users', compact('users', 'adminUsers'));
    }

    public function createUser(Request $request): RedirectResponse
    {
        if (!License::isActive()) {
            return redirect()->back()->withInput()->withErrors(['licence' => License::REQUIRED_MESSAGE]);
        }

        $actor = Auth::user();
        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', Rule::in(['user', 'admin'])],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $rbacId = $validated['role'] === 'admin' ? 101 : 102;

        $workspaceId = !empty($validated['workspace_id'])
            ? (int) $validated['workspace_id']
            : (int) $request->session()->get('selected_workspace_id', 0);

        $workspace = null;
        if ($workspaceId > 0) {
            $workspace = Workspace::find($workspaceId);

            if ($workspace === null || !$this->canManageWorkspaceUsers($actor, $workspace)) {
                return redirect()->back()
                    ->withInput()
                    ->withErrors([
                        'workspace_id' => 'You are not allowed to assign users to the selected workspace.',
                    ]);
            }
        }

        $newUser = User::create([
            'name' => $validated['username'],
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'rbac_id' => $rbacId,
            'org_id' => (int) ($actor->org_id ?? 200),
        ]);

        ActivityRecorder::record($newUser->id, 'account.created_by_admin', 'Account created by ' . ($actor->name ?: 'an admin'), ActivityRecorder::SUCCESS, $request);

        if ($workspace !== null) {
            $newUser->workspaces()->syncWithoutDetaching([
                $workspaceId => ['is_admin' => $rbacId === 101],
            ]);
        }

        return redirect()->route('admin.users')->with('success', 'User registered successfully.');
    }

    public function userProfile(User $user): View
    {
        $actor = Auth::user();
        if (!$this->canViewTargetUser($actor, $user)) {
            abort(403);
        }

        $stats = [
            'systems' => SystemRegister::where('user_id', $user->id)->count(),
            'services' => Service::where('user_id', $user->id)->count(),
            'configurations' => ConfigurationFile::where('user_id', $user->id)->count(),
        ];

        $assignedWorkspaces = $user->workspaces()
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name', 'workspace_user.is_admin']);

        $canEditUserProfile = $this->canEditTargetUser($actor, $user);

        $recentActivity = \App\Support\ActivityFeed::latest((int) $user->id, 20);

        return view('admin.user-profile', compact('user', 'stats', 'assignedWorkspaces', 'canEditUserProfile', 'recentActivity'));
    }

    /**
     * Readable list of the fields an admin is about to change, e.g. "role to Admin".
     *
     * @return list<string>
     */
    private function describeAdminChanges(User $user): array
    {
        $changes = [];

        if ($user->isDirty('rbac_id')) {
            $changes[] = 'role to ' . ((int) $user->rbac_id === 101 ? 'Admin' : 'User');
        }
        if ($user->isDirty('status')) {
            $changes[] = 'status to ' . $user->status;
        }
        if ($user->isDirty('email')) {
            $changes[] = 'email to ' . $user->email;
        }
        if ($user->isDirty(['name', 'first_name', 'last_name'])) {
            $changes[] = 'name';
        }

        return $changes;
    }

    public function updateUser(Request $request, User $user): RedirectResponse
    {
        $actor = Auth::user();
        if (!$this->canEditTargetUser($actor, $user)) {
            return redirect()
                ->route('admin.users.profile', ['user' => $user->id])
                ->withErrors([
                    'authorization' => 'You are not allowed to modify this profile.',
                ]);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'role' => ['required', Rule::in(['user', 'admin'])],
        ]);

        $user->name = $validated['username'];
        $user->first_name = $validated['first_name'] ?? null;
        $user->last_name = $validated['last_name'] ?? null;
        $user->email = $validated['email'];
        $user->status = $validated['status'];
        $user->rbac_id = $validated['role'] === 'admin' ? 101 : 102;
        $changes = $this->describeAdminChanges($user);
        $user->save();

        if ($changes !== []) {
            ActivityRecorder::record($user->id, 'account.changed_by_admin', ($actor->name ?: 'An admin') . ' changed your ' . implode(', ', $changes), ActivityRecorder::SUCCESS, $request);
        }

        return redirect()
            ->route('admin.users.profile', ['user' => $user->id])
            ->with('success', 'User profile updated successfully.');
    }

    public function userDashboard(User $user): View
    {
        $actor = Auth::user();
        if (!$this->canViewTargetUser($actor, $user)) {
            abort(403);
        }

        $sharedWorkspaceIds = $this->sharedWorkspaceIdsWithTarget($actor, $user);

        $systemsQuery = SystemRegister::query()
            ->where('user_id', $user->id);

        if ($this->isAdminOnly($actor)) {
            if (empty($sharedWorkspaceIds)) {
                $systemsQuery->whereRaw('1 = 0');
            } else {
                $systemsQuery->whereIn('workspace_id', $sharedWorkspaceIds);
            }
        }

        $systems = $systemsQuery
            ->orderByDesc('created_at')
            ->get();

        $serviceCountsBySystem = Service::query()
            ->where('user_id', $user->id)
            ->select('system_id', DB::raw('COUNT(*) as total'))
            ->groupBy('system_id')
            ->pluck('total', 'system_id');

        $systems->each(function (SystemRegister $system) use ($serviceCountsBySystem) {
            $system->service_count = (int) ($serviceCountsBySystem[$system->id] ?? 0);
        });

        $stats = [
            'systems' => SystemRegister::where('user_id', $user->id)->count(),
            'services' => Service::where('user_id', $user->id)->count(),
            'configurations' => ConfigurationFile::where('user_id', $user->id)->count(),
        ];

        return view('admin.user-show', compact('user', 'systems', 'stats'));
    }

    public function userSystemServices(User $user, int $systemId): View
    {
        $actor = Auth::user();
        if (!$this->canViewTargetUser($actor, $user)) {
            abort(403);
        }

        $sharedWorkspaceIds = $this->sharedWorkspaceIdsWithTarget($actor, $user);

        $systemQuery = SystemRegister::query()
            ->where('id', $systemId)
            ->where('user_id', $user->id);

        if ($this->isAdminOnly($actor)) {
            if (empty($sharedWorkspaceIds)) {
                abort(403);
            }

            $systemQuery->whereIn('workspace_id', $sharedWorkspaceIds);
        }

        $system = $systemQuery->firstOrFail();

        $services = Service::query()
            ->where('services.user_id', $user->id)
            ->where('services.system_id', $systemId)
            ->leftJoin('configuration_files', 'services.service_id', '=', 'configuration_files.service_id')
            ->select(
                'services.service_id',
                'services.service_name',
                'services.system_id',
                'services.status',
                'services.created_at',
                DB::raw('COUNT(configuration_files.id) as config_count'),
                DB::raw('MAX(configuration_files.version) as latest_version')
            )
            ->groupBy(
                'services.service_id',
                'services.service_name',
                'services.system_id',
                'services.status',
                'services.created_at'
            )
            ->orderByDesc('services.created_at')
            ->get();

        return view('admin.user-services', compact('user', 'system', 'services'));
    }

    public function userServiceVersions(User $user, int $serviceId): View
    {
        $actor = Auth::user();
        if (!$this->canViewTargetUser($actor, $user)) {
            abort(403);
        }

        $sharedWorkspaceIds = $this->sharedWorkspaceIdsWithTarget($actor, $user);

        $service = Service::query()
            ->where('service_id', $serviceId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($this->isAdminOnly($actor)) {
            if (empty($sharedWorkspaceIds)) {
                abort(403);
            }

            $allowedSystem = SystemRegister::query()
                ->where('id', $service->system_id)
                ->whereIn('workspace_id', $sharedWorkspaceIds)
                ->exists();

            if (!$allowedSystem) {
                abort(403);
            }
        }

        $versions = DB::table('configuration_files as cf')
            ->leftJoin('system_register as sr', 'cf.system_register_id', '=', 'sr.id')
            ->where('cf.user_id', $user->id)
            ->where('cf.service_id', $serviceId)
            ->when($this->isAdminOnly($actor), function ($query) use ($sharedWorkspaceIds) {
                $query->whereIn('sr.workspace_id', $sharedWorkspaceIds);
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
                'sr.system_name'
            )
            ->orderByDesc('cf.created_at')
            ->get();

        return view('admin.user-service-versions', compact('user', 'service', 'versions'));
    }

    public function enterpriseConsole(): View
    {
        $actor = Auth::user();
        $defaultOrg = Organization::find(200) ?? Organization::first();
        $workspaces = Workspace::query()
            ->where('org_id', (int) ($actor->org_id ?? ($defaultOrg->id ?? 200)))
            ->orderBy('name')
            ->get();

        $adminCandidates = User::query()
            ->where('rbac_id', 101)
            ->where('org_id', (int) ($actor->org_id ?? 200))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return view('admin.enterprise-console', [
            'workspaces' => $workspaces,
            'adminCandidates' => $adminCandidates,
        ]);
    }

    public function createEnterpriseWorkspace(Request $request): RedirectResponse
    {
        $actor = Auth::user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:workspaces,name'],
            'description' => ['nullable', 'string', 'max:512'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'admin_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $workspace = Workspace::create([
            'org_id' => (int) ($actor->org_id ?? 200),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ]);

        if (!empty($validated['admin_user_id'])) {
            $adminUser = User::query()
                ->where('id', (int) $validated['admin_user_id'])
                ->where('rbac_id', 101)
                ->where('org_id', (int) ($actor->org_id ?? 200))
                ->first();

            if ($adminUser !== null) {
                $workspace->addUser($adminUser->id, true);
            }
        }

        return redirect()
            ->route('enterprise.console')
            ->with('success', 'Workspace created successfully.');
    }

    public function adminWorkspaces(): View
    {
        $actor = Auth::user();
        $workspaces = Workspace::query()
            ->join('workspace_user', 'workspace_user.workspace_id', '=', 'workspaces.id')
            ->where('workspace_user.user_id', $actor->id)
            ->where('workspace_user.is_admin', true)
            ->orderBy('workspaces.name')
            ->get(['workspaces.id', 'workspaces.name', 'workspaces.description', 'workspaces.status']);

        $workspaceTags = $workspaces->mapWithKeys(fn ($workspace) => [
            $workspace->id => \App\Support\WorkspaceSettings::tagLabels(\App\Support\WorkspaceSettings::get((int) $workspace->id)['tags']),
        ]);
        $tagFilter = trim((string) request('tag', ''));
        if ($tagFilter !== '') {
            $workspaces = $workspaces->filter(fn ($workspace) => collect($workspaceTags[$workspace->id])
                ->contains(fn (string $tag) => mb_strtolower($tag) === mb_strtolower($tagFilter)))->values();
        }

        return view('admin.manage-workspaces', [
            'workspaces' => $workspaces,
            'workspaceTags' => $workspaceTags,
            'allWorkspaceTags' => $workspaceTags->flatten()->unique(fn ($tag) => mb_strtolower($tag))->sort()->values(),
            'tagFilter' => $tagFilter,
            'canCreateWorkspace' => in_array((int) ($actor->rbac_id ?? 0), [100, 101], true),
        ]);
    }

    /**
     * An admin (rbac 101) creates a workspace in their organization and becomes its workspace admin.
     */
    public function createAdminWorkspace(Request $request): RedirectResponse
    {
        $actor = Auth::user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:workspaces,name'],
            'description' => ['nullable', 'string', 'max:512'],
        ]);

        $workspace = Workspace::create([
            'org_id' => (int) ($actor->org_id ?? 200),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => 'active',
        ]);
        $workspace->addUser($actor->id, true);

        return redirect()
            ->route('admin.workspaces.show', $workspace->id)
            ->with('success', 'Workspace created. You are its workspace admin.');
    }

    public function viewWorkspace(int $workspaceId): View
    {
        $actor = Auth::user();
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$this->canManageWorkspaceUsers($actor, $workspace)) {
            abort(403);
        }

        $isSuperAdmin = $this->isSuperAdmin($actor);
        $admins = $workspace->admins()->get();
        $regularUsers = $workspace->regularUsers()->get();
        $assignedUserIds = $workspace->users()->pluck('users.id')->all();
        // Workspace admin is a per-workspace role (workspace_user.is_admin): any user or admin
        // of the organization can hold it. Plain members here can be promoted.
        $allAdmins = User::query()
            ->where('org_id', (int) ($workspace->org_id ?? 200))
            ->where(function ($query) {
                $query->whereNull('rbac_id')
                    ->orWhereIn('rbac_id', [101, 102]);
            })
            ->whereNotIn('id', $admins->pluck('id')->all())
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
        // Admin-role users (101) can be plain members here while managing another workspace.
        $allRegularUsers = User::query()
            ->where('org_id', (int) ($workspace->org_id ?? 200))
            ->where(function ($query) {
                $query->whereNull('rbac_id')
                    ->orWhereIn('rbac_id', [101, 102]);
            })
            ->whereNotIn('id', $assignedUserIds)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $workspaceShowRouteName = $isSuperAdmin ? 'workspace.detail' : 'admin.workspaces.show';
        $workspaceAddUserRouteName = $isSuperAdmin ? 'workspace.users.add' : 'admin.workspaces.users.add';
        $workspaceRemoveUserRouteName = $isSuperAdmin ? 'workspace.users.remove' : 'admin.workspaces.users.remove';
        $workspaceAddAdminRouteName = $isSuperAdmin ? 'workspace.admins.add' : 'admin.workspaces.admins.add';
        $workspaceUpdateRouteName = $isSuperAdmin ? 'workspace.update' : null;
        $workspaceDeleteRouteName = $isSuperAdmin ? 'workspace.destroy' : null;

        return view('admin.workspace-detail', [
            'workspace' => $workspace,
            'admins' => $admins,
            'regularUsers' => $regularUsers,
            'allAdmins' => $allAdmins,
            'allRegularUsers' => $allRegularUsers,
            'isSuperAdmin' => $isSuperAdmin,
            'canEditWorkspaceMetadata' => $isSuperAdmin,
            'canManageAdmins' => $workspace->allows($actor, 'admins'),
            'workspaceAddAdminRouteName' => $workspaceAddAdminRouteName,
            'permissions' => $workspace->permissionsFor($actor),
            'canSetPermissions' => $this->canSetPermissions($actor, $workspace),
            'adminPermissions' => $admins->mapWithKeys(fn (User $admin) => [
                $admin->id => Workspace::resolvePermissions(json_decode((string) ($admin->pivot->permissions ?? ''), true)),
            ]),
            'aiRun' => \App\Models\WorkspaceAiRun::where('workspace_id', $workspace->id)->latest('id')->first(),
            'removableMemberIds' => $workspace->users()->get()
                ->filter(fn (User $member) => $this->canRemoveMember($actor, $member, $workspace))
                ->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'settings' => \App\Support\WorkspaceSettings::get($workspace->id),
            'notificationGroups' => \App\Models\NotificationGroup::where('workspace_id', $workspace->id)->orderBy('name')->get(),
            'memberPreferences' => \App\Models\WorkspaceNotificationPreference::where('workspace_id', $workspace->id)->get()->keyBy('user_id'),
            'backupRuns' => \App\Models\WorkspaceBackupRun::where('workspace_id', $workspace->id)->latest('id')->limit(20)->get(),
            'workspaceShowRouteName' => $workspaceShowRouteName,
            'workspaceUpdateRouteName' => $workspaceUpdateRouteName,
            'workspaceDeleteRouteName' => $workspaceDeleteRouteName,
            'workspaceAddUserRouteName' => $workspaceAddUserRouteName,
            'workspaceRemoveUserRouteName' => $workspaceRemoveUserRouteName,
        ]);
    }

    public function updateWorkspace(Request $request, int $workspaceId): RedirectResponse
    {
        $actor = Auth::user();
        if (!$this->isSuperAdmin($actor)) {
            abort(403);
        }

        $workspace = Workspace::findOrFail($workspaceId);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:512'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ]);

        $workspace->update($validated);

        return redirect()
            ->route('workspace.detail', $workspaceId)
            ->with('success', 'Workspace updated successfully.');
    }

    public function deleteWorkspace(int $workspaceId): RedirectResponse
    {
        $actor = Auth::user();
        if (!$this->isSuperAdmin($actor)) {
            abort(403);
        }

        $workspace = Workspace::findOrFail($workspaceId);
        $workspaceName = $workspace->name;
        $workspace->delete();

        return redirect()
            ->route('enterprise.console')
            ->with('success', "Workspace '{$workspaceName}' deleted successfully.");
    }

    public function addAdminToWorkspace(Request $request, int $workspaceId): RedirectResponse
    {
        $actor = Auth::user();
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$workspace->allows($actor, 'admins')) {
            abort(403);
        }

        $validated = $request->validate([
            'admin_id' => ['required', 'integer', 'exists:users,id'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_keys(Workspace::PERMISSIONS))],
        ]);

        $admin = User::where('id', $validated['admin_id'])
            ->where('org_id', (int) ($workspace->org_id ?? 200))
            ->where(function ($query) {
                $query->whereNull('rbac_id')
                    ->orWhere('rbac_id', '!=', 100);
            })
            ->firstOrFail();

        $workspace->addUser($admin->id, true);
        // Access for a User-role workspace admin is chosen by an Admin-role workspace admin (or the super admin).
        if ($this->canSetPermissions($actor, $workspace) && (int) ($admin->rbac_id ?? 0) === 102) {
            $workspace->setPermissions($admin->id, array_fill_keys($validated['permissions'] ?? [], true)
                + array_map(fn () => false, Workspace::PERMISSIONS));
        }
        app(\App\Services\Notifier::class)->notify(
            \App\Notifications\NotificationEvents::WORKSPACE_MEMBER_ADDED,
            $workspace->id,
            'Member added: ' . $workspace->name,
            ['Workspace' => $workspace->name, 'Member' => $admin->name . ' (' . $admin->email . ')', 'Role' => 'Workspace admin', 'By' => (string) $actor->name],
        );

        return redirect()
            ->route($this->isSuperAdmin($actor) ? 'workspace.detail' : 'admin.workspaces.show', $workspaceId)
            ->with('success', 'Admin added to workspace.');
    }

    public function addUserToWorkspace(Request $request, int $workspaceId): RedirectResponse
    {
        $actor = Auth::user();
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$workspace->allows($actor, 'members')) {
            abort(403);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetUser = User::findOrFail((int) $validated['user_id']);
        if ((int) ($targetUser->rbac_id ?? 0) === 100) {
            return redirect()->back()->withErrors([
                'user_id' => 'Super admin cannot be added to a workspace as a regular member.',
            ]);
        }

        if ($this->isWorkspaceManager($targetUser, $workspace)) {
            return redirect()->back()->withErrors([
                'user_id' => 'This user is already a manager of this workspace.',
            ]);
        }

        $workspace->addUser($targetUser->id, false);
        app(\App\Services\Notifier::class)->notify(
            \App\Notifications\NotificationEvents::WORKSPACE_MEMBER_ADDED,
            $workspace->id,
            'Member added: ' . $workspace->name,
            ['Workspace' => $workspace->name, 'Member' => $targetUser->name . ' (' . $targetUser->email . ')', 'Role' => 'User', 'By' => (string) $actor->name],
        );

        $showRoute = $this->isSuperAdmin($actor) ? 'workspace.detail' : 'admin.workspaces.show';

        return redirect()
            ->route($showRoute, $workspaceId)
            ->with('success', 'User added to workspace.');
    }

    public function removeUserFromWorkspace(int $workspaceId, int $userId): RedirectResponse
    {
        $actor = Auth::user();
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$this->canManageWorkspaceUsers($actor, $workspace)) {
            abort(403);
        }

        $targetUser = User::findOrFail($userId);
        if (!$this->canRemoveMember($actor, $targetUser, $workspace)) {
            return redirect()->back()->withErrors([
                'authorization' => 'You cannot remove this member. Workspace admins with the User role cannot remove admins who have the Admin role, and nobody can remove themselves or the super admin.',
            ]);
        }

        $workspace->removeUser($userId);
        app(\App\Services\Notifier::class)->notify(
            \App\Notifications\NotificationEvents::WORKSPACE_MEMBER_REMOVED,
            $workspace->id,
            'Member removed: ' . $workspace->name,
            ['Workspace' => $workspace->name, 'Member' => $targetUser->name . ' (' . $targetUser->email . ')', 'Role' => 'Removed', 'By' => (string) $actor->name],
        );

        $showRoute = $this->isSuperAdmin($actor) ? 'workspace.detail' : 'admin.workspaces.show';

        return redirect()
            ->route($showRoute, $workspaceId)
            ->with('success', 'User removed from workspace.');
    }

    private function isSuperAdmin(User $user): bool
    {
        return (int) ($user->rbac_id ?? 0) === 100;
    }

    private function isAdminOnly(User $user): bool
    {
        return (int) ($user->rbac_id ?? 0) === 101;
    }

    private function visibleWorkspaceIdsForAdmin(User $user): array
    {
        if (!$this->isAdminOnly($user)) {
            return [];
        }

        return $user->workspaces()
            ->pluck('workspaces.id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Who may remove $target from $workspace (the actor already manages it):
     * - nobody removes themselves or the super admin;
     * - a workspace admin with the User role cannot remove a workspace admin who has the Admin role.
     */
    private function canRemoveMember(User $actor, User $target, Workspace $workspace): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return (int) $target->id !== (int) $actor->id;
        }

        if ((int) $target->id === (int) $actor->id || (int) ($target->rbac_id ?? 0) === 100) {
            return false;
        }

        $targetIsAdmin = $this->isWorkspaceManager($target, $workspace);
        if (!$workspace->allows($actor, $targetIsAdmin ? 'admins' : 'members')) {
            return false;
        }

        if (!$this->isAdminOnly($actor) && $targetIsAdmin && (int) ($target->rbac_id ?? 0) === 101) {
            return false;
        }

        return true;
    }

    /**
     * Only workspace admins with the Admin role, and the super admin, decide what a
     * User-role workspace admin may change.
     */
    private function canSetPermissions(User $actor, Workspace $workspace): bool
    {
        return in_array((int) ($actor->rbac_id ?? 0), [100, 101], true) && $workspace->canBeManagedBy($actor);
    }

    public function updateMemberPermissions(Request $request, int $workspaceId, int $userId): RedirectResponse
    {
        $actor = Auth::user();
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$this->canSetPermissions($actor, $workspace)) {
            abort(403);
        }

        $target = User::findOrFail($userId);
        if (!$this->isWorkspaceManager($target, $workspace) || (int) ($target->rbac_id ?? 0) !== 102) {
            return redirect()->back()->withErrors([
                'permissions' => 'Access can only be set for workspace admins with the User role.',
            ]);
        }

        $validated = $request->validate([
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(array_keys(Workspace::PERMISSIONS))],
        ]);

        $workspace->setPermissions($target->id, array_fill_keys($validated['permissions'] ?? [], true)
            + array_map(fn () => false, Workspace::PERMISSIONS));

        return redirect()
            ->route($this->isSuperAdmin($actor) ? 'workspace.detail' : 'admin.workspaces.show', ['workspaceId' => $workspaceId, 'tab' => 'members'])
            ->with('success', 'Access updated for ' . $target->name . '.');
    }

    /**
     * Workspaces where the user is a manager (workspace_user.is_admin), as opposed to a plain member.
     */
    private function managedWorkspaceIds(User $user): array
    {
        return DB::table('workspace_user')
            ->where('user_id', $user->id)
            ->where('is_admin', true)
            ->pluck('workspace_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function isWorkspaceManager(User $user, Workspace $workspace): bool
    {
        return DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->where('is_admin', true)
            ->exists();
    }

    private function canViewTargetUser(User $actor, User $target): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        if (!$this->isAdminOnly($actor)) {
            return false;
        }

        return DB::table('workspace_user as actor_wu')
            ->join('workspace_user as target_wu', 'target_wu.workspace_id', '=', 'actor_wu.workspace_id')
            ->where('actor_wu.user_id', $actor->id)
            ->where('target_wu.user_id', $target->id)
            ->exists();
    }

    private function canEditTargetUser(User $actor, User $target): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return (int) ($target->rbac_id ?? 0) !== 100;
        }

        if (!$this->isAdminOnly($actor)) {
            return false;
        }

        if (in_array((int) ($target->rbac_id ?? 0), [100, 101], true)) {
            return false;
        }

        return $this->canViewTargetUser($actor, $target);
    }

    private function sharedWorkspaceIdsWithTarget(User $actor, User $target): array
    {
        if ($this->isSuperAdmin($actor)) {
            return $target->workspaces()
                ->pluck('workspaces.id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return DB::table('workspace_user as actor_wu')
            ->join('workspace_user as target_wu', 'target_wu.workspace_id', '=', 'actor_wu.workspace_id')
            ->where('actor_wu.user_id', $actor->id)
            ->where('target_wu.user_id', $target->id)
            ->pluck('actor_wu.workspace_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function canManageWorkspaceUsers(User $actor, Workspace $workspace): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return (int) ($workspace->org_id ?? 200) === (int) ($actor->org_id ?? 200);
        }

        return $this->isWorkspaceManager($actor, $workspace);
    }

    public function createEnterpriseOrganization(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:organizations,name'],
            'description' => ['nullable', 'string', 'max:512'],
            'status' => ['required', Rule::in(['active', 'inactive'])],
            'admin_user_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $newOrgId = (int) ((Organization::max('id') ?? 200) + 1);

        $organization = Organization::create([
            'id' => $newOrgId,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
        ]);

        if (!empty($validated['admin_user_id'])) {
            $adminUser = User::query()
                ->where('id', $validated['admin_user_id'])
                ->where('rbac_id', 101)
                ->first();

            if ($adminUser !== null) {
                $adminUser->org_id = $organization->id;
                $adminUser->save();
            }
        }

        return redirect()
            ->route('enterprise.console')
            ->with('success', 'Organization created and admin assignment updated.');
    }

    public function settings(): View
    {
        $this->syncStorageBaseUrlsToDatabase();

        $s3Runtime = $this->resolveS3RuntimeCredentials();
        $siteUrl = rtrim((string) config('app.url', ''), '/');
        $siteDomain = parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl;
        $sitePort = parse_url($siteUrl, PHP_URL_PORT);
        if (!empty($sitePort) && is_numeric($sitePort)) {
            $siteDomain .= ':' . $sitePort;
        }

        $organizationName = Organization::query()
            ->where('id', 200)
            ->value('name') ?? 'Default Organization';

        $localStorageBaseUrl = $this->resolveStorageBaseUrl('local');
        $s3StorageBaseUrl = $this->resolveStorageBaseUrl('s3');

        $useS3Storage = $this->isFeatureEnabledSetting('s3_enabled', $this->isS3Enabled());
        $backupRestoreEnabled = BackupSettings::masterEnabled();
        $migrationEnabled = $this->isFeatureEnabledSetting('migration_enabled', $useS3Storage);

        $backupService = app(BackupService::class);
        $backupSections = [];
        $configuredCronSetups = [];
        foreach (BackupSettings::TYPES as $backupType) {
            $settings = BackupSettings::get($backupType);
            $backupSections[$backupType] = $settings + [
                'label' => BackupSettings::LABELS[$backupType],
                'last_run' => $backupService->lastRun($backupType),
            ];

            $expression = BackupSettings::expression($settings);
            if ($backupRestoreEnabled && $settings['enabled'] && $expression !== null) {
                $destinations = array_filter([
                    $settings['to_local'] ? 'local (keep ' . $settings['keep_local'] . ')' : null,
                    $settings['to_s3'] ? 'S3 (keep ' . $settings['keep_s3'] . ')' : null,
                ]);
                $configuredCronSetups[] = [
                    'name' => BackupSettings::LABELS[$backupType] . ' to ' . implode(' and ', $destinations),
                    'frequency' => BackupSettings::frequencyLabel($settings),
                    'expression' => $expression,
                    'command' => $backupType === BackupService::TYPE_DATABASE ? 'backup:database' : 'backup:config',
                    'last_run' => $backupSections[$backupType]['last_run'],
                ];
            }
        }

        $migrationDirection = (string) session('migration_direction', $useS3Storage ? 'local_to_s3' : 's3_to_local');
        if (!in_array($migrationDirection, ['local_to_s3', 's3_to_local'], true)) {
            $migrationDirection = $useS3Storage ? 'local_to_s3' : 's3_to_local';
        }
        $migrationKeepSource = (bool) session('migration_keep_source', true);
        $migrationAnalysis = session('migration_analysis');
        $migrationResult = session('migration_result');

        $siteFeatures = $this->getJsonSetting('site_features', []);
        $siteMetadata = $this->getJsonSetting('site_metadata', []);
        $siteTags = $this->getJsonSetting('site_tags', []);
        $mailRecipients = $this->getJsonSetting('mail_recipients', []);
        return view('admin.settings', [
            'siteUrl' => $siteUrl,
            'siteDomain' => $siteDomain,
            'organizationName' => $organizationName,
            'siteLogoUrl' => $this->resolveSiteLogoUrl(),
            'siteLogoUrlOverride' => AdminSetting::getValue('site_logo_url', ''),
            'siteDescription' => AdminSetting::getValue('site_description', AdminSetting::getValue('site_content', '')),
            'siteContent' => AdminSetting::getValue('site_content', ''),
            'domainView' => DomainSettings::viewData(request()->getHost()),
            'siteMetadataText' => json_encode($siteMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'siteTagsText' => implode(',', $siteTags),
            'siteFeaturesText' => implode("\n", $siteFeatures),
            'organizationLogoUrl' => SiteProfile::current()->logoUrl(),
            'siteAbout' => SiteProfile::current()->about(),
            'siteFaq' => SiteProfile::current()->faq(),
            'siteSupport' => SiteProfile::current()->support(),
            'siteContactEnabled' => SiteProfile::current()->contactEnabled(),
            'siteContactIntro' => SiteProfile::current()->contactIntro(),
            'contactSubmissions' => ContactSubmission::query()->latest('id')->limit(50)->get(),
            'useS3Storage' => $useS3Storage,
            's3Region' => $s3Runtime['region'],
            's3Bucket' => $s3Runtime['bucket'],
            's3AccessKey' => $s3Runtime['key'],
            'localStorageBaseUrl' => $localStorageBaseUrl,
            's3StorageBaseUrl' => $s3StorageBaseUrl,
            'hasS3Secret' => $s3Runtime['secret'] !== '',
            'backupRestoreEnabled' => $backupRestoreEnabled,
            'backupSections' => $backupSections,
            'backupFrequencies' => BackupSettings::FREQUENCIES,
            'configuredCronSetups' => $configuredCronSetups,
            'migrationEnabled' => $migrationEnabled,
            'migrationDirection' => $migrationDirection,
            'migrationKeepSource' => $migrationKeepSource,
            'migrationAnalysis' => is_array($migrationAnalysis) ? $migrationAnalysis : null,
            'migrationResult' => is_array($migrationResult) ? $migrationResult : null,
            'migrationNotice' => (string) session('migration_notice', ''),
            'migrationStatus' => (string) AdminSetting::getValue('migration_status', ''),
            'migrationProgress' => $this->getJsonSetting('migration_progress', []),
            'mailHost' => AdminSetting::getValue('mail_host', ''),
            'mailPort' => AdminSetting::getValue('mail_port', ''),
            'mailUsername' => AdminSetting::getValue('mail_username', ''),
            'hasMailPassword' => AdminSetting::getValue('mail_password', null) !== null,
            'mailEncryption' => AdminSetting::getValue('mail_encryption', ''),
            'mailFromAddress' => AdminSetting::getValue('mail_from_address', ''),
            'mailFromName' => AdminSetting::getValue('mail_from_name', ''),
            'mailRecipientsText' => implode(',', $mailRecipients),
            'ssoEnabled' => SsoSettings::enabled(),
            'disableEmailRegistration' => $this->isFeatureEnabledSetting('disable_email_registration'),
            'ssoEnabledProviders' => SsoSettings::enabledProviders(),
        ]);
    }

    public function updateSiteSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
            'site_logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
            'remove_site_logo' => ['nullable', 'boolean'],
            'site_description' => ['nullable', 'string', 'max:5000'],
            'site_about' => ['nullable', 'string', 'max:20000'],
            'faq_question' => ['nullable', 'array', 'max:100'],
            'faq_question.*' => ['nullable', 'string', 'max:500'],
            'faq_answer' => ['nullable', 'array', 'max:100'],
            'faq_answer.*' => ['nullable', 'string', 'max:5000'],
            'site_support_contact_name' => ['nullable', 'string', 'max:255'],
            'site_support_contact_email' => ['nullable', 'email', 'max:255'],
            'site_support_contact_phone' => ['nullable', 'string', 'max:50'],
            'site_support_hours' => ['nullable', 'string', 'max:255'],
            'site_support_request_url' => ['nullable', 'url:http,https', 'max:2048'],
            'site_support_details' => ['nullable', 'string', 'max:20000'],
            'site_contact_enabled' => ['nullable', 'boolean'],
            'site_contact_intro' => ['nullable', 'string', 'max:5000'],
            'site_metadata' => ['nullable', 'string', 'max:20000'],
            'site_tags' => ['nullable', 'string', 'max:2000'],
            'site_features' => ['nullable', 'string'],
            'site_content' => ['nullable', 'string', 'max:5000'],
        ]);

        $metadata = [];
        if (!empty($validated['site_metadata'])) {
            $decodedMetadata = json_decode((string) $validated['site_metadata'], true);
            if (!is_array($decodedMetadata)) {
                return back()->withErrors([
                    'site_metadata' => 'Site metadata must be valid JSON object/array.',
                ])->withInput();
            }

            $metadata = $decodedMetadata;
        }

        $tags = collect(explode(',', (string) ($validated['site_tags'] ?? '')))
            ->map(fn (string $tag) => trim($tag))
            ->filter()
            ->values()
            ->all();

        $features = collect(explode("\n", (string) ($validated['site_features'] ?? '')))
            ->map(fn (string $feature) => trim($feature))
            ->filter()
            ->values()
            ->all();

        if ($request->hasFile('site_logo')) {
            $this->replaceSiteLogo($request->file('site_logo'));
        } elseif ($request->boolean('remove_site_logo')) {
            $this->removeSiteLogo();
        }

        Organization::query()->updateOrCreate(
            ['id' => SiteProfile::DEFAULT_ORGANIZATION_ID],
            ['name' => trim($validated['organization_name'])]
        );

        $faq = [];
        foreach (($validated['faq_question'] ?? []) as $index => $question) {
            $question = trim((string) $question);
            if ($question !== '') {
                $faq[] = ['question' => $question, 'answer' => trim((string) ($validated['faq_answer'][$index] ?? ''))];
            }
        }

        AdminSetting::putValue('site', 'site_about', trim((string) ($validated['site_about'] ?? '')));
        AdminSetting::putValue('site', 'site_faq', $faq);
        foreach (['contact_name', 'contact_email', 'contact_phone', 'hours', 'request_url', 'details'] as $field) {
            AdminSetting::putValue('site', 'site_support_' . $field, trim((string) ($validated['site_support_' . $field] ?? '')));
        }
        AdminSetting::putValue('site', 'site_contact_enabled', $request->boolean('site_contact_enabled') ? 'true' : 'false');
        AdminSetting::putValue('site', 'site_contact_intro', trim((string) ($validated['site_contact_intro'] ?? '')));

        AdminSetting::putValue('site', 'site_description', $validated['site_description'] ?? '');
        AdminSetting::putValue('site', 'site_metadata', $metadata);
        AdminSetting::putValue('site', 'site_tags', $tags);
        AdminSetting::putValue('site', 'site_features', $features);
        AdminSetting::putValue('site', 'site_content', $validated['site_description'] ?? ($validated['site_content'] ?? ''));

        return redirect()->route('admin.settings', ['tab' => 'site'])->with('success', 'Site settings updated successfully.');
    }

    /**
     * Stores the logo on the public disk (inside the storage volume, so it
     * survives container rebuilds). It is served through the site.logo route.
     */
    private function replaceSiteLogo(UploadedFile $logo): void
    {
        $extension = strtolower((string) ($logo->extension() ?: 'png'));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }

        $previousPath = trim((string) AdminSetting::getValue('site_logo_path', ''));

        // A new name per upload, so browsers do not keep showing the old logo.
        $path = $logo->storeAs('branding', 'site-logo-' . now()->format('YmdHis') . '.' . $extension, 'public');

        AdminSetting::putValue('site', 'site_logo_disk', 'public');
        AdminSetting::putValue('site', 'site_logo_path', $path);
        AdminSetting::putValue('site', 'site_logo_url', '');
        AdminSetting::putValue('site', 'site_favicon_url', '');

        if ($previousPath !== '' && $previousPath !== $path) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    private function removeSiteLogo(): void
    {
        $previousPath = trim((string) AdminSetting::getValue('site_logo_path', ''));
        if ($previousPath !== '') {
            Storage::disk('public')->delete($previousPath);
        }

        AdminSetting::putValue('site', 'site_logo_path', '');
        AdminSetting::putValue('site', 'site_logo_url', '');
        AdminSetting::putValue('site', 'site_favicon_url', '');
    }

    public function deleteContactSubmission(int $submissionId): RedirectResponse
    {
        ContactSubmission::query()->whereKey($submissionId)->delete();

        return redirect()->route('admin.settings', ['tab' => 'site'])->with('success', 'Contact message deleted.');
    }

    public function toggleS3Plugin(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
        $enabled = $request->boolean('enabled');
        $back = redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 's3']);

        if (!$enabled) {
            // Config files stored in S3 would become unreadable.
            $analysis = $this->analyzeStorageMigrationDirection('s3_to_local');
            if (($analysis['files_pending_migration'] ?? 0) > 0) {
                return redirect()
                    ->route('admin.settings', ['tab' => 'migration'])
                    ->withErrors(['s3_enabled' => 'Before disabling S3 Storage, migrate the files stored in S3 back to local storage here.'])
                    ->with('migration_analysis', $analysis)
                    ->with('migration_direction', 's3_to_local');
            }
            $this->setEnvironmentValues(['S3_ENABLED' => 'false']);
            AdminSetting::putValue('storage', 's3_enabled', 'false');
        }
        AdminSetting::putValue('storage', 's3_plugin_enabled', $enabled ? 'true' : 'false');

        ActivityRecorder::record(Auth::id(), 'settings.s3_plugin', 'S3 Storage plugin ' . ($enabled ? 'enabled' : 'disabled'));

        return $back->with('success', $enabled
            ? 'S3 Storage is enabled. Enter the bucket and keys on the S3 Configuration tab to start using it.'
            : 'S3 Storage is disabled. Everything is stored locally.');
    }

    public function updateS3Settings(Request $request): RedirectResponse
    {
        if (!S3Settings::pluginEnabled()) {
            return redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 's3'])
                ->withErrors(['s3' => 'Enable S3 Storage in the Plugins tab first.']);
        }

        $currentlyEnabled = $this->isFeatureEnabledSetting('s3_enabled', $this->isS3Enabled());

        $validated = $request->validate([
            's3_enabled' => ['nullable', 'boolean'],
            's3_access_key' => ['required', 'string', 'max:255'],
            's3_secret_key' => ['nullable', 'string', 'max:255'],
            's3_region' => ['required', 'string', 'max:100'],
            's3_bucket' => ['required', 'string', 'max:255'],
        ]);

        // On/off is the S3 Storage plugin; saving the keys here puts S3 to use.
        $requestedEnabled = true;
        $runtimeCredentials = $this->resolveS3RuntimeCredentials();
        $resolvedSecret = trim((string) ($validated['s3_secret_key'] ?? ''));

        if ($resolvedSecret === '') {
            $resolvedSecret = $runtimeCredentials['secret'];
        }

        if ($requestedEnabled && $resolvedSecret === '') {
            return back()
                ->withErrors(['s3_secret_key' => 'S3 secret key is required when enabling S3.'])
                ->withInput();
        }

        if ($currentlyEnabled && !$requestedEnabled) {
            $analysis = $this->analyzeStorageMigrationDirection('s3_to_local');
            if (($analysis['files_pending_migration'] ?? 0) > 0) {
                return redirect()
                    ->route('admin.settings', ['tab' => 'migration'])
                    ->withErrors([
                        's3_enabled' => 'Before disabling S3, migrate data from S3 to local in the Migration tab.',
                    ])
                    ->with('migration_analysis', $analysis)
                    ->with('migration_direction', 's3_to_local');
            }
        }

        $siteUrl = rtrim((string) config('app.url', ''), '/');
        $localStorageBaseUrl = $siteUrl !== '' ? $siteUrl . '/storage' : '';
        $s3StorageBaseUrl = '';

        if ($requestedEnabled && trim($validated['s3_bucket']) !== '' && trim($validated['s3_region']) !== '') {
            $s3StorageBaseUrl = sprintf('https://%s.s3.%s.amazonaws.com/', trim($validated['s3_bucket']), trim($validated['s3_region']));
        }

        $this->setEnvironmentValues([
            'S3_ENABLED' => $requestedEnabled ? 'true' : 'false',
            'AWS_ACCESS_KEY_ID' => $validated['s3_access_key'],
            'AWS_SECRET_ACCESS_KEY' => $this->encryptSecretForEnvironment($resolvedSecret),
            'AWS_DEFAULT_REGION' => $validated['s3_region'],
            'AWS_BUCKET' => $validated['s3_bucket'],
            'AWS_URL' => $s3StorageBaseUrl,
            'LOCAL_STORAGE_BASE_URL' => $localStorageBaseUrl,
            'S3_STORAGE_BASE_URL' => $s3StorageBaseUrl,
            'VERSION' => (string) config('app.version', '0.1.0'),
        ]);

        AdminSetting::putValue('storage', 's3_enabled', $requestedEnabled ? 'true' : 'false');
        AdminSetting::putValue('storage', 's3_access_key', $validated['s3_access_key']);
        AdminSetting::putValue('storage', 's3_region', $validated['s3_region']);
        AdminSetting::putValue('storage', 's3_bucket', $validated['s3_bucket']);

        if ($resolvedSecret !== '') {
            AdminSetting::putValue('storage', 's3_secret_key', $resolvedSecret, true);
        }

        Config::set('filesystems.disks.s3.key', $validated['s3_access_key']);
        Config::set('filesystems.disks.s3.secret', $resolvedSecret);
        Config::set('filesystems.disks.s3.region', $validated['s3_region']);
        Config::set('filesystems.disks.s3.bucket', $validated['s3_bucket']);
        Config::set('filesystems.disks.s3.url', $s3StorageBaseUrl);

        $this->syncStorageBaseUrlsToDatabase();

        if (!$currentlyEnabled && $requestedEnabled) {
            AdminSetting::putValue('storage', 'migration_enabled', 'true');
            $this->setEnvironmentValues([
                'MIGRATION_ENABLED' => 'true',
            ]);

            return redirect()
                ->route('admin.settings', ['tab' => 'migration'])
                ->with('success', 'S3 has been enabled. Go ahead and migrate existing local data to S3 from the Migration tab.')
                ->with('migration_notice', 'S3 is enabled. Run Local to S3 migration to move existing local data.')
                ->with('migration_direction', 'local_to_s3');
        }

        return redirect()->route('admin.settings', ['tab' => 's3'])->with('success', 'S3 settings saved successfully.');
    }

    public function updateBackupRestoreSettings(Request $request): RedirectResponse
    {
        $rules = ['backup_restore_enabled' => ['nullable', 'boolean']];
        foreach (BackupSettings::TYPES as $type) {
            $rules += [
                "backup_{$type}_enabled" => ['nullable', 'boolean'],
                "backup_{$type}_frequency" => ['nullable', Rule::in(array_merge(array_keys(BackupSettings::FREQUENCIES), [BackupSettings::CUSTOM]))],
                "backup_{$type}_cron_expression" => ['nullable', 'string', 'max:100'],
                "backup_{$type}_to_s3" => ['nullable', 'boolean'],
                "backup_{$type}_to_local" => ['nullable', 'boolean'],
                "backup_{$type}_keep_s3" => ['nullable', 'integer', 'min:1', 'max:' . BackupSettings::MAX_KEEP],
                "backup_{$type}_keep_local" => ['nullable', 'integer', 'min:1', 'max:' . BackupSettings::MAX_KEEP],
            ];
        }
        $validated = $request->validate($rules);

        $backupRestoreEnabled = $request->boolean('backup_restore_enabled');
        $sections = [];
        $errors = [];

        foreach (BackupSettings::TYPES as $type) {
            $label = BackupSettings::LABELS[$type];
            $values = [
                'enabled' => $backupRestoreEnabled && $request->boolean("backup_{$type}_enabled"),
                'frequency' => (string) ($validated["backup_{$type}_frequency"] ?? ''),
                'cron_expression' => trim((string) ($validated["backup_{$type}_cron_expression"] ?? '')),
                'to_s3' => $request->boolean("backup_{$type}_to_s3"),
                'to_local' => $request->boolean("backup_{$type}_to_local"),
                'keep_s3' => (int) ($validated["backup_{$type}_keep_s3"] ?? BackupSettings::DEFAULT_KEEP),
                'keep_local' => (int) ($validated["backup_{$type}_keep_local"] ?? BackupSettings::DEFAULT_KEEP),
            ];

            if ($values['enabled']) {
                if ($values['frequency'] === '') {
                    $errors["backup_{$type}_frequency"] = "Select how often to run the {$label}.";
                } elseif ($values['frequency'] === BackupSettings::CUSTOM && !BackupSettings::isValidCron($values['cron_expression'])) {
                    $errors["backup_{$type}_cron_expression"] = "Enter a valid 5-field cron expression for the {$label}, for example 30 2 * * *.";
                }

                if (!$values['to_s3'] && !$values['to_local']) {
                    $errors["backup_{$type}_to_local"] = "Choose where to keep the {$label}: local copies, S3, or both.";
                }
            }

            if ($values['to_s3'] && $backupRestoreEnabled && !$this->isS3Enabled()) {
                $errors["backup_{$type}_to_s3"] = 'Enable S3 on the S3 tab first, or keep local copies only.';
            }

            $sections[$type] = $values;
        }

        if ($errors !== []) {
            return back()->withErrors($errors)->withInput();
        }

        AdminSetting::putValue('storage', 'backup_restore_enabled', $backupRestoreEnabled ? 'true' : 'false');
        foreach ($sections as $type => $values) {
            BackupSettings::save($type, $values);
        }

        // Where newly uploaded configuration files are stored follows the files backup's S3 choice, as before.
        $configToS3 = $sections[BackupSettings::TYPE_CONFIG]['enabled'] && $sections[BackupSettings::TYPE_CONFIG]['to_s3'];
        AdminSetting::putValue('storage', 'configuration_file_base_location', $configToS3 ? 's3' : 'local');

        $this->setEnvironmentValues([
            'CONFIGURATION_FILES_BASE_DISK' => $configToS3 ? 's3' : 'local',
            'BACKUP_RESTORE_ENABLED' => $backupRestoreEnabled ? 'true' : 'false',
        ]);

        return redirect()
            ->route('admin.settings', ['tab' => 'backup-restore'])
            ->with('success', 'Backup and restore settings saved successfully.');
    }

    public function updateMigrationSettings(Request $request): RedirectResponse
    {
        $request->validate([
            'migration_enabled' => ['nullable', 'boolean'],
        ]);

        $migrationEnabled = $request->boolean('migration_enabled');

        AdminSetting::putValue('storage', 'migration_enabled', $migrationEnabled ? 'true' : 'false');
        $this->setEnvironmentValues([
            'MIGRATION_ENABLED' => $migrationEnabled ? 'true' : 'false',
        ]);

        return redirect()
            ->route('admin.settings', ['tab' => 'migration'])
            ->with('success', 'Migration settings saved successfully.');
    }

    public function analyzeMigration(Request $request): RedirectResponse
    {
        if (!$this->isFeatureEnabledSetting('migration_enabled')) {
            return redirect()
                ->route('admin.settings', ['tab' => 'migration'])
                ->withErrors(['migration' => 'Enable migration from the Migration tab before running migration actions.']);
        }

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['local_to_s3', 's3_to_local'])],
            'keep_source' => ['nullable', 'boolean'],
        ]);

        $analysis = $this->analyzeStorageMigrationDirection($validated['direction']);
        $keepSource = $request->boolean('keep_source');

        return redirect()
            ->route('admin.settings', ['tab' => 'migration'])
            ->with('migration_analysis', $analysis)
            ->with('migration_direction', $validated['direction'])
            ->with('migration_keep_source', $keepSource);
    }

    public function startMigration(Request $request): RedirectResponse
    {
        if (!$this->isFeatureEnabledSetting('migration_enabled')) {
            return redirect()
                ->route('admin.settings', ['tab' => 'migration'])
                ->withErrors(['migration' => 'Enable migration from the Migration tab before running migration actions.']);
        }

        $validated = $request->validate([
            'direction' => ['required', Rule::in(['local_to_s3', 's3_to_local'])],
            'keep_source' => ['nullable', 'boolean'],
        ]);

        $keepSource = $request->boolean('keep_source');

        $analysis = $this->analyzeStorageMigrationDirection($validated['direction']);

        if (($analysis['files_pending_migration'] ?? 0) === 0) {
            // Files can already be at the destination (e.g. a kept source from an
            // earlier migration); the file records must still switch disks.
            $this->applyMigrationCompletion($validated['direction']);

            return redirect()
                ->route('admin.settings', ['tab' => 'migration'])
                ->with('success', 'No files pending migration. Source and destination are already synchronized.')
                ->with('migration_result', [
                    'direction' => $validated['direction'],
                    'keep_source' => $keepSource,
                    'migrated_files' => 0,
                    'verified_files' => (int) ($analysis['source_files_found'] ?? 0),
                    'failed_verification' => 0,
                    'source_deleted_files' => 0,
                    'warnings' => [],
                    'progress_percent' => 100,
                ])
                ->with('migration_analysis', $analysis)
                ->with('migration_direction', $validated['direction'])
                ->with('migration_keep_source', $keepSource);
        }

        $result = $this->executeStorageMigration($validated['direction'], $analysis['entries'] ?? [], $keepSource);

        $postAnalysis = $this->analyzeStorageMigrationDirection($validated['direction']);

        if ($validated['direction'] === 's3_to_local' && (($postAnalysis['files_pending_migration'] ?? 0) === 0)) {
            $this->disableS3AfterMigration();
        }

        if (!empty($result['errors'])) {
            return redirect()
                ->route('admin.settings', ['tab' => 'migration'])
                ->withErrors(['migration' => implode(' | ', $result['errors'])])
                ->with('migration_analysis', $postAnalysis)
                ->with('migration_result', $result)
                ->with('migration_direction', $validated['direction'])
                ->with('migration_keep_source', $keepSource);
        }

        $this->applyMigrationCompletion($validated['direction']);

        return redirect()
            ->route('admin.settings', ['tab' => 'migration'])
            ->with('success', 'Migration completed successfully with verification.')
            ->with('migration_result', $result)
            ->with('migration_analysis', $postAnalysis)
                ->with('migration_direction', $validated['direction'])
                ->with('migration_keep_source', $keepSource);
    }

    /**
     * Points file records and the site logo at the migration's destination disk.
     */
    private function applyMigrationCompletion(string $direction): void
    {
        if ($direction === 'local_to_s3') {
            AdminSetting::putValue('storage', 'migration_local_to_s3_completed_at', now()->toDateTimeString());
            AdminSetting::putValue('site', 'site_logo_disk', 's3');
            if ($this->hasStorageDiskColumn()) {
                ConfigurationFile::query()->whereNotNull('file_location')->update(['storage_disk' => 's3']);
            }

            return;
        }

        AdminSetting::putValue('storage', 'migration_s3_to_local_completed_at', now()->toDateTimeString());
        $this->disableS3AfterMigration();
        AdminSetting::putValue('site', 'site_logo_disk', 'public');
        if ($this->hasStorageDiskColumn()) {
            ConfigurationFile::query()->whereNotNull('file_location')->update(['storage_disk' => 'local']);
        }
    }

    /**
     * Turns S3 off in both places it is read from, so the UI and the API agree.
     */
    private function disableS3AfterMigration(): void
    {
        $this->setEnvironmentValues(['S3_ENABLED' => 'false']);
        AdminSetting::putValue('storage', 's3_enabled', 'false');
    }

    public function serveSiteLogo(?string $path = null)
    {
        $storedPath = ltrim(trim((string) AdminSetting::getValue('site_logo_path', '')), '/');
        if ($storedPath === '') {
            abort(404, 'Site logo not configured.');
        }

        $requestedPath = ltrim(trim((string) ($path ?? '')), '/');
        if ($requestedPath !== '' && $requestedPath !== $storedPath) {
            abort(404, 'Logo not found.');
        }

        $activeDisk = $this->resolveStorageDisk();
        $fallbackDisk = strtolower(trim((string) AdminSetting::getValue('site_logo_disk', 'public')));
        if ($fallbackDisk === 'local') {
            $fallbackDisk = 'public';
        }

        $disk = $activeDisk;
        if (!Storage::disk($disk)->exists($storedPath) && $fallbackDisk !== '' && Storage::disk($fallbackDisk)->exists($storedPath)) {
            $disk = $fallbackDisk;
        }

        if (!Storage::disk($disk)->exists($storedPath)) {
            abort(404, 'Logo file is missing in storage.');
        }

        $content = Storage::disk($disk)->get($storedPath);
        $extension = strtolower(pathinfo($storedPath, PATHINFO_EXTENSION));
        $mimeMap = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
        ];
        $mimeType = $mimeMap[$extension] ?? 'application/octet-stream';

        return response($content, 200)
            ->header('Content-Type', $mimeType)
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function updateMailSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mail_host' => ['required', 'string', 'max:255'],
            'mail_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'mail_username' => ['required', 'string', 'max:255'],
            'mail_password' => ['nullable', 'string', 'max:255'],
            'mail_encryption' => ['nullable', Rule::in(['tls', 'ssl', 'starttls'])],
            'mail_from_address' => ['required', 'email', 'max:255'],
            'mail_from_name' => ['required', 'string', 'max:255'],
            'mail_recipients' => ['required', 'string', 'max:2000'],
        ]);

        $recipientList = collect(explode(',', $validated['mail_recipients']))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->values();

        foreach ($recipientList as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return back()->withErrors([
                    'mail_recipients' => 'Every recipient must be a valid email address.',
                ])->withInput();
            }
        }

        AdminSetting::putValue('mail', 'mail_host', $validated['mail_host']);
        AdminSetting::putValue('mail', 'mail_port', (string) $validated['mail_port']);
        AdminSetting::putValue('mail', 'mail_username', $validated['mail_username']);

        if (!empty($validated['mail_password'])) {
            AdminSetting::putValue('mail', 'mail_password', $validated['mail_password'], true);
        }

        AdminSetting::putValue('mail', 'mail_encryption', $validated['mail_encryption'] ?? '');
        AdminSetting::putValue('mail', 'mail_from_address', $validated['mail_from_address']);
        AdminSetting::putValue('mail', 'mail_from_name', $validated['mail_from_name']);
        AdminSetting::putValue('mail', 'mail_recipients', $recipientList->all());

        return redirect()->route('admin.settings', ['tab' => 'email'])->with('success', 'Mail settings saved successfully.');
    }

    public function toggleSsoPlugin(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);
        $enabled = $request->boolean('enabled');

        $this->setEnvironmentValues(['SSO_ENABLED' => $enabled ? 'true' : 'false']);
        AdminSetting::putValue('sso', 'sso_enabled', $enabled ? 'true' : 'false');
        if (!$enabled) {
            // Without SSO, users must be able to register and reset passwords by email again.
            AdminSetting::putValue('sso', 'disable_email_registration', 'false');
        }

        ActivityRecorder::record(Auth::id(), 'settings.sso_plugin', 'SSO Login plugin ' . ($enabled ? 'enabled' : 'disabled'));

        return redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 'sso'])->with('success', $enabled
            ? 'SSO Login is enabled. Set up at least one provider on the SSO tab.'
            : 'SSO Login is disabled. The login page shows email and password only.');
    }

    public function updateSsoSettings(Request $request): RedirectResponse
    {
        if (!SsoSettings::enabled()) {
            return redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 'sso'])
                ->withErrors(['sso' => 'Enable SSO Login in the Plugins tab first.']);
        }

        $catalog = SsoProviders::all();
        $request->validate([
            'disable_email_registration' => ['nullable', 'boolean'],
            'sso_enabled_providers' => ['nullable', 'array'],
            'sso_enabled_providers.*' => [Rule::in(array_keys($catalog))],
            'sso_config' => ['nullable', 'array'],
            'sso_config.*' => ['array'],
            'sso_config.*.*' => ['nullable', 'string', 'max:2048'],
            'sso_secret' => ['nullable', 'array'],
            'sso_secret.*' => ['nullable', 'string', 'max:1024'],
            'sso_clear_secret' => ['nullable', 'array'],
        ]);

        $enabledProviders = array_values(array_unique(array_intersect((array) $request->input('sso_enabled_providers', []), array_keys($catalog))));

        // Everything entered is saved, even when a provider has errors, so a secret is never lost;
        // only providers without errors are enabled. Unticked providers keep their settings.
        $config = SsoProviders::savedConfig();
        $secrets = SsoProviders::secrets();
        $errors = [];
        foreach ($catalog as $provider => $definition) {
            $input = (array) $request->input('sso_config.' . $provider, []);
            $submitted = $input !== [] || $request->has('sso_secret.' . $provider);
            $selected = in_array($provider, $enabledProviders, true);
            if (!$submitted && !$selected) {
                continue;
            }

            if ($submitted) {
                $values = ['client_id' => trim((string) ($input['client_id'] ?? ''))];
                foreach ($definition['fields'] ?? [] as $field => $meta) {
                    $values[$field] = ($meta['type'] ?? 'text') === 'checkbox'
                        ? (!empty($input[$field]) ? 'true' : 'false')
                        : trim((string) ($input[$field] ?? ''));
                }
                $config[$provider] = $values;
            } else {
                $values = ['client_id' => SsoProviders::clientId($provider)];
                foreach (array_keys($definition['fields'] ?? []) as $field) {
                    $values[$field] = SsoProviders::value($provider, $field);
                }
                $secrets[$provider] ??= SsoProviders::clientSecret($provider);
            }

            $secret = trim((string) $request->input('sso_secret.' . $provider, ''));
            if ($secret !== '') {
                $secrets[$provider] = $secret;
            } elseif ($request->boolean('sso_clear_secret.' . $provider)) {
                unset($secrets[$provider]);
            }

            if (in_array($provider, $enabledProviders, true)) {
                $label = $definition['label'];
                foreach ($this->ssoProviderErrors($provider, $values, $secrets[$provider] ?? '') as $message) {
                    $errors['sso_config.' . $provider][] = $label . ': ' . $message;
                }
            }
        }

        $readyProviders = array_values(array_filter($enabledProviders, fn ($provider) => !isset($errors['sso_config.' . $provider])));
        // Without a working provider, turning off email registration would leave no way to sign up.
        $disableEmailRegistration = $request->boolean('disable_email_registration') && $readyProviders !== [];

        AdminSetting::putValue('sso', 'sso_provider_config', $config);
        AdminSetting::putValue('sso', 'sso_provider_client_secrets', $secrets, true);
        AdminSetting::putValue('sso', 'sso_enabled_providers', $readyProviders);
        AdminSetting::putValue('sso', 'disable_email_registration', $disableEmailRegistration ? 'true' : 'false');
        $this->setEnvironmentValues(['SSO_ENABLED_PROVIDERS' => implode(',', $readyProviders)]);

        $labels = fn (array $keys) => implode(', ', array_map(fn ($key) => $catalog[$key]['label'], $keys));
        ActivityRecorder::record(Auth::id(), 'settings.sso_updated', 'Updated SSO providers: ' . ($readyProviders === [] ? 'none enabled' : $labels($readyProviders)));

        $redirect = redirect()->route('admin.settings', ['tab' => 'sso']);
        if ($errors !== []) {
            $notReady = array_values(array_diff($enabledProviders, $readyProviders));

            return $redirect
                ->withErrors(collect($errors)->map(fn ($messages) => implode(' ', $messages))->all())
                ->withInput($request->except('sso_secret'))
                ->with('success', 'Saved. ' . $labels($notReady) . ' stays disabled until the fields below are fixed; saved secrets are kept.');
        }

        return $redirect->with('success', $readyProviders === []
            ? 'SSO settings saved. No provider is enabled yet.'
            : 'SSO settings saved. Sign out and try the new button on the login page.');
    }

    /** @return array<int, string> what is wrong with one provider's settings */
    private function ssoProviderErrors(string $provider, array $values, string $secret): array
    {
        $definition = SsoProviders::definition($provider);
        $errors = [];
        if ($values['client_id'] === '') {
            $errors[] = ($definition['client_id_label'] ?? 'Client ID') . ' is required.';
        }
        if ($secret === '') {
            $errors[] = ($definition['client_secret_label'] ?? 'Client secret') . ' is required.';
        }
        foreach ($definition['fields'] ?? [] as $field => $meta) {
            $value = (string) ($values[$field] ?? '');
            if (($meta['required'] ?? false) && $value === '') {
                $errors[] = $meta['label'] . ' is required.';
                continue;
            }
            if ($value !== '' && ($meta['type'] ?? 'text') === 'url') {
                if (filter_var($value, FILTER_VALIDATE_URL) === false || !str_starts_with(strtolower($value), 'https://')) {
                    $errors[] = $meta['label'] . ' must be an https:// address.';
                }
            }
        }

        if ($provider === 'microsoft' && in_array(strtolower($values['tenant_id'] ?? ''), SsoProviders::RESERVED_MICROSOFT_TENANTS, true)) {
            $errors[] = 'Use your own Directory (tenant) ID, not common, organizations or consumers.';
        }
        if ($provider === 'auth0' && ($values['domain'] ?? '') !== '' && !preg_match('/^(https:\/\/)?[a-z0-9.-]+\.[a-z]{2,}\/?$/i', $values['domain'])) {
            $errors[] = 'Domain must look like your-tenant.us.auth0.com.';
        }
        if ($provider === 'okta' && ($values['auth_server'] ?? '') !== '' && !preg_match('/^[A-Za-z0-9_-]+$/', $values['auth_server'])) {
            $errors[] = 'Authorization server ID can only contain letters, numbers, - and _.';
        }
        if ($provider === 'google' && ($values['allowed_domain'] ?? '') !== '' && !preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $values['allowed_domain'])) {
            $errors[] = 'Google Workspace domain must look like example.com.';
        }

        return $errors;
    }

    private function getJsonSetting(string $key, array $default): array
    {
        $value = AdminSetting::getValue($key, null);

        if ($value === null || $value === '') {
            return $default;
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : $default;
    }

    private function isFeatureEnabledSetting(string $key, bool $default = false): bool
    {
        $raw = (string) AdminSetting::getValue($key, $default ? 'true' : 'false');

        return filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    private function resolveStorageDisk(): string
    {
        $s3Enabled = $this->isS3Enabled();

        if (!$s3Enabled) {
            return 'public';
        }

        $credentials = $this->resolveS3RuntimeCredentials();
        $key = $credentials['key'];
        $secret = $credentials['secret'];
        $region = $credentials['region'];
        $bucket = $credentials['bucket'];

        if ($key === '' || $secret === '' || $region === '' || $bucket === '') {
            return 'public';
        }

        Config::set('filesystems.disks.s3.key', $key);
        Config::set('filesystems.disks.s3.secret', $secret);
        Config::set('filesystems.disks.s3.region', $region);
        Config::set('filesystems.disks.s3.bucket', $bucket);

        return 's3';
    }

    private function resolveSiteLogoUrl(): string
    {
        $overrideUrl = trim((string) AdminSetting::getValue('site_logo_url', ''));
        if ($overrideUrl !== '') {
            return $overrideUrl;
        }

        $storedPath = trim((string) AdminSetting::getValue('site_logo_path', ''));
        if ($storedPath === '') {
            return '';
        }

        return $this->buildStorageObjectUrl($this->resolveStorageDisk(), $storedPath);
    }

    private function buildStorageObjectUrl(string $disk, string $path): string
    {
        $normalizedPath = ltrim($path, '/');
        if ($normalizedPath === '') {
            return '';
        }

        if ($disk === 's3') {
            return route('site.logo', ['path' => $normalizedPath]);
        }

        $baseUrl = $this->resolveStorageBaseUrl($disk);

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . $normalizedPath;
        }

        if (in_array($disk, ['public', 'local'], true)) {
            return Storage::url($normalizedPath);
        }

        if ($disk === 's3') {
            return $this->buildS3ObjectUrl($normalizedPath);
        }

        return $normalizedPath;
    }

    private function resolveStorageBaseUrl(string $disk): string
    {
        $normalizedDisk = strtolower(trim($disk));

        if ($normalizedDisk === 's3') {
            $configured = trim($this->getEnvValue('S3_STORAGE_BASE_URL', ''));
            if ($configured !== '') {
                return $configured;
            }

            $credentials = $this->resolveS3RuntimeCredentials();
            if ($credentials['bucket'] !== '' && $credentials['region'] !== '') {
                return sprintf('https://%s.s3.%s.amazonaws.com/', $credentials['bucket'], $credentials['region']);
            }

            return '';
        }

        $configured = trim($this->getEnvValue('LOCAL_STORAGE_BASE_URL', ''));
        if ($configured !== '') {
            return $configured;
        }

        $siteUrl = rtrim((string) config('app.url', ''), '/');

        return $siteUrl !== '' ? $siteUrl . '/storage' : '';
    }

    private function syncStorageBaseUrlsToDatabase(): void
    {
        $siteUrl = rtrim((string) config('app.url', ''), '/');
        $localStorageBaseUrl = $siteUrl !== '' ? $siteUrl . '/storage' : '';

        $s3Enabled = $this->isS3Enabled();
        $credentials = $this->resolveS3RuntimeCredentials();
        $bucket = $credentials['bucket'];
        $region = $credentials['region'];
        $s3StorageBaseUrl = '';

        if ($s3Enabled && $bucket !== '' && $region !== '') {
            $s3StorageBaseUrl = sprintf('https://%s.s3.%s.amazonaws.com/', $bucket, $region);
        }

        $this->setEnvironmentValues([
            'LOCAL_STORAGE_BASE_URL' => $localStorageBaseUrl,
            'S3_STORAGE_BASE_URL' => $s3StorageBaseUrl,
            'VERSION' => (string) config('app.version', '0.1.0'),
        ]);

        AdminSetting::putValue('storage', 'site_url', $siteUrl);
    }

    private function analyzeStorageMigrationDirection(string $direction): array
    {
        $entries = $this->buildMigrationEntries($direction);

        $sourceFilesFound = 0;
        $filesPendingMigration = 0;
        $missingSourceFiles = 0;
        $folders = [];
        $localFilesFound = 0;
        $s3FilesFound = 0;

        foreach ($entries as &$entry) {
            $sourceExists = Storage::disk($entry['source_disk'])->exists($entry['path']);
            $destinationExists = Storage::disk($entry['destination_disk'])->exists($entry['path']);

            if (Storage::disk('local')->exists($entry['path']) || Storage::disk('public')->exists($entry['path'])) {
                $localFilesFound++;
            }
            if (Storage::disk('s3')->exists($entry['path'])) {
                $s3FilesFound++;
            }

            $entry['source_exists'] = $sourceExists;
            $entry['destination_exists'] = $destinationExists;

            if (!$sourceExists) {
                $missingSourceFiles++;
                continue;
            }

            $sourceFilesFound++;

            if (!$destinationExists) {
                $filesPendingMigration++;
                $folders[dirname($entry['path'])] = true;
            }
        }

        return [
            'direction' => $direction,
            'total_tracked_files' => count($entries),
            'source_files_found' => $sourceFilesFound,
            'missing_source_files' => $missingSourceFiles,
            'files_pending_migration' => $filesPendingMigration,
            'folders_pending_migration' => count(array_filter(array_keys($folders), fn ($folder) => $folder !== '.' && $folder !== '')),
            'local_files_found' => $localFilesFound,
            's3_files_found' => $s3FilesFound,
            'entries' => $entries,
        ];
    }

    private function executeStorageMigration(string $direction, array $entries, bool $keepSource = true): array
    {
        $errors = [];
        $warnings = [];
        $migrated = 0;
        $verified = 0;
        $failedVerification = 0;
        $sourceDeleted = 0;
        $processed = 0;
        $total = count($entries);

        AdminSetting::putValue('storage', 'migration_status', 'in_progress');
        AdminSetting::putValue('storage', 'migration_progress', [
            'processed' => 0,
            'total' => $total,
            'percent' => 0,
            'direction' => $direction,
            'started_at' => now()->toDateTimeString(),
        ]);

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $path = (string) ($entry['path'] ?? '');
            $sourceDisk = (string) ($entry['source_disk'] ?? '');
            $destinationDisk = (string) ($entry['destination_disk'] ?? '');

            if ($path === '' || $sourceDisk === '' || $destinationDisk === '') {
                $processed++;
                continue;
            }

            $sourceExists = Storage::disk($sourceDisk)->exists($path);
            $destinationExists = Storage::disk($destinationDisk)->exists($path);

            if (!$sourceExists) {
                $warnings[] = "Missing source file (skipped): {$path} on {$sourceDisk}";
                $processed++;
                AdminSetting::putValue('storage', 'migration_progress', [
                    'processed' => $processed,
                    'total' => $total,
                    'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 100,
                    'direction' => $direction,
                    'started_at' => now()->toDateTimeString(),
                ]);
                continue;
            }

            if (!$destinationExists) {
                try {
                    $stream = Storage::disk($sourceDisk)->readStream($path);
                    if ($stream === false) {
                        $errors[] = "Cannot read source stream: {$path}";
                        $processed++;
                        continue;
                    }

                    $writeOptions = [];
                    if ($destinationDisk === 's3') {
                        $writeOptions['visibility'] = 'public';
                    }

                    $written = Storage::disk($destinationDisk)->writeStream($path, $stream, $writeOptions);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    if (!$written) {
                        $errors[] = "Cannot write destination stream: {$path}";
                        $processed++;
                        continue;
                    }

                    $migrated++;
                } catch (\Throwable $e) {
                    $errors[] = "Migration failed for {$path}: {$e->getMessage()}";
                    $processed++;
                    continue;
                }
            }

            try {
                if (!Storage::disk($destinationDisk)->exists($path)) {
                    $failedVerification++;
                    $errors[] = "Verification failed (destination missing): {$path}";
                    continue;
                }

                $sourceChecksum = md5((string) Storage::disk($sourceDisk)->get($path));
                $destinationChecksum = md5((string) Storage::disk($destinationDisk)->get($path));

                if ($sourceChecksum !== $destinationChecksum) {
                    $failedVerification++;
                    $errors[] = "Verification checksum mismatch: {$path}";
                    continue;
                }

                $verified++;

                if (!$keepSource && $sourceDisk !== $destinationDisk) {
                    try {
                        if (Storage::disk($sourceDisk)->delete($path)) {
                            $sourceDeleted++;
                        } else {
                            $warnings[] = "Could not delete source file after migration: {$path} ({$sourceDisk})";
                        }
                    } catch (\Throwable $e) {
                        $warnings[] = "Source delete error for {$path}: {$e->getMessage()}";
                    }
                }
            } catch (\Throwable $e) {
                $failedVerification++;
                $errors[] = "Verification error for {$path}: {$e->getMessage()}";
            }

            $processed++;
            AdminSetting::putValue('storage', 'migration_progress', [
                'processed' => $processed,
                'total' => $total,
                'percent' => $total > 0 ? (int) floor(($processed / $total) * 100) : 100,
                'direction' => $direction,
                'started_at' => now()->toDateTimeString(),
            ]);
        }

        AdminSetting::putValue('storage', 'migration_status', empty($errors) ? 'completed' : 'completed_with_errors');
        AdminSetting::putValue('storage', 'migration_progress', [
            'processed' => $processed,
            'total' => $total,
            'percent' => 100,
            'direction' => $direction,
            'started_at' => now()->toDateTimeString(),
            'completed_at' => now()->toDateTimeString(),
        ]);

        return [
            'direction' => $direction,
            'keep_source' => $keepSource,
            'migrated_files' => $migrated,
            'verified_files' => $verified,
            'failed_verification' => $failedVerification,
            'source_deleted_files' => $sourceDeleted,
            'processed_files' => $processed,
            'total_files' => $total,
            'progress_percent' => 100,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function buildMigrationEntries(string $direction): array
    {
        $entries = [];
        $seen = [];

        $logoPath = trim((string) AdminSetting::getValue('site_logo_path', ''));
        if ($logoPath !== '') {
            $logoEntry = $direction === 'local_to_s3'
                ? ['type' => 'logo', 'path' => ltrim($logoPath, '/'), 'source_disk' => 'public', 'destination_disk' => 's3']
                : ['type' => 'logo', 'path' => ltrim($logoPath, '/'), 'source_disk' => 's3', 'destination_disk' => 'public'];

            $key = $logoEntry['type'] . '|' . $logoEntry['path'] . '|' . $logoEntry['source_disk'] . '|' . $logoEntry['destination_disk'];
            $entries[] = $logoEntry;
            $seen[$key] = true;
        }

        $configPaths = ConfigurationFile::query()
            ->whereNotNull('file_location')
            ->where('file_location', '!=', '')
            ->pluck('file_location')
            ->all();

        foreach ($configPaths as $configPath) {
            $normalizedPath = ltrim((string) $configPath, '/');
            if ($normalizedPath === '') {
                continue;
            }

            $entry = $direction === 'local_to_s3'
                ? ['type' => 'configuration_file', 'path' => $normalizedPath, 'source_disk' => 'local', 'destination_disk' => 's3']
                : ['type' => 'configuration_file', 'path' => $normalizedPath, 'source_disk' => 's3', 'destination_disk' => 'local'];

            $key = $entry['type'] . '|' . $entry['path'] . '|' . $entry['source_disk'] . '|' . $entry['destination_disk'];
            if (isset($seen[$key])) {
                continue;
            }

            $entries[] = $entry;
            $seen[$key] = true;
        }

        if ($this->configureS3DiskFromSettings() === false && in_array($direction, ['local_to_s3', 's3_to_local'], true)) {
            return [];
        }

        return $entries;
    }

    private function configureS3DiskFromSettings(): bool
    {
        $credentials = $this->resolveS3RuntimeCredentials();
        $key = $credentials['key'];
        $secret = $credentials['secret'];
        $region = $credentials['region'];
        $bucket = $credentials['bucket'];

        if ($key === '' || $secret === '' || $region === '' || $bucket === '') {
            return false;
        }

        Config::set('filesystems.disks.s3.key', $key);
        Config::set('filesystems.disks.s3.secret', $secret);
        Config::set('filesystems.disks.s3.region', $region);
        Config::set('filesystems.disks.s3.bucket', $bucket);

        return true;
    }

    private function resolveS3RuntimeCredentials(): array
    {
        // Saved settings win; .env is only a fallback, since `artisan serve`
        // keeps the environment it started with.
        $credentials = S3Settings::credentials();

        $fallbacks = [
            'key' => fn () => $this->getEnvFileValue('AWS_ACCESS_KEY_ID', $this->getEnvValue('AWS_ACCESS_KEY_ID', '')),
            'secret' => fn () => $this->decryptSecretFromEnvironment($this->getEnvFileValue('AWS_SECRET_ACCESS_KEY', $this->getEnvValue('AWS_SECRET_ACCESS_KEY', ''))),
            'region' => fn () => $this->getEnvFileValue('AWS_DEFAULT_REGION', $this->getEnvValue('AWS_DEFAULT_REGION', '')),
            'bucket' => fn () => $this->getEnvFileValue('AWS_BUCKET', $this->getEnvValue('AWS_BUCKET', '')),
        ];

        foreach ($fallbacks as $name => $fallback) {
            if ($credentials[$name] === '') {
                $credentials[$name] = $this->normalizeSettingValue($fallback());
            }
        }

        return $credentials;
    }

    private function getEnvFileValue(string $key, string $default = ''): string
    {
        $envPath = base_path('.env');
        if (!File::exists($envPath)) {
            return trim($default);
        }

        $contents = (string) File::get($envPath);
        $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return trim($default);
        }

        $raw = trim((string) ($matches[1] ?? ''));
        if ($raw === '') {
            return '';
        }

        if (str_starts_with($raw, '"') && str_ends_with($raw, '"')) {
            $raw = trim($raw, '"');
        }

        return trim($raw);
    }

    private function normalizeSettingValue(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '' || strtolower($normalized) === 'null') {
            return '';
        }

        return $normalized;
    }

    private function getEnvValue(string $key, string $default = ''): string
    {
        $fileValue = $this->readEnvFileValue($key);
        if ($fileValue !== null) {
            return $fileValue;
        }

        $value = env($key);
        if ($value !== null && $value !== false) {
            return trim((string) $value);
        }

        $runtime = getenv($key);
        if ($runtime !== false) {
            return trim((string) $runtime);
        }

        return trim($default);
    }

    private function readEnvFileValue(string $key): ?string
    {
        $envPath = base_path('.env');
        if (!File::exists($envPath) || !File::isReadable($envPath)) {
            return null;
        }

        $pattern = '/^' . preg_quote($key, '/') . '=(.*)$/m';
        $contents = File::get($envPath);
        if (preg_match($pattern, $contents, $matches) !== 1) {
            return null;
        }

        $raw = trim((string) ($matches[1] ?? ''));
        if (
            strlen($raw) >= 2
            && str_starts_with($raw, '"')
            && str_ends_with($raw, '"')
        ) {
            $raw = substr($raw, 1, -1);
            $raw = str_replace('\\"', '"', $raw);
        }

        return trim($raw);
    }

    private function getSecretEnvValue(string $key, string $default = ''): string
    {
        return $this->decryptSecretFromEnvironment($this->getEnvValue($key, $default));
    }

    private function isS3Enabled(): bool
    {
        $envEnabled = filter_var($this->getEnvValue('S3_ENABLED', 'false'), FILTER_VALIDATE_BOOL);

        return $this->isFeatureEnabledSetting('s3_enabled', $envEnabled);
    }

    private function encryptSecretForEnvironment(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($trimmed, 'ENC:')) {
            return $trimmed;
        }

        return 'ENC:' . Crypt::encryptString($trimmed);
    }

    private function decryptSecretFromEnvironment(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (!str_starts_with($trimmed, 'ENC:')) {
            return $trimmed;
        }

        try {
            return trim(Crypt::decryptString(substr($trimmed, 4)));
        } catch (\Throwable) {
            return '';
        }
    }

    private function setEnvironmentValues(array $values): void
    {
        $envPath = base_path('.env');
        if (!File::exists($envPath) || !File::isWritable($envPath)) {
            return;
        }

        $contents = File::get($envPath);
        $originalContents = $contents;
        $effectiveUpdates = [];

        foreach ($values as $key => $rawValue) {
            $value = trim((string) ($rawValue ?? ''));
            $line = $key . '=' . $this->formatEnvValue($value);
            $pattern = "/^" . preg_quote($key, '/') . "=.*/m";
            $existingValue = $this->getEnvValue($key, '');

            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace($pattern, $line, $contents, 1) ?? $contents;
            } else {
                $contents = rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
            }

            if ($existingValue !== $value) {
                $effectiveUpdates[$key] = $value;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        if ($contents !== $originalContents) {
            File::put($envPath, $contents);
        }

        foreach ($effectiveUpdates as $key => $value) {
            putenv($key . '=' . $value);
        }
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|"/', $value) === 1) {
            return '"' . str_replace('"', '\\"', $value) . '"';
        }

        return $value;
    }

    private function hasStorageDiskColumn(): bool
    {
        static $hasColumn;

        if ($hasColumn !== null) {
            return $hasColumn;
        }

        $hasColumn = DB::getSchemaBuilder()->hasColumn('configuration_files', 'storage_disk');

        return $hasColumn;
    }

    private function buildS3ObjectUrl(string $path): string
    {
        $customUrl = trim((string) config('filesystems.disks.s3.url', ''));
        if ($customUrl !== '') {
            return rtrim($customUrl, '/') . '/' . ltrim($path, '/');
        }

        $bucket = trim((string) config('filesystems.disks.s3.bucket', ''));
        $region = trim((string) config('filesystems.disks.s3.region', ''));

        if ($bucket === '' || $region === '') {
            return $path;
        }

        return sprintf('https://%s.s3.%s.amazonaws.com/%s', $bucket, $region, ltrim($path, '/'));
    }

    public function updateMcpSettings(Request $request): RedirectResponse
    {
        abort_unless(Auth::user()?->isSuperAdmin(), 403);

        $enabled = $request->validate(['enabled' => 'required|boolean'])['enabled'];
        $enabled = filter_var($enabled, FILTER_VALIDATE_BOOLEAN);

        McpControl::setEnabled($enabled);
        $error = McpControl::apply($enabled);

        ActivityRecorder::record(
            Auth::id(),
            'settings.mcp_updated',
            'MCP turned ' . ($enabled ? 'on' : 'off') . ($error !== null ? ' (container not changed: ' . $error . ')' : ''),
            $error === null ? ActivityRecorder::SUCCESS : ActivityRecorder::FAILURE
        );

        $redirect = redirect()->route('admin.settings', ['tab' => 'plugins', 'plugin' => 'mcp']);

        if ($error !== null) {
            return $redirect->withErrors(['mcp' => 'MCP turned ' . ($enabled ? 'on' : 'off') . ', but the MCP container was not ' . ($enabled ? 'started' : 'stopped') . ': ' . $error]);
        }

        return $redirect->with('success', $enabled ? 'MCP Server is enabled and running. Users now see the MCP link in the top bar.' : 'MCP Server is disabled and stopped.');
    }
}
