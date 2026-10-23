<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateUserJob;
use App\Jobs\UpdateUserJob;
use App\Jobs\DeleteUserJob;
use App\Models\User;
use App\Services\DatabaseCircuitBreaker;
use App\Support\License;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private DatabaseCircuitBreaker $circuitBreaker;

    public function __construct(DatabaseCircuitBreaker $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    /** Only an admin (rbac_id 100/101) may manage users. Returns the caller. */
    private function authorizeAdmin(Request $request): User
    {
        $caller = $request->user();
        abort_unless($caller !== null, 401, 'Authentication required');
        abort_unless(in_array((int) $caller->rbac_id, [100, 101], true), 403, 'Administrator role required');

        return $caller;
    }

    /** A user may only act within their own organisation. */
    private function assertSameOrg(User $caller, User $target): void
    {
        abort_unless((int) $target->org_id === (int) $caller->org_id, 403, 'User is outside your organisation');
    }

    /** A super administrator (rbac_id 100) may not be modified by anyone else. */
    private function guardSuperAdminTarget(User $caller, User $target): void
    {
        if ((int) $target->rbac_id === 100 && (int) $caller->id !== (int) $target->id) {
            abort(403, 'A super administrator cannot be modified by another user');
        }
    }

    public function index(Request $request)
    {
        $caller = $this->authorizeAdmin($request);

        try {
            // Read operations with circuit breaker; scoped to the caller's organisation.
            return $this->circuitBreaker->query(function () use ($caller) {
                return User::query()->where('org_id', $caller->org_id)->get();
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Database service is temporarily unavailable',
                'message' => 'Please try again later',
                'circuit_state' => $this->circuitBreaker->getState()
            ], 503);
        }
    }

    public function store(Request $request)
    {
        $caller = $this->authorizeAdmin($request);

        if (!License::canAddUser()) {
            return response()->json(['message' => License::userLimitMessage(), 'code' => License::isActive() ? 'USER_LIMIT_REACHED' : 'E_NO_LICENSE'], 403);
        }

        $baseRules = [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'dob' => ['required', 'date'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ];

        $requestId = Str::uuid()->toString();

        try {
            $rules = $baseRules;

            if ($this->circuitBreaker->isAvailable()) {
                $rules['email'][] = 'unique:users,email';
            }

            $data = $request->validate($rules);
        } catch (\Exception $e) {
            // If DB-backed validation fails, fall back to basic validation and queue.
            $data = $request->validate($baseRules);

            $userData = [
                'name' => $data['name'],
                'email' => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'org_id' => $caller->org_id,
            ];

            CreateUserJob::dispatch($userData, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database validation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'dob' => $data['dob'],
            'password_hash' => Hash::make($data['password']),
            'org_id' => $caller->org_id,
        ];

        // Check if database is available
        if (!$this->circuitBreaker->isAvailable()) {
            // Queue the operation
            CreateUserJob::dispatch($userData, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database service is temporarily unavailable. Request has been queued.',
                'request_id' => $requestId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        try {
            // Try to create user directly with circuit breaker
            $user = $this->circuitBreaker->query(function () use ($userData) {
                return User::create($userData);
            });

            return response()->json($user, 201);

        } catch (\Exception $e) {
            // Circuit breaker triggered - queue the operation
            CreateUserJob::dispatch($userData, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database operation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }
    }

    public function show(Request $request, User $user)
    {
        $caller = $this->authorizeAdmin($request);
        $this->assertSameOrg($caller, $user);

        try {
            // Read operations with circuit breaker
            return $this->circuitBreaker->query(function () use ($user) {
                return $user;
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Database service is temporarily unavailable',
                'message' => 'Please try again later',
                'circuit_state' => $this->circuitBreaker->getState()
            ], 503);
        }
    }

    public function update(Request $request, User $user)
    {
        $caller = $this->authorizeAdmin($request);
        $this->assertSameOrg($caller, $user);
        $this->guardSuperAdminTarget($caller, $user);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'dob' => ['sometimes', 'date'],
            'password' => ['sometimes', 'string', 'min:8', 'max:128'],
        ]);

        if (array_key_exists('password', $data)) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $requestId = Str::uuid()->toString();

        // Check if database is available
        if (!$this->circuitBreaker->isAvailable()) {
            UpdateUserJob::dispatch($user->id, $data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database service is temporarily unavailable. Request has been queued.',
                'request_id' => $requestId,
                'user_id' => $user->id,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        try {
            // Try to update user directly with circuit breaker
            $updatedUser = $this->circuitBreaker->query(function () use ($user, $data) {
                $user->fill($data);
                $user->save();
                return $user;
            });

            return response()->json($updatedUser);

        } catch (\Exception $e) {
            // Circuit breaker triggered - queue the operation
            UpdateUserJob::dispatch($user->id, $data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database operation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'user_id' => $user->id,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }
    }

    public function destroy(Request $request, User $user)
    {
        $caller = $this->authorizeAdmin($request);
        $this->assertSameOrg($caller, $user);
        abort_if((int) $user->rbac_id === 100, 403, 'A super administrator cannot be deleted via the API');

        $requestId = Str::uuid()->toString();
        $userId = $user->id;

        // Check if database is available
        if (!$this->circuitBreaker->isAvailable()) {
            DeleteUserJob::dispatch($userId, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database service is temporarily unavailable. Request has been queued.',
                'request_id' => $requestId,
                'user_id' => $userId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        try {
            // Try to delete user directly with circuit breaker
            $this->circuitBreaker->query(function () use ($user) {
                $user->delete();
            });

            return response()->noContent();

        } catch (\Exception $e) {
            // Circuit breaker triggered - queue the operation
            DeleteUserJob::dispatch($userId, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database operation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'user_id' => $userId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }
    }
}
