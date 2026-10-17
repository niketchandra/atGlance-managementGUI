<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Http\Request;

class SecurityEventController extends Controller
{
    /**
     * List security events for the organization.
     * Admin PAT required; scoped to caller's org (super admin: all).
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // Only admins (rbac 100/101) can view security events
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = SecurityEvent::query()
            ->select(['id', 'type', 'user_id', 'ip', 'details', 'created_at']);

        // Scope to organization (super admins see all)
        if (!$user->isSuperAdmin()) {
            $query->where('org_id', $user->org_id);
        }

        // Optional filters
        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        if ($since = $request->query('since')) {
            try {
                $query->where('created_at', '>=', \Carbon\Carbon::parse($since));
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Invalid since parameter'], 400);
            }
        }

        if ($until = $request->query('until')) {
            try {
                $query->where('created_at', '<=', \Carbon\Carbon::parse($until));
            } catch (\Throwable $e) {
                return response()->json(['message' => 'Invalid until parameter'], 400);
            }
        }

        if ($userId = $request->query('user_id')) {
            $query->where('user_id', $userId);
        }

        // Pagination
        $perPage = 50;
        $page = max(1, (int) ($request->query('page') ?? 1));

        $events = $query->orderBy('created_at', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        // Transform events to include user info and format response
        $eventData = $events->map(function (SecurityEvent $event) {
            return [
                'id' => $event->id,
                'type' => $event->type,
                'user' => $event->user_id ? [
                    'id' => $event->user_id,
                    'name' => $event->user?->name ?? 'Unknown',
                ] : null,
                'ip' => $event->ip,
                'details' => $event->details ?? [],
                'created_at' => $event->created_at->toIso8601String(),
            ];
        })->all();

        $response = [
            'events' => $eventData,
        ];

        // Add next page link if there are more pages
        if ($events->hasMorePages()) {
            $response['next'] = $events->nextPageUrl();
        }

        return response()->json($response);
    }

    /**
     * List currently locked accounts in the organization.
     * Admin PAT required; scoped to caller's org (super admin: all).
     */
    public function lockedAccounts(Request $request)
    {
        $user = $request->user();

        // Only admins can view locked accounts
        if (!$user || !$user->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $query = User::query()
            ->select(['id', 'name', 'email', 'force_locked_until', 'force_lock_level'])
            ->whereNotNull('force_locked_until')
            ->where('force_locked_until', '>', now());

        // Scope to organization
        if (!$user->isSuperAdmin()) {
            $query->where('org_id', $user->org_id);
        }

        $locked = $query->get()->map(function (User $user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'locked_until' => $user->force_locked_until->toIso8601String(),
                'lock_level' => $user->force_lock_level,
            ];
        });

        return response()->json(['locked_accounts' => $locked]);
    }

    /**
     * Unlock an account (admin action).
     * Admin PAT required; scoped to caller's org (super admin: all).
     */
    public function unlock(Request $request)
    {
        $admin = $request->user();

        // Only admins can unlock accounts
        if (!$admin || !$admin->isAdmin()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $userId = (int) $request->input('user_id');
        if (!$userId) {
            return response()->json(['message' => 'user_id is required'], 422);
        }

        $user = User::find($userId);
        if (!$user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Scope check: admins can only unlock users in their org
        if (!$admin->isSuperAdmin() && $user->org_id !== $admin->org_id) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Unlock the account
        \App\Support\ForceLockout::unlock($user, $admin);

        return response()->json([
            'message' => 'Account unlocked',
            'user_id' => $user->id,
        ]);
    }
}
