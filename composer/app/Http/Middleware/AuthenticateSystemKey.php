<?php

namespace App\Http\Middleware;

use App\Models\ServiceAccount;
use App\Models\SystemRegister;
use App\Models\User;
use App\Support\ApiIdentity;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the calling host for /api/v1 system-scoped routes (alias atglance.system,
 * always after auth.pat). The host sends X-Atglance-System-Id and X-Atglance-System-Key;
 * the key is checked against the system's stored hash (SystemRegister::matchesKey, which
 * falls back to validation_hash for a row not hashed yet). Every mismatch answers the
 * same 404 so the response does not reveal which systems exist. A person must be the
 * owner, an admin or a member of the system's workspace; a service account must be the
 * one the system runs as, with an active grant on its workspace. Status is not checked,
 * so an inactive system can still be reactivated.
 */
class AuthenticateSystemKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $systemId = trim((string) $request->header('X-Atglance-System-Id', ''));
        $key = (string) $request->header('X-Atglance-System-Key', '');

        if ($systemId === '' || $key === '') {
            return response()->json([
                'message' => 'X-Atglance-System-Id and X-Atglance-System-Key headers are required',
            ], 400);
        }

        $identity = ApiIdentity::current($request);
        $user = $request->user();
        $system = preg_match('/^[0-9]{1,18}$/', $systemId) ? SystemRegister::find((int) $systemId) : null;

        $keyMatches = $system !== null
            && $system->matchesKey($key)
            && $identity !== null
            && (int) ($system->org_id ?? 200) === $identity->orgId();

        $allowed = $keyMatches && ($identity->isServiceAccount()
            ? self::accountMayUseSystem($identity->serviceAccount, $system)
            : ($user !== null && self::userMayUseSystem($user, $system)));

        if (!$allowed) {
            return response()->json(['message' => 'System not found'], 404);
        }

        $request->attributes->set('system', $system);

        return $next($request);
    }

    /**
     * Who may act as a system (same organisation already checked): its owner, an admin,
     * or a member of the system's workspace. A system outside any workspace (id 0) is
     * owner or admin only. Membership is read on every request, so removal is immediate.
     */
    public static function userMayUseSystem(User $user, SystemRegister $system): bool
    {
        if ((int) $system->user_id === (int) $user->id || $user->isAdmin()) {
            return true;
        }

        $workspaceId = (int) ($system->workspace_id ?? 0);

        return $workspaceId > 0 && $user->workspaces()->whereKey($workspaceId)->exists();
    }

    /**
     * A service account acts only for systems it owns, and only while it holds an active
     * grant on the system's workspace (any permission; each route checks its own).
     */
    public static function accountMayUseSystem(ServiceAccount $account, SystemRegister $system): bool
    {
        return (int) $system->service_account_id === (int) $account->id
            && $account->isActive()
            && $account->activeGrantFor((int) ($system->workspace_id ?? 0)) !== null;
    }
}
