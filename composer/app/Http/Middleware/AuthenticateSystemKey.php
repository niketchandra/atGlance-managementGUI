<?php

namespace App\Http\Middleware;

use App\Models\SystemRegister;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Identifies the calling host for /api/v1 system-scoped routes (alias atglance.system,
 * always after auth.pat). The host sends X-Atglance-System-Id and X-Atglance-System-Key;
 * the key is the system's validation_hash. Every mismatch answers the same 404 so the
 * response does not reveal which systems exist. Status is not checked, so an inactive
 * system can still be reactivated.
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

        $user = $request->user();
        $system = preg_match('/^[0-9]{1,18}$/', $systemId) ? SystemRegister::find((int) $systemId) : null;

        if (
            $user === null
            || $system === null
            || empty($system->validation_hash)
            || !hash_equals((string) $system->validation_hash, $key)
            || (int) ($system->org_id ?? 200) !== (int) ($user->org_id ?? 200)
        ) {
            return response()->json(['message' => 'System not found'], 404);
        }

        $request->attributes->set('system', $system);

        return $next($request);
    }
}
