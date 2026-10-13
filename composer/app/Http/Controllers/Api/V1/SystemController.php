<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\SystemRegisterController;
use App\Http\Controllers\Controller;
use App\Models\SystemRegister;
use App\Support\ApiIdentity;
use App\Support\ServiceAccountKeyHeader;
use App\Support\ServiceAccountSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

        $data['validation_hash'] = $data['system_key'];
        unset($data['system_key']);

        $system = $this->systems->createSystem($request, $data, $account);
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
