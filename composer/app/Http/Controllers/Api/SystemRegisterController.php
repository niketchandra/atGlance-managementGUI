<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PatToken;
use App\Models\SystemRegister;
use Illuminate\Http\Request;

class SystemRegisterController extends Controller
{
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

    public function store(Request $request)
    {
        $data = $request->validate([
            'system_name' => ['required', 'string', 'max:255'],
            'os_type' => ['required', 'string', 'max:100'],
            'ip_address' => ['required', 'string', 'max:45'],
            'tags' => ['nullable', 'string', 'max:512'],
            'metadata' => ['nullable', 'string'],
            'validation_hash' => ['nullable', 'string', 'max:255'],
        ]);

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

        $system = SystemRegister::create([
            'pat_token_id' => $patToken->id,
            'user_id' => $user->id,
            'org_id' => $resolvedOrgId,
            'system_name' => $data['system_name'],
            'os_type' => $data['os_type'],
            'ip_address' => $data['ip_address'],
            'tags' => $data['tags'] ?? null,
            'metadata' => $data['metadata'] ?? null,
            'validation_hash' => $data['validation_hash'] ?? null,
        ]);

        return response()->json([
            'message' => 'System registered successfully',
            'system' => [
                'id' => $system->id,
                'pat_token_id' => $system->pat_token_id,
                'user_id' => $system->user_id,
                'org_id' => $system->org_id,
                'system_name' => $system->system_name,
                'os_type' => $system->os_type,
                'ip_address' => $system->ip_address,
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

        // Update the status to inactive
        $system->status = 'inactive';
        $system->save();

        return response()->json([
            'message' => 'System deregistered successfully',
            'system_id' => $system->id,
            'status' => $system->status,
        ], 200);
    }

    /**
     * Force deregister any system - change status to inactive
     * Requires bearer token authentication
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

        $user = $request->user();
        
        // Find the system by ID
        $system = SystemRegister::find($systemId);

        if (!$system) {
            return response()->json([
                'message' => 'System not found'
            ], 404);
        }

        // Force deregister - no ownership check required
        $system->status = 'inactive';
        $system->save();

        return response()->json([
            'message' => 'System force deregistered successfully',
            'deregistered_by_user_id' => $user->id,
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

        // Check if system is already active
        if ($system->status === 'active') {
            return response()->json([
                'message' => 'System is already active',
                'system_id' => $system->id,
                'status' => $system->status,
            ], 200);
        }

        // Update the status to active
        $system->status = 'active';
        $system->save();

        return response()->json([
            'message' => 'System reactivated successfully',
            'system_id' => $system->id,
            'status' => $system->status,
        ], 200);
    }

    /**
     * Force reactivate any system - change status from inactive to active
     * ADMIN-LEVEL endpoint: requires session bearer token, no ownership check
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

        $user = $request->user();
        
        // Find the system by ID
        $system = SystemRegister::find($systemId);

        if (!$system) {
            return response()->json([
                'message' => 'System not found'
            ], 404);
        }

        // Check if system is already active
        if ($system->status === 'active') {
            return response()->json([
                'message' => 'System is already active',
                'reactivated_by_user_id' => $user->id,
                'system_id' => $system->id,
                'system_user_id' => $system->user_id,
                'status' => $system->status,
            ], 200);
        }

        // Force reactivate - no ownership check required
        $system->status = 'active';
        $system->save();

        return response()->json([
            'message' => 'System force reactivated successfully',
            'reactivated_by_user_id' => $user->id,
            'system_id' => $system->id,
            'system_user_id' => $system->user_id,
            'status' => $system->status,
        ], 200);
    }
}
