<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Services\BackupService;
use App\Services\RestoreService;
use App\Support\BackupSettings;
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
     * Only the super-admin may replace the whole database (database and older portal backups).
     */
    private const DATABASE_RESTORE_RBAC_ID = 100;

    /**
     * Backup types listed in each section of the Backup & Restore tab.
     */
    private const SECTION_TYPES = [
        'database' => [BackupService::TYPE_DATABASE, BackupService::TYPE_PORTAL],
        'files' => [BackupService::TYPE_CONFIG],
    ];

    public function __construct(private RestoreService $restores)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $section = (string) $request->query('section', '');
        $types = self::SECTION_TYPES[$section] ?? array_merge(...array_values(self::SECTION_TYPES));

        $result = $this->restores->listBackups($types);

        return response()->json([
            'backups' => $result['backups'],
            'errors' => $result['errors'],
            'can_restore_database' => $this->canRestoreDatabase(),
            // Kept for older scripts.
            'can_restore_portal' => $this->canRestoreDatabase(),
        ]);
    }

    /**
     * Runs one backup now, with the saved destinations and retention, even when
     * its schedule is turned off.
     */
    public function run(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(BackupSettings::TYPES)],
        ]);

        $run = app(BackupService::class)->run($validated['type'], true);
        $label = BackupSettings::LABELS[$validated['type']];
        $back = redirect()->route('admin.settings', ['tab' => 'backup-restore']);

        return match ($run['status']) {
            BackupService::STATUS_SUCCESS => $back->with('success', $label . ' finished. ' . $run['message']),
            BackupService::STATUS_SKIPPED => $back->withErrors(['backup_run' => $label . ' did not run: ' . $run['message']]),
            default => $back->withErrors(['backup_run' => $label . ' failed: ' . $run['message']]),
        };
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

        if ($type === BackupService::TYPE_PORTAL && !$this->canRestoreDatabase()) {
            return $back->withErrors(['restore' => 'Only the super admin can restore a portal backup.']);
        }

        if ($type === BackupService::TYPE_DATABASE && !$this->canRestoreDatabase()) {
            return $back->withErrors(['restore' => 'Only the super admin can restore a database backup.']);
        }

        try {
            $result = $this->restores->restore($validated['source'], $validated['path']);
        } catch (Throwable $e) {
            Log::error('Restore failed', ['source' => $validated['source'], 'path' => $validated['path'], 'error' => $e->getMessage()]);
            $this->logRestore($request, $user->id, 500, $validated, $type, ['error' => $e->getMessage()]);

            return $back->withErrors(['restore' => 'Restore failed: ' . $e->getMessage()]);
        }

        $this->logRestore($request, $user->id, 200, $validated, $type, $result);

        $message = match ($type) {
            BackupService::TYPE_CONFIG => sprintf(
                'Configuration files restored (%d config files and %d console files written).',
                $result['summary']['files_written'],
                $result['summary']['console_files_written'] ?? 0
            ),
            BackupService::TYPE_DATABASE => 'Database restored. Sign in again if your session ended.',
            default => 'Portal restored. Restart the app containers so the restored .env takes effect.',
        };

        return $back->with('success', $message . ' Snapshot of the previous state: ' . $result['snapshot']);
    }

    private function canRestoreDatabase(): bool
    {
        return (int) (Auth::user()->rbac_id ?? 0) === self::DATABASE_RESTORE_RBAC_ID;
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
