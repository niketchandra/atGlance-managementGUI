<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateUserJob;
use App\Jobs\UpdateUserJob;
use App\Jobs\DeleteUserJob;
use App\Models\User;
use App\Services\DatabaseCircuitBreaker;
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

    public function index()
    {
        try {
            // Read operations with circuit breaker
            return $this->circuitBreaker->query(function () {
                return User::query()->get();
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
            'dob' => $data['dob'],
            'password_hash' => Hash::make($data['password']),
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

    public function show(User $user)
    {
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

    public function destroy(User $user)
    {
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
