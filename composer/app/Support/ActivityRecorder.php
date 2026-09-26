<?php

namespace App\Support;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Writes a readable event to activity_logs. Recording never breaks the
 * request: failures are logged and swallowed, as in ActivityLogger.
 *
 * An explicit event marks the request so the ActivityLogger middleware does
 * not write a second, raw row for it.
 */
class ActivityRecorder
{
    public const REQUEST_FLAG = 'activity_recorded';

    public const SUCCESS = 'success';
    public const FAILURE = 'failure';

    public static function record(?int $userId, string $event, string $description, string $outcome = self::SUCCESS, ?Request $request = null): void
    {
        $request ??= app()->bound('request') ? app('request') : null;

        try {
            if (!Schema::hasTable('activity_logs')) {
                return;
            }

            ActivityLog::create([
                'user_id' => $userId,
                'event' => $event,
                'description' => mb_substr($description, 0, 255),
                'outcome' => $outcome,
                'method' => $request ? strtoupper($request->method()) : 'CLI',
                'path' => $request ? '/' . ltrim($request->path(), '/') : 'console',
                'status_code' => null,
                'ip_address' => $request?->ip(),
                'user_agent' => $request?->userAgent(),
            ]);

            $request?->attributes->set(self::REQUEST_FLAG, true);
        } catch (Throwable $e) {
            Log::warning('Activity event not recorded', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }
}
