<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\SystemRegisterController;
use App\Http\Controllers\Controller;
use App\Models\SystemRegister;
use App\Support\ActivityRecorder;
use App\Support\ApiIdentity;
use App\Support\ServiceAccountKeyHeader;
use App\Support\ServiceAccountSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * /api/v1/systems for the atglance CLI v2. The logic lives in SystemRegisterController
 * (shared with the 1.x routes); the calling system comes from the atglance.system
 * middleware.
 */
class SystemController extends Controller
{
    public function __construct(private readonly SystemRegisterController $systems)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $orgId = (int) ($user->org_id ?? 200);

        [$account, $denied] = ServiceAccountKeyHeader::resolve($request, $orgId);
        if ($denied) {
            return $denied;
        }
        if ($account === null && ServiceAccountSettings::requireForRegistration()) {
            return response()->json(['message' => 'Registration needs a user key and a service account key'], 422);
        }

        $data = $request->validate(SystemRegisterController::systemFieldRules() + [
            // A workspace of the user's org, and one the user is a member of (admins: any).
            'workspace_id' => array_merge(SystemRegisterController::workspaceIdRule($orgId), [
                function (string $attribute, mixed $value, \Closure $fail) use ($user): void {
                    $workspaceId = (int) $value;
                    if ($workspaceId > 0 && !$user->isAdmin() && !$user->workspaces()->whereKey($workspaceId)->exists()) {
                        $fail('The selected workspace is invalid.');
                    }
                },
            ]),
            'system_key' => ['required', 'string', 'min:32', 'max:255'],
        ]);

        if ($account !== null && !$account->can('systems.register', (int) ($data['workspace_id'] ?? 0))) {
            return response()->json(['message' => 'Service account lacks systems.register in this workspace'], 403);
        }

        // Idempotent on system_key: a CLI retrying a registration whose answer was lost
        // gets the existing system back (200) instead of a duplicate.
        $existing = SystemRegister::query()
            ->where('org_id', $orgId)
            ->where('validation_hash', $data['system_key'])
            ->first();
        if ($existing && hash_equals((string) $existing->validation_hash, (string) $data['system_key'])) {
            $mayManage = (int) $existing->user_id === (int) $user->id
                || $user->isAdmin()
                || ($account !== null && (int) $existing->service_account_id === (int) $account->id);
            if (!$mayManage) {
                return response()->json(['message' => 'The given data was invalid.', 'errors' => ['system_key' => ['The system key is already in use.']]], 422);
            }

            return response()->json(['system' => [
                'id' => $existing->id,
                'system_name' => $existing->system_name,
                'status' => $existing->status,
                'workspace_id' => (int) $existing->workspace_id,
                'service_account_id' => $existing->service_account_id ? (int) $existing->service_account_id : null,
                'created_at' => $existing->created_at,
            ]], 200);
        }

        // A host registering again names the system it replaces and proves it with that
        // system's key. Every failure is the same 422, so a system id is never confirmed.
        $replaces = null;
        if ($request->filled('replaces_system_id') || $request->filled('replaces_system_key')) {
            $rid = (string) $request->input('replaces_system_id', '');
            $rkey = (string) $request->input('replaces_system_key', '');
            $candidate = preg_match('/^[0-9]{1,18}$/', $rid) ? SystemRegister::where('org_id', $orgId)->find((int) $rid) : null;
            $ok = $candidate !== null
                && !empty($candidate->validation_hash)
                && hash_equals((string) $candidate->validation_hash, $rkey)
                && ($this->systems->canManageSystem($user, $candidate)
                    || ($account !== null && (int) $candidate->service_account_id === (int) $account->id));
            if (!$ok) {
                return response()->json(['message' => 'The given data was invalid.', 'errors' => ['replaces_system_id' => ['The system to replace was not found.']]], 422);
            }
            $replaces = $candidate;
        }

        $data['validation_hash'] = $data['system_key'];
        unset($data['system_key']);

        $system = DB::transaction(function () use ($request, $data, $account, $replaces, $user) {
            $created = $this->systems->createSystem($request, $data, $account);
            if ($created instanceof SystemRegister && $replaces !== null) {
                $replaces->status = 'inactive';
                $replaces->save();
                ActivityRecorder::record(
                    (int) $user->id,
                    'system.replaced',
                    'System ' . $replaces->id . ' replaced by system ' . $created->id,
                    ActivityRecorder::SUCCESS,
                    $request,
                    (int) $replaces->workspace_id,
                    (int) $created->id,
                );
            }

            return $created;
        });
        if (!$system instanceof SystemRegister) {
            return $system;
        }

        return response()->json([
            'system' => [
                'id' => $system->id,
                'system_name' => $system->system_name,
                'status' => $system->status,
                'workspace_id' => (int) $system->workspace_id,
                'service_account_id' => $system->service_account_id ? (int) $system->service_account_id : null,
                'created_at' => $system->created_at,
                'replaced_system_id' => $replaces?->id,
            ],
        ], 201);
    }

    public function show(Request $request): JsonResponse
    {
        $system = $this->system($request);

        return response()->json([
            'system' => [
                'id' => $system->id,
                'system_name' => $system->system_name,
                'status' => $system->status,
                'workspace_id' => (int) $system->workspace_id,
                'workspace_name' => $system->workspace?->name,
                'ip_address' => $system->ip_address,
                'os_type' => $system->os_type,
                'distro' => $system->distro,
                'version' => $system->version,
                'tags' => $system->tags,
                'created_at' => $system->created_at,
                'updated_at' => $system->updated_at,
            ],
        ]);
    }

    public function deregister(Request $request)
    {
        return $this->refuseServiceAccount($request) ?? $this->authorizeChange($request) ?? $this->systems->deregisterSystem($this->system($request));
    }

    public function reactivate(Request $request)
    {
        return $this->refuseServiceAccount($request) ?? $this->authorizeChange($request) ?? $this->systems->reactivateSystem($this->system($request));
    }

    public function rotateKey(Request $request)
    {
        $identity = ApiIdentity::current($request);

        // A service account reaches here only with systems.rotate_key (route middleware);
        // it has no password or PIN. A person still needs one.
        if (!$identity?->isServiceAccount() && ($denied = $this->authorizeChange($request))) {
            return $denied;
        }

        $system = $this->system($request);
        $data = $request->validate([
            'new_key' => [
                'required',
                'string',
                'min:32',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) use ($system): void {
                    if (is_string($value) && hash_equals((string) $system->validation_hash, $value)) {
                        $fail('The new key must differ from the current key.');
                    }
                },
            ],
        ]);

        $this->systems->rotateSystemKey($system, $data['new_key'], $request->user(), $identity?->serviceAccount);

        return response()->json(['system_id' => $system->id, 'rotated' => true]);
    }

    public function deregisterForce(Request $request, int $id)
    {
        return $this->systems->forceDeregister($request, $id);
    }

    public function reactivateForce(Request $request, int $id)
    {
        return $this->systems->forceReactivate($request, $id);
    }

    private function system(Request $request): SystemRegister
    {
        return $request->attributes->get('system');
    }

    /**
     * Password or PIN (422), then owner or same-org admin (403). Null when allowed.
     */
    private function refuseServiceAccount(Request $request): ?JsonResponse
    {
        return ApiIdentity::current($request)?->isServiceAccount()
            ? response()->json(['message' => 'Deregister and reactivate need a user key with password or PIN'], 403)
            : null;
    }

    private function authorizeChange(Request $request): ?JsonResponse
    {
        if ($error = $this->systems->validatePasswordOrPin($request)) {
            return $error;
        }

        if (!$this->systems->canManageSystem($request->user(), $this->system($request))) {
            return response()->json(['message' => 'Only the system owner, a workspace admin of its workspace or an admin of its organisation can change this system'], 403);
        }

        return null;
    }
}
