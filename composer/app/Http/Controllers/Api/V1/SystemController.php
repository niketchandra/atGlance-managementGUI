<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\SystemRegisterController;
use App\Http\Controllers\Controller;
use App\Models\SystemRegister;
use App\Models\Workspace;
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
        $orgId = (int) ($request->user()->org_id ?? 200);

        $data = $request->validate(SystemRegisterController::systemFieldRules() + [
            'workspace_id' => [
                'nullable',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, \Closure $fail) use ($orgId): void {
                    $workspaceId = (int) $value;
                    if ($workspaceId <= 0) {
                        return;
                    }
                    $workspace = Workspace::query()->find($workspaceId);
                    if (!$workspace || (int) ($workspace->org_id ?? 200) !== $orgId) {
                        $fail('The selected workspace is invalid.');
                    }
                },
            ],
            'system_key' => ['required', 'string', 'min:32', 'max:255'],
        ]);

        $data['validation_hash'] = $data['system_key'];
        unset($data['system_key']);

        $system = $this->systems->createSystem($request, $data);
        if (!$system instanceof SystemRegister) {
            return $system;
        }

        return response()->json([
            'system' => [
                'id' => $system->id,
                'system_name' => $system->system_name,
                'status' => $system->status,
                'workspace_id' => (int) $system->workspace_id,
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
        return $this->authorizeChange($request) ?? $this->systems->deregisterSystem($this->system($request));
    }

    public function reactivate(Request $request)
    {
        return $this->authorizeChange($request) ?? $this->systems->reactivateSystem($this->system($request));
    }

    public function rotateKey(Request $request)
    {
        if ($denied = $this->authorizeChange($request)) {
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

        $this->systems->rotateSystemKey($system, $data['new_key'], $request->user());

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
    private function authorizeChange(Request $request): ?JsonResponse
    {
        if ($error = $this->systems->validatePasswordOrPin($request)) {
            return $error;
        }

        if (!$this->systems->canManageSystem($request->user(), $this->system($request))) {
            return response()->json(['message' => 'Only the system owner or an admin of its organisation can change this system'], 403);
        }

        return null;
    }
}
