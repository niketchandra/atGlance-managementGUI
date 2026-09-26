<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A user's browser sessions, read from the database session store.
 *
 * Ending a session deletes its row and rotates the remember-me token. Without
 * the rotation, a device with a "remember me" cookie would sign straight back in.
 */
class WebSessions
{
    public static function isAvailable(): bool
    {
        return config('session.driver') === 'database' && Schema::hasTable(self::table());
    }

    /**
     * @return Collection<int, array{id: string, device: string, ip: ?string, last_active: Carbon, current: bool}>
     */
    public static function forUser(User $user, string $currentId): Collection
    {
        if (!self::isAvailable()) {
            return collect();
        }

        return DB::table(self::table())
            ->where('user_id', $user->id)
            ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'device' => UserAgent::describe($row->user_agent),
                'ip' => $row->ip_address,
                'last_active' => Carbon::createFromTimestamp($row->last_activity),
                'current' => hash_equals($currentId, (string) $row->id),
            ])
            ->sortByDesc('current')
            ->values();
    }

    public static function count(User $user): int
    {
        return self::isAvailable()
            ? DB::table(self::table())
                ->where('user_id', $user->id)
                ->where('last_activity', '>=', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())
                ->count()
            : 0;
    }

    /**
     * Ends one of the user's other sessions. Returns false when it is not theirs or is the current one.
     */
    public static function end(User $user, string $sessionId, Request $request): bool
    {
        if (!self::isAvailable() || hash_equals($request->session()->getId(), $sessionId)) {
            return false;
        }

        $deleted = DB::table(self::table())->where('id', $sessionId)->where('user_id', $user->id)->delete();
        if ($deleted === 0) {
            return false;
        }

        self::rotateRememberToken($user, $request);

        return true;
    }

    /**
     * Ends every session of the user except the current one. Returns how many ended.
     */
    public static function endOthers(User $user, Request $request): int
    {
        $ended = self::isAvailable()
            ? DB::table(self::table())
                ->where('user_id', $user->id)
                ->where('id', '!=', $request->session()->getId())
                ->delete()
            : 0;

        self::rotateRememberToken($user, $request);

        return $ended;
    }

    /**
     * Invalidates every "remember me" cookie, then gives this browser a fresh
     * one if it had one, so only the other devices are affected.
     */
    private static function rotateRememberToken(User $user, Request $request): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();

        $guard = Auth::guard('web');
        if (method_exists($guard, 'getRecallerName') && $request->cookies->has($guard->getRecallerName())) {
            $guard->login($user, true);
        }
    }

    private static function table(): string
    {
        return (string) config('session.table', 'web_sessions');
    }
}
