<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'min:8', 'max:128', 'confirmed'],
            'dob' => ['nullable', 'date'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'dob' => $data['dob'] ?? null,
        ]);

        return response()->json([
            'message' => 'User registered successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'dob' => $user->dob,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:128'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        $user = User::where('email', $data['email'])->first();
        $passwordMatches = $user ? Hash::check($data['password'], $user->password_hash) : false;

        Log::info('api_login_attempt', [
            'email' => $data['email'],
            'db_default' => config('database.default'),
            'db_name' => DB::connection()->getDatabaseName(),
            'db_host' => config('database.connections.mysql.host'),
            'user_found' => (bool) $user,
            'password_hash_length' => $user ? strlen((string) $user->password_hash) : 0,
            'password_matches' => $passwordMatches,
        ]);

        if (!$user || !$passwordMatches) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        // Generate session token (temporary bearer token)
        $plainToken = Session::generateToken();
        $hashedToken = hash('sha256', $plainToken);

        // Create session record (expires in 24 hours)
        $session = Session::create([
            'user_id' => $user->id,
            'token' => $hashedToken,
            'expires_at' => Carbon::now()->addHours(24),
        ]);

        return response()->json([
            'access_token' => $plainToken,
            'token_type' => 'bearer',
            'expires_at' => $session->expires_at,
            'user' => [
                'id' => $user->id,
                'org_id' => $user->org_id,
                'rbac_id' => $user->rbac_id,
                'status' => $user->status,
            ],
            'message' => 'Session token is temporary. Use it to create permanent PAT tokens.',
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->header('Authorization');
        
        if ($token && str_starts_with($token, 'Bearer ')) {
            $plainToken = substr($token, 7);
            $hashedToken = hash('sha256', $plainToken);
            
            Session::where('token', $hashedToken)->delete();
        }

        return response()->noContent();
    }
}
