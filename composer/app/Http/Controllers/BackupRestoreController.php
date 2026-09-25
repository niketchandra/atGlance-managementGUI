<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\BackupService;
use App\Services\RestoreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

class BackupRestoreController extends Controller
{
    /**
     * Only the super-admin may replace the whole portal (DB and .env).
     */
    private const PORTAL_RESTORE_RBAC_ID = 100;

    public function __construct(private RestoreService $restores)
    {
    }

    public function index(): JsonResponse
    {
        $result = $this->restores->listBackups();

        return response()->json([
            'backups' => $result['backups'],
            'errors' => $result['errors'],
            'can_restore_portal' => $this->canRestorePortal(),
        ]);
    }

    public function restore(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'source' => ['required', Rule::in([RestoreService::SOURCE_S3, RestoreService::SOURCE_LOCAL])],
            'path' => ['required', 'string', 'max:512'],
            'password' => ['required', 'string'],
            'confirm_overwrite' => ['accepted'],
        ], [
            'confirm_overwrite.accepted' => 'Confirm that current data will be overwritten.',
        ]);

        $back = redirect()->route('admin.settings', ['tab' => 'backup-restore']);

        $type = $this->restores->typeFor($validated['source'], $validated['path']);
        if ($type === null) {
            return $back->withErrors(['restore' => 'Select a backup from the list.']);
        }

        $user = Auth::user();
        $passwordHash = $user->password_hash ?? $user->password;
        if (!$passwordHash || !Hash::check($validated['password'], $passwordHash)) {
            return $back->withErrors(['restore' => 'Password is incorrect.']);
        }

        if ($type === BackupService::TYPE_PORTAL && !$this->canRestorePortal()) {
            return $back->withErrors(['restore' => 'Only the super admin can restore a portal backup.']);
        }

        try {
            $result = $this->restores->restore($validated['source'], $validated['path']);
        } catch (Throwable $e) {
            Log::error('Restore failed', ['source' => $validated['source'], 'path' => $validated['path'], 'error' => $e->getMessage()]);
            $this->logRestore($request, $user->id, 500, $validated, $type, ['error' => $e->getMessage()]);

            return $back->withErrors(['restore' => 'Restore failed: ' . $e->getMessage()]);
        }

        $this->logRestore($request, $user->id, 200, $validated, $type, $result);

        $message = $type === BackupService::TYPE_CONFIG
            ? sprintf('Configuration files restored (%d files written).', $result['summary']['files_written'])
            : 'Portal restored. Restart the app containers so the restored .env takes effect.';

        return $back->with('success', $message . ' Snapshot of the previous state: ' . $result['snapshot']);
    }

    private function canRestorePortal(): bool
    {
        return (int) (Auth::user()->rbac_id ?? 0) === self::PORTAL_RESTORE_RBAC_ID;
    }

    private function logRestore(Request $request, int $userId, int $statusCode, array $validated, string $type, array $details): void
    {
        $payload = json_encode([
            'action' => 'restore',
            'type' => $type,
            'source' => $validated['source'],
            'backup' => $validated['path'],
        ] + $details, JSON_UNESCAPED_SLASHES);

        try {
            ActivityLog::create([
                'user_id' => $userId,
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'status_code' => $statusCode,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_payload' => $payload,
            ]);
        } catch (Throwable $e) {
            Log::warning('Restore activity log failed', ['error' => $e->getMessage()]);
        }
    }
}
