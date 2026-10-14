<?php

namespace App\Http\Controllers;

use App\Models\PatToken;
use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ServiceAccountException;
use App\Services\ServiceAccounts;
use App\Support\ServiceAccountAccess;
use App\Support\ServiceAccountSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Console pages for service accounts. Every action checks ServiceAccountAccess; an
 * account the actor may not manage (or of another organization) is a 404.
 */
class ServiceAccountController extends Controller
{
    public function __construct(private readonly ServiceAccounts $accounts)
    {
    }

    public function index(Request $request): View
    {
        $actor = $this->actor();
        $filterWorkspace = (int) $request->query('workspace', 0);

        return view('admin.service-accounts.index', [
            'filterWorkspace' => $filterWorkspace,
            'activePane' => $request->query('pane') === 'settings' ? 'settings' : 'accounts',
            'accounts' => ServiceAccountAccess::visibleQuery($actor)
                // A workspace filter shows the workspace's own accounts and the organization
                // accounts with an active grant there.
                ->when($filterWorkspace > 0, fn ($query) => $query->where(fn ($inWorkspace) => $inWorkspace
                    ->where('workspace_id', $filterWorkspace)
                    ->orWhereHas('grants', fn ($grants) => $grants->where('workspace_id', $filterWorkspace)->where('status', 'active'))))
                ->with(['workspace:id,name', 'owner:id,email'])
                ->withCount(['grants as active_grants_count' => fn ($query) => $query->where('status', 'active')])
                ->orderBy('scope')->orderBy('name')
                ->get(),
            'workspaces' => Workspace::query()->where('org_id', (int) ($actor->org_id ?? 200))->orderBy('name')->get(['id', 'name']),
            'settings' => $this->settingsValues(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sa_default_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sa_max_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'sa_org_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sa_workspace_quota' => ['required', 'integer', 'min:0', 'max:1000'],
            'sa_expiry_notice_days' => ['required', 'string', 'max:50', 'regex:/^\s*\d{1,3}(\s*,\s*\d{1,3})*\s*$/'],
            'pat_default_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'pat_max_key_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'pat_user_quota' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        ServiceAccountSettings::save($validated + ['sa_require_for_registration' => $request->boolean('sa_require_for_registration')]);

        return redirect()->route('service-accounts.index', ['pane' => 'settings'])->with('success', 'Service account settings saved.');
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:512'],
            'team' => ['nullable', 'string', 'max:100'],
            'scope' => ['required', 'in:organization,workspace'],
            'workspace_id' => ['required_if:scope,workspace', 'nullable', 'regex:/^(all|[0-9]{1,18})$/'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);

        // "All workspaces": an organization account with a grant on every workspace of the
        // organization, made in one step. Admins only, like any organization account.
        if (($data['workspace_id'] ?? null) === 'all') {
            abort_unless(ServiceAccountAccess::canCreateOrganizationAccount($actor), 403);

            return $this->attempt(function () use ($actor, $data) {
                $account = $this->accounts->create($actor, ['scope' => ServiceAccount::SCOPE_ORGANIZATION, 'workspace_id' => null] + $data);
                $workspaces = Workspace::query()->where('org_id', (int) ($actor->org_id ?? 200))->get();
                foreach ($workspaces as $workspace) {
                    $this->accounts->setGrant($account, $actor, $workspace, $data['permissions'] ?? ServiceAccount::DEFAULT_PERMISSIONS);
                }

                return $account;
            }, fn (ServiceAccount $account) => redirect()->route('service-accounts.show', $account->id)->with('success', 'Service account created with access to every workspace. Create a key next.'));
        }

        if ($data['scope'] === ServiceAccount::SCOPE_ORGANIZATION) {
            $allowed = ServiceAccountAccess::canCreateOrganizationAccount($actor);
        } else {
            $workspace = Workspace::query()->find((int) $data['workspace_id']);
            $allowed = $workspace !== null && ServiceAccountAccess::canManageWorkspaceAccounts($actor, $workspace);
        }
        abort_unless($allowed, 403);

        return $this->attempt(
            fn () => $this->accounts->create($actor, $data),
            fn (ServiceAccount $account) => redirect()->route('service-accounts.show', $account->id)->with('success', 'Service account created. Create a key next.'),
        );
    }

    public function show(int $id): View
    {
        $actor = $this->actor();
        $account = $this->findOr404($id);

        return view('admin.service-accounts.show', [
            'account' => $account->load(['workspace:id,name', 'owner:id,email,name', 'grants.workspace:id,name']),
            'keys' => $account->keys()->orderByDesc('id')->get(),
            'activeKeyCount' => $account->activeKeys()->count(),
            'grantableWorkspaces' => $account->isOrganizationScope() && ServiceAccountAccess::canCreateOrganizationAccount($actor)
                ? Workspace::query()->where('org_id', $account->org_id)->orderBy('name')->get(['id', 'name'])
                : collect(),
            'possibleOwners' => User::query()->where('org_id', $account->org_id)->where('status', 'active')->orderBy('email')->get(['id', 'email']),
            'newKey' => session('new_service_account_key'),
            'keyDays' => ['default' => ServiceAccountSettings::defaultKeyDays(), 'max' => ServiceAccountSettings::maxKeyDays()],
        ]);
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:512'],
            'team' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->attempt(fn () => $this->accounts->update($account, $this->actor(), $data), fn () => $this->backTo($account, 'Service account updated.'));
    }

    public function status(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $active = $request->boolean('active');

        return $this->attempt(fn () => $this->accounts->setStatus($account, $this->actor(), $active), fn () => $this->backTo($account, $active ? 'Service account enabled.' : 'Service account disabled.'));
    }

    public function owner(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate(['owner_user_id' => ['required', 'integer']]);
        $newOwner = User::query()->find((int) $data['owner_user_id']);
        if ($newOwner === null) {
            return back()->withErrors(['service_account' => 'The owner must be an active user of this organization.']);
        }

        return $this->attempt(fn () => $this->accounts->transferOwner($account, $this->actor(), $newOwner), fn () => $this->backTo($account, 'Owner changed.'));
    }

    public function grant(Request $request, int $id): RedirectResponse
    {
        $actor = $this->actor();
        $account = $this->findOr404($id);
        $data = $request->validate([
            'workspace_id' => ['required', 'integer'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string'],
        ]);
        $workspace = Workspace::query()->find((int) $data['workspace_id']);
        abort_unless($workspace && ServiceAccountAccess::canGrant($actor, $account, $workspace), 404);

        return $this->attempt(fn () => $this->accounts->setGrant($account, $actor, $workspace, $data['permissions'] ?? []), fn () => $this->backTo($account, 'Access saved.'));
    }

    public function revokeGrant(int $id, int $workspaceId): RedirectResponse
    {
        $actor = $this->actor();
        $account = $this->findOr404($id);
        $workspace = Workspace::query()->find($workspaceId);
        abort_unless($workspace && ServiceAccountAccess::canGrant($actor, $account, $workspace), 404);

        return $this->attempt(fn () => $this->accounts->removeGrant($account, $actor, $workspace), fn () => $this->backTo($account, 'Access removed.'));
    }

    public function issueKey(Request $request, int $id): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'days' => ['nullable', 'integer', 'min:1', 'max:3650'],
        ]);

        return $this->attempt(
            fn () => $this->accounts->issueKey($account, $this->actor(), $data['name'], isset($data['days']) ? (int) $data['days'] : null),
            // Flashed: shown on the next page load only, never stored.
            fn (array $issued) => redirect()->route('service-accounts.show', $account->id)
                ->with('new_service_account_key', $issued['plain'])
                ->with('success', 'Key created. Copy it now: it will not be shown again.'),
        );
    }

    public function revokeKey(Request $request, int $id, int $keyId): RedirectResponse
    {
        $account = $this->findOr404($id);
        $data = $request->validate(['reason' => ['required', 'string', 'max:50']]);
        $key = PatToken::query()
            ->where('tokenable_type', ServiceAccount::class)
            ->where('tokenable_id', $account->id)
            ->whereKey($keyId)
            ->firstOrFail();

        $this->accounts->revokeKey($key, $this->actor(), $data['reason']);

        return $this->backTo($account, 'Key revoked.');
    }

    public function assignSystem(Request $request, int $systemId): RedirectResponse
    {
        $actor = $this->actor();
        $data = $request->validate(['service_account_id' => ['required', 'integer']]);
        $system = SystemRegister::query()->find($systemId);
        abort_unless($system && ServiceAccountAccess::canAssignSystem($actor, $system), 404);

        $account = ServiceAccount::query()->where('org_id', (int) ($actor->org_id ?? 200))->find((int) $data['service_account_id']);
        abort_unless($account, 404);

        return $this->attempt(fn () => $this->accounts->assignSystem($system, $account, $actor), fn () => back()->with(
            'success',
            'System assigned to ' . $account->name . '. Run "sudo atglance --configure" on the server with a key of this service account.',
        ));
    }

    /**
     * For the Systems page: per workspace, the active accounts that can be assigned
     * there, when the actor may assign systems in that workspace.
     *
     * @return array<int, \Illuminate\Support\Collection<int, ServiceAccount>> workspace id => accounts
     */
    public static function assignableByWorkspace(User $actor): array
    {
        $accounts = ServiceAccount::query()
            ->where('org_id', (int) ($actor->org_id ?? 200))
            ->where('status', 'active')
            ->with(['grants' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get();

        $byWorkspace = [];
        foreach ($accounts as $account) {
            foreach ($account->grants as $grant) {
                if ($grant->allows('systems.register')) {
                    $byWorkspace[(int) $grant->workspace_id][] = $account;
                }
            }
        }

        return array_filter(
            array_map('collect', $byWorkspace),
            function ($_, int $workspaceId) use ($actor) {
                $workspace = Workspace::query()->find($workspaceId);

                return $workspace && ServiceAccountAccess::canManageWorkspaceAccounts($actor, $workspace);
            },
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsValues(): array
    {
        return [
            'sa_default_key_days' => ServiceAccountSettings::defaultKeyDays(),
            'sa_max_key_days' => ServiceAccountSettings::maxKeyDays(),
            'sa_org_quota' => ServiceAccountSettings::orgQuota(),
            'sa_workspace_quota' => ServiceAccountSettings::workspaceQuota(),
            'sa_expiry_notice_days' => implode(',', ServiceAccountSettings::noticeDays()),
            'sa_require_for_registration' => ServiceAccountSettings::requireForRegistration(),
            'pat_default_key_days' => ServiceAccountSettings::patDefaultKeyDays(),
            'pat_max_key_days' => ServiceAccountSettings::patMaxKeyDays(),
            'pat_user_quota' => ServiceAccountSettings::patUserQuota(),
        ];
    }

    private function actor(): User
    {
        return Auth::user();
    }

    private function findOr404(int $id): ServiceAccount
    {
        return ServiceAccountAccess::findForActor($this->actor(), $id) ?? abort(404);
    }

    private function backTo(ServiceAccount $account, string $message): RedirectResponse
    {
        return redirect()->route('service-accounts.show', $account->id)->with('success', $message);
    }

    /**
     * Runs a ServiceAccounts operation; its refusals return to the form as errors.
     */
    private function attempt(callable $operation, callable $onSuccess): RedirectResponse
    {
        try {
            $result = $operation();
        } catch (ServiceAccountException $e) {
            return back()->withInput()->withErrors(['service_account' => $e->getMessage()]);
        }

        return $onSuccess($result);
    }
}
