<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PatToken;
use App\Models\SystemRegister;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ActivityRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SystemRegisterController extends Controller
{
    /**
     * Require either password or PIN for sensitive system state changes.
     * Returns the error response, or null when the credentials are valid.
     */
    public function validatePasswordOrPin(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], 401);
        }

        $password = trim((string) $request->input('password', ''));
        $pin = trim((string) $request->input('pin', ''));

        if ($password === '' && $pin === '') {
            return response()->json([
                'message' => 'Either password or pin is required'
            ], 422);
        }

        $passwordHash = $user->password_hash ?? $user->password;
        $pinHash = $user->pin;

        $passwordValid = $password !== '' && !empty($passwordHash) && Hash::check($password, $passwordHash);
        $pinValid = $pin !== '' && !empty($pinHash) && Hash::check($pin, $pinHash);

        if (!$passwordValid && !$pinValid) {
            return response()->json([
                'message' => 'Invalid password or pin'
            ], 422);
        }

        return null;
    }

    /**
     * Validate actor by email and password/pin for force endpoints.
     * Returns [User|null, \Illuminate\Http\JsonResponse|null]
     */
    private function resolveForceActor(Request $request): array
    {
        $email = trim((string) $request->input('email', ''));
        $password = trim((string) $request->input('password', ''));
        $pin = trim((string) $request->input('pin', ''));

        if ($email === '') {
            return [null, response()->json([
                'message' => 'email is required',
            ], 422)];
        }

        if ($password === '' && $pin === '') {
            return [null, response()->json([
                'message' => 'Either password or pin is required',
            ], 422)];
        }

        $invalid = [null, response()->json([
            'message' => 'Invalid credentials',
        ], 401)];

        $user = User::where('email', $email)->first();
        if (!$user) {
            // Same bcrypt work as for a real user, so timing does not reveal unknown emails.
            Hash::check($password !== '' ? $password : $pin, self::DUMMY_HASH);

            return $invalid;
        }

        $passwordHash = $user->password_hash ?? $user->password;
        $pinHash = $user->pin;

        $passwordValid = $password !== '' && !empty($passwordHash) && Hash::check($password, $passwordHash);
        $pinValid = $pin !== '' && !empty($pinHash) && Hash::check($pin, $pinHash);

        if (!$passwordValid && !$pinValid) {
            return $invalid;
        }

        // A deactivated account cannot act, whatever its role.
        if (strtolower((string) ($user->status ?? 'active')) !== 'active') {
            return $invalid;
        }

        return [$user, null];
    }

    /**
     * bcrypt hash of a throwaway string, checked when the force email is unknown.
     */
    private const DUMMY_HASH = '$2y$12$sRjK8Xag9ld.yaq51LPq0e2feplggq12FD2Lr5rl6DZx0dQMdBLIq';

    /**
     * Force actions are for admins only. Returns the 403 response, or null when allowed.
     */
    private function forbidForceActor(User $actor)
    {
        return $actor->isAdmin() ? null : response()->json([
            'message' => "Only an admin of the system's organisation can force this action",
        ], 403);
    }

    /**
     * The system a force action targets: one of the admin's organisation (a super admin:
     * any). Unknown ids and systems of other organisations both give null (404), so an
     * admin cannot probe other organisations' system ids.
     */
    private function findForceTarget(User $actor, mixed $systemId): ?SystemRegister
    {
        $system = SystemRegister::find($systemId);

        if ($system === null) {
            return null;
        }

        if (!$actor->isSuperAdmin() && (int) ($system->org_id ?? 200) !== (int) ($actor->org_id ?? 200)) {
            return null;
        }

        return $system;
    }

    private function logForceSystemAction(Request $request, User $actor, int $systemId, string $action): void
    {
        ActivityLog::create([
            'user_id' => $actor->id,
            'method' => strtoupper((string) $request->method()),
            'path' => (string) $request->path(),
            'status_code' => 200,
            'ip_address' => (string) $request->ip(),
            'user_agent' => (string) ($request->userAgent() ?? ''),
            'request_payload' => json_encode([
                'system_id' => $systemId,
                'action' => $action,
                'performed_by' => $actor->name ?: ('user_' . $actor->id),
                'performed_by_user_id' => $actor->id,
                'performed_by_email' => $actor->email,
            ]),
        ]);
    }

    /**
     * Get all system registers for the authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $systems = SystemRegister::where('user_id', $user->id)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'total' => $systems->count(),
            'systems' => $systems,
        ]);
    }

    /**
     * Get system registers by PAT token ID
     */
    public function getByPatToken(Request $request, $patTokenId)
    {
        $user = $request->user();
        
        // Verify the PAT token belongs to the authenticated user
        $patToken = PatToken::where('id', $patTokenId)
            ->where('user_id', $user->id)
            ->first();

        if (!$patToken) {
            return response()->json([
                'message' => 'PAT token not found or does not belong to you'
            ], 404);
        }

        $systems = SystemRegister::where('pat_token_id', $patTokenId)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'pat_token_id' => $patTokenId,
            'pat_token_name' => $patToken->name,
            'total_systems' => $systems->count(),
            'systems' => $systems,
        ]);
    }

    /**
     * Get system registers count and details by user ID
     */
    public function getByUser(Request $request, $userId)
    {
        $authenticatedUser = $request->user();
        
        // Only allow users to view their own data or implement admin check here
        if ($authenticatedUser->id != $userId) {
            return response()->json([
                'message' => 'Unauthorized to view other user\'s systems'
            ], 403);
        }

        $systems = SystemRegister::where('user_id', $userId)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get();

        // Group by PAT token to show breakdown
        $groupedByPat = $systems->groupBy('pat_token_id')->map(function ($group) {
            $patToken = PatToken::find($group->first()->pat_token_id);
            return [
                'pat_token_id' => $group->first()->pat_token_id,
                'pat_token_name' => $patToken ? $patToken->name : 'Unknown',
                'count' => $group->count(),
            ];
        })->values();

        return response()->json([
            'user_id' => $userId,
            'total_systems' => $systems->count(),
            'systems_by_pat_token' => $groupedByPat,
            'systems' => $systems,
        ]);
    }

    /**
     * Rules shared by the legacy and the /api/v1 register endpoints.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function systemFieldRules(): array
    {
        return [
            'system_name' => ['required', 'string', 'max:255'],
            'os_type' => ['required', 'string', 'max:100'],
            'ip_address' => ['required', 'string', 'max:45'],
            'distro' => ['nullable', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'string', 'max:512'],
            'metadata' => ['nullable', 'string'],
        ];
    }

    /**
     * workspace_id rule for register: 0/empty means none; a positive id must be an
     * existing workspace, and when $orgId is given, one of that organisation (null
     * workspace org counts as 200). Legacy store passes no org (any workspace).
     *
     * @return array<int, mixed>
     */
    public static function workspaceIdRule(?int $orgId = null): array
    {
        return [
            'nullable',
            'integer',
            'min:0',
            function (string $attribute, mixed $value, \Closure $fail) use ($orgId): void {
                $workspaceId = (int) $value;
                if ($workspaceId <= 0) {
                    return;
                }
                $workspace = Workspace::query()->find($workspaceId);
                if (!$workspace || ($orgId !== null && (int) ($workspace->org_id ?? 200) !== $orgId)) {
                    $fail('The selected workspace is invalid.');
                }
            },
        ];
    }

    /**
     * Creates a system for the PAT's user from validated $data. Shared by the legacy
     * and the /api/v1 register endpoints. Returns the 401 response without a PAT.
     */
    public function createSystem(Request $request, array $data)
    {
        $user = $request->user();
        $patToken = $request->attributes->get('patToken');

        if (!$patToken) {
            $token = $request->bearerToken();
            $hashedToken = $token ? hash('sha256', $token) : null;
            $patToken = $hashedToken ? PatToken::where('token', $hashedToken)->first() : null;
        }

        if (!$patToken) {
            return response()->json(['message' => 'PAT token required'], 401);
        }

        $resolvedOrgId = $user->org_id ?? $patToken->user?->org_id;
        $sessionWorkspaceId = null;
        if ($request->hasSession()) {
            $sessionWorkspaceId = $request->session()->has('selected_workspace_id')
                ? (int) $request->session()->get('selected_workspace_id')
                : null;
        }
        $resolvedWorkspaceId = isset($data['workspace_id']) && $data['workspace_id'] !== ''
            ? (int) $data['workspace_id']
            : (($sessionWorkspaceId !== null && $sessionWorkspaceId > 0) ? $sessionWorkspaceId : 0);

        return SystemRegister::create([
            'pat_token_id' => $patToken->id,
            'user_id' => $user->id,
            'org_id' => $resolvedOrgId,
            'workspace_id' => $resolvedWorkspaceId,
            'system_name' => $data['system_name'],
            'os_type' => $data['os_type'],
            'ip_address' => $data['ip_address'],
            'public_ip' => $data['public_ip'] ?? null,
            'public_facing' => (bool) ($data['public_facing'] ?? false),
            'description' => $data['description'] ?? null,
            'distro' => $data['distro'] ?? null,
            'version' => $data['version'] ?? null,
            'tags' => $data['tags'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'validation_hash' => $data['validation_hash'] ?? null,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(self::systemFieldRules() + [
            'workspace_id' => self::workspaceIdRule(),
            'public_ip' => ['nullable', 'string', 'max:45'],
            'public_facing' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string'],
            'validation_hash' => ['nullable', 'string', 'max:255'],
        ]);

        $system = $this->createSystem($request, $data);
        if (!$system instanceof SystemRegister) {
            return $system;
        }

        return response()->json([
            'message' => 'System registered successfully',
            'system' => [
                'id' => $system->id,
                'pat_token_id' => $system->pat_token_id,
                'user_id' => $system->user_id,
                'org_id' => $system->org_id,
                'workspace_id' => $system->workspace_id,
                'system_name' => $system->system_name,
                'os_type' => $system->os_type,
                'ip_address' => $system->ip_address,
                'public_ip' => $system->public_ip,
                'public_facing' => $system->public_facing,
                'description' => $system->description,
                'distro' => $system->distro,
                'version' => $system->version,
                'is_locked' => $system->is_locked,
                'tags' => $system->tags,
                'metadata' => $system->metadata,
                'validation_hash' => $system->validation_hash,
                'status' => $system->status,
                'created_at' => $system->created_at,
            ],
        ], 201);
    }

    /**
     * Deregister a system - change status to inactive
     */
    public function deregister(Request $request)
    {
        $systemId = $request->query('systemId')
            ?? $request->input('systemId')
            ?? $request->query('system_id')
            ?? $request->input('system_id');
        
        if (!$systemId) {
            return response()->json([
                'message' => 'systemId is required'
            ], 400);
        }
        
        $user = $request->user();

        $credentialError = $this->validatePasswordOrPin($request);
        if ($credentialError) {
            return $credentialError;
        }
        
        // Find the system by ID
        $system = SystemRegister::find($systemId);

        if (!$system) {
            return response()->json([
                'message' => 'System not found'
            ], 404);
        }

        // Verify the system belongs to the authenticated user
        if ($system->user_id != $user->id) {
            return response()->json([
                'message' => 'Unauthorized to deregister this system'
            ], 403);
        }

        return $this->deregisterSystem($system);
    }

    /**
     * Sets the system inactive. Shared by the legacy and the /api/v1 routes.
     */
    public function deregisterSystem(SystemRegister $system)
    {
        $system->status = 'inactive';
        $system->save();

        return response()->json([
            'message' => 'System deregistered successfully',
            'system_id' => $system->id,
            'status' => $system->status,
        ], 200);
    }

    /**
     * Sets the system active (200 with a message when it already is). Shared by the
     * legacy and the /api/v1 routes.
     */
    public function reactivateSystem(SystemRegister $system)
    {
        if ($system->status === 'active') {
            return response()->json([
                'message' => 'System is already active',
                'system_id' => $system->id,
                'status' => $system->status,
            ], 200);
        }

        $system->status = 'active';
        $system->save();

        return response()->json([
            'message' => 'System reactivated successfully',
            'system_id' => $system->id,
            'status' => $system->status,
        ], 200);
    }

    /**
     * Who may change a system from the host (/api/v1/systems/self/*): its owner, or an
     * admin of the system's organisation.
     */
    public function canManageSystem(User $user, SystemRegister $system): bool
    {
        if ((int) $system->user_id === (int) $user->id) {
            return true;
        }

        return $user->isAdmin() && (int) ($system->org_id ?? 200) === (int) ($user->org_id ?? 200);
    }

    /**
     * Replaces the system's key (validation_hash) and records it in the activity log.
     */
    public function rotateSystemKey(SystemRegister $system, string $newKey, User $actor): void
    {
        DB::transaction(function () use ($system, $newKey): void {
            $oldKey = (string) $system->validation_hash;

            // Copies of the key stored with this system's backups and services.
            foreach (['configuration_files', 'raw_data'] as $table) {
                DB::table($table)
                    ->where('system_register_id', $system->id)
                    ->where('validation_hash', $oldKey)
                    ->update(['validation_hash' => $newKey]);
            }
            DB::table('services')->where('system_id', $system->id)->update(['system_hash' => $newKey]);

            $system->validation_hash = $newKey;
            $system->save();
        });

        ActivityRecorder::record(
            (int) $actor->id,
            'system.key_rotated',
            'Rotated the key of system ' . $system->system_name,
            ActivityRecorder::SUCCESS,
            null,
            $system->workspace_id ? (int) $system->workspace_id : null,
            (int) $system->id,
        );
    }

    /**
     * Force deregister a system - change status to inactive
     * No bearer token; an admin of the system's organisation signs in with email + password/pin
     */
    public function deregisterForce(Request $request)
    {
        $systemId = $request->query('systemId')
            ?? $request->input('systemId')
            ?? $request->query('system_id')
            ?? $request->input('system_id');
        
        if (!$systemId) {
            return response()->json([
                'message' => 'systemId is required'
            ], 400);
        }

        return $this->forceDeregister($request, $systemId);
    }

    /**
     * Force deregister by an admin of the system's organisation, identified by
     * email + password/pin. Shared by the legacy and the /api/v1 routes.
     */
    public function forceDeregister(Request $request, mixed $systemId)
    {
        [$actor, $errorResponse] = $this->resolveForceActor($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ($forbidden = $this->forbidForceActor($actor)) {
            return $forbidden;
        }

        $system = $this->findForceTarget($actor, $systemId);

        if (!$system) {
            return response()->json([
                'message' => 'System not found'
            ], 404);
        }

        $system->status = 'inactive';
        $system->save();

        $this->logForceSystemAction($request, $actor, (int) $system->id, 'inactivate');

        return response()->json([
            'message' => 'System force deregistered successfully',
            'deregistered_by_user_id' => $actor->id,
            'system_id' => $system->id,
            'system_user_id' => $system->user_id,
            'status' => $system->status,
        ], 200);
    }

    /**
     * Reactivate a system using PAT token - change status from inactive to active
     * User-level endpoint: can only reactivate their own systems
     */
    public function reactive(Request $request)
    {
        $systemId = $request->query('systemId')
            ?? $request->input('systemId')
            ?? $request->query('system_id')
            ?? $request->input('system_id');
        
        if (!$systemId) {
            return response()->json([
                'message' => 'systemId is required'
            ], 400);
        }
        
        $user = $request->user();

        $credentialError = $this->validatePasswordOrPin($request);
        if ($credentialError) {
            return $credentialError;
        }
        
        // Find the system by ID
        $system = SystemRegister::find($systemId);

        if (!$system) {
            return response()->json([
                'message' => 'System not found'
            ], 404);
        }

        // Verify the system belongs to the authenticated user
        if ($system->user_id != $user->id) {
            return response()->json([
                'message' => 'Unauthorized to reactivate this system'
            ], 403);
        }

        return $this->reactivateSystem($system);
    }

    /**
     * Force reactivate any system - change status from inactive to active
     * ADMIN-LEVEL endpoint: no bearer token; an admin of the system's organisation signs in with email + password/pin
     */
    public function reactiveForce(Request $request)
    {
        $systemId = $request->query('systemId')
            ?? $request->input('systemId')
            ?? $request->query('system_id')
            ?? $request->input('system_id');
        
        if (!$systemId) {
            return response()->json([
                'message' => 'systemId is required'
            ], 400);
        }

        return $this->forceReactivate($request, $systemId);
    }

    /**
     * Force reactivate by an admin of the system's organisation, identified by
     * email + password/pin. Shared by the legacy and the /api/v1 routes.
     */
    public function forceReactivate(Request $request, mixed $systemId)
    {
        [$actor, $errorResponse] = $this->resolveForceActor($request);
        if ($errorResponse) {
            return $errorResponse;
        }

        if ($forbidden = $this->forbidForceActor($actor)) {
            return $forbidden;
        }

        $system = $this->findForceTarget($actor, $systemId);

        if (!$system) {
            return response()->json([
                'message' => 'System not found'
            ], 404);
        }

        // Check if system is already active
        if ($system->status === 'active') {
            return response()->json([
                'message' => 'System is already active',
                'reactivated_by_user_id' => $actor->id,
                'system_id' => $system->id,
                'system_user_id' => $system->user_id,
                'status' => $system->status,
            ], 200);
        }

        $system->status = 'active';
        $system->save();

        $this->logForceSystemAction($request, $actor, (int) $system->id, 'reactivate');

        return response()->json([
            'message' => 'System force reactivated successfully',
            'reactivated_by_user_id' => $actor->id,
            'system_id' => $system->id,
            'system_user_id' => $system->user_id,
            'status' => $system->status,
        ], 200);
    }
}
