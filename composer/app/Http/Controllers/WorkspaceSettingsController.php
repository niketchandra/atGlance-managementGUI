<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Models\WorkspaceAiRun;
use App\Models\WorkspaceBackupRun;
use App\Notifications\NotificationEvents;
use App\Services\BackupService;
use App\Services\WorkspaceAiSweep;
use App\Support\BackupSettings;
use App\Support\S3Settings;
use App\Support\WorkspaceSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The settings tabs on the workspace page.
 *
 * Workspace Tags: any workspace admin (Workspace::canBeManagedBy()).
 * General, Vulnerability Checks, Backups and Notifications: Workspace::allows() —
 * always for Admin-role workspace admins and the super admin; for User-role
 * workspace admins, as chosen on the Members tab.
 */
class WorkspaceSettingsController extends Controller
{
    /**
     * General tab: name and description. Status stays super-admin only.
     */
    public function updateGeneral(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'general');
        $isSuperAdmin = (int) (Auth::user()->rbac_id ?? 0) === 100;

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('workspaces', 'name')->ignore($workspace->id)],
            'description' => ['nullable', 'string', 'max:512'],
            'status' => [$isSuperAdmin ? 'required' : 'prohibited', Rule::in(['active', 'inactive'])],
        ]);

        $workspace->update(array_intersect_key($validated, array_flip(['name', 'description', 'status'])));

        return $this->back($workspace, 'general', 'Workspace updated.');
    }

    /**
     * Workspace Tags tab: key/value tags, e.g. env = prod. Systems in the workspace can pick them.
     */
    public function updateTags(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->manageable($workspaceId);

        $validated = $request->validate([
            'tags' => ['nullable', 'array', 'max:' . WorkspaceSettings::MAX_TAGS],
            'tags.*.key' => ['nullable', 'string', 'max:' . WorkspaceSettings::MAX_TAG_KEY_LENGTH, 'regex:/^[^=,]*$/'],
            'tags.*.value' => ['nullable', 'string', 'max:' . WorkspaceSettings::MAX_TAG_VALUE_LENGTH, 'regex:/^[^,]*$/'],
        ], [
            'tags.*.key.regex' => 'A tag key cannot contain "=" or ",".',
            'tags.*.value.regex' => 'A tag value cannot contain ",".',
        ]);

        WorkspaceSettings::save($workspace->id, ['tags' => WorkspaceSettings::normalizeTags($validated['tags'] ?? [])]);

        return $this->back($workspace, 'tags', 'Workspace tags saved.');
    }

    /**
     * Vulnerability Checks tab: automatic AI review on upload and on a schedule.
     */
    public function updateAi(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'vulnerability_checks');
        $validated = $this->validateSchedule($request, 'ai_sweep');

        WorkspaceSettings::save($workspace->id, [
            'ai_on_upload' => $request->boolean('ai_on_upload'),
            'ai_sweep_enabled' => $request->boolean('ai_sweep_enabled'),
            'ai_sweep_skip_unchanged' => $request->boolean('ai_sweep_skip_unchanged'),
        ] + $validated);

        return $this->back($workspace, 'vulnerability-checks', 'Vulnerability check settings saved.');
    }

    public function runAiSweep(int $workspaceId, WorkspaceAiSweep $sweep): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'vulnerability_checks');
        $run = $sweep->run($workspace->id, (int) Auth::id());

        return $this->back($workspace, 'vulnerability-checks', $run['message']);
    }

    /**
     * "Reset queue": stops the unfinished Vulnerability Checks runs of this workspace.
     * Reviews already saved are kept.
     */
    public function resetAiQueue(int $workspaceId): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'vulnerability_checks');
        $stopped = WorkspaceAiRun::cancelUnfinished($workspace->id, (int) Auth::id());

        return $this->back($workspace, 'vulnerability-checks', $stopped > 0
            ? 'Check queue reset. Files not reviewed yet will be skipped; finished reviews are kept.'
            : 'No check was in progress.');
    }

    /**
     * Progress of the latest Vulnerability Checks run, polled by the progress bar.
     */
    public function aiProgress(int $workspaceId): JsonResponse
    {
        $workspace = $this->manageable($workspaceId);
        $run = WorkspaceAiRun::where('workspace_id', $workspace->id)->latest('id')->first();

        return response()->json($run?->progress());
    }

    /**
     * Backups tab: schedule, destinations, retention and notes for the workspace backup.
     */
    public function updateBackup(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'backups');
        $schedule = $this->validateSchedule($request, 'backup');

        $validated = $request->validate([
            'backup_keep_local' => ['required', 'integer', 'min:1', 'max:' . BackupSettings::MAX_KEEP],
            'backup_keep_s3' => ['required', 'integer', 'min:1', 'max:' . BackupSettings::MAX_KEEP],
            'backup_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $toLocal = $request->boolean('backup_to_local');
        $toS3 = $request->boolean('backup_to_s3') && S3Settings::enabled();
        if ($request->boolean('backup_enabled') && !$toLocal && !$toS3) {
            throw ValidationException::withMessages(['backup_to_local' => 'Choose at least one destination: a local copy or S3.']);
        }

        WorkspaceSettings::save($workspace->id, [
            'backup_enabled' => $request->boolean('backup_enabled'),
            'backup_to_local' => $toLocal,
            'backup_to_s3' => $toS3,
            'backup_keep_local' => (int) $validated['backup_keep_local'],
            'backup_keep_s3' => (int) $validated['backup_keep_s3'],
            'backup_notes' => trim((string) ($validated['backup_notes'] ?? '')),
        ] + $schedule);

        return $this->back($workspace, 'backups', 'Backup settings saved.');
    }

    public function runBackup(int $workspaceId, BackupService $backups): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'backups');
        $run = $backups->runWorkspace($workspace->id, (int) Auth::id());

        return $run->status === BackupService::STATUS_SUCCESS
            ? $this->back($workspace, 'backups', 'Backup finished. ' . $run->message)
            : $this->back($workspace, 'backups', 'Backup failed: ' . $run->message);
    }

    public function downloadBackup(int $workspaceId, int $runId)
    {
        $workspace = $this->manageable($workspaceId);
        $run = WorkspaceBackupRun::where('workspace_id', $workspace->id)->findOrFail($runId);

        // Only paths this feature wrote: backups/workspace-{id}/...
        if (!$run->object_key || !$run->disk || !str_starts_with($run->object_key, BackupService::workspacePrefix($workspace->id))) {
            abort(404);
        }

        if ($run->disk === 's3' && !S3Settings::configureDisk()) {
            abort(404);
        }

        if (!Storage::disk($run->disk)->exists($run->object_key)) {
            return $this->back($workspace, 'backups', 'That backup file no longer exists (removed by retention).');
        }

        return Storage::disk($run->disk)->download($run->object_key, basename($run->object_key));
    }

    /**
     * Notifications tab: which events this workspace sends, and the default email
     * events for members who have not chosen their own.
     */
    public function updateNotifications(Request $request, int $workspaceId): RedirectResponse
    {
        $workspace = $this->permitted($workspaceId, 'notifications');
        $allowed = array_keys(NotificationEvents::forScope(NotificationEvents::SCOPE_WORKSPACE));

        $validated = $request->validate([
            'events' => ['nullable', 'array'],
            'events.*' => [Rule::in($allowed)],
            'member_email_default_events' => ['nullable', 'array'],
            'member_email_default_events.*' => [Rule::in($allowed)],
        ]);

        $events = array_values(array_unique($validated['events'] ?? []));

        WorkspaceSettings::save($workspace->id, [
            'events' => $events,
            // Members can only get events the workspace sends.
            'member_email_default_events' => array_values(array_intersect($validated['member_email_default_events'] ?? [], $events)),
        ]);

        return $this->back($workspace, 'notifications', 'Notification settings saved.');
    }

    /**
     * Frequency (or custom cron) fields named {prefix}_frequency / {prefix}_cron.
     */
    private function validateSchedule(Request $request, string $prefix): array
    {
        $frequencies = array_merge(array_keys(BackupSettings::FREQUENCIES), [BackupSettings::CUSTOM]);

        $validated = $request->validate([
            $prefix . '_frequency' => ['required', Rule::in($frequencies)],
            $prefix . '_cron' => ['nullable', 'string', 'max:100', 'required_if:' . $prefix . '_frequency,' . BackupSettings::CUSTOM],
        ]);

        $cron = trim((string) ($validated[$prefix . '_cron'] ?? ''));
        if ($validated[$prefix . '_frequency'] === BackupSettings::CUSTOM && !BackupSettings::isValidCron($cron)) {
            throw ValidationException::withMessages([$prefix . '_cron' => 'Enter a valid five-field cron expression, for example "0 2 * * *".']);
        }

        return [$prefix . '_frequency' => $validated[$prefix . '_frequency'], $prefix . '_cron' => $cron];
    }

    private function permitted(int $workspaceId, string $permission): Workspace
    {
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$workspace->allows(Auth::user(), $permission)) {
            abort(403, 'You do not have access to change this setting.');
        }

        return $workspace;
    }

    private function manageable(int $workspaceId): Workspace
    {
        $workspace = Workspace::findOrFail($workspaceId);
        if (!$workspace->canBeManagedBy(Auth::user())) {
            abort(403);
        }

        return $workspace;
    }

    private function back(Workspace $workspace, string $tab, string $message): RedirectResponse
    {
        $route = (int) (Auth::user()->rbac_id ?? 0) === 100 ? 'workspace.detail' : 'admin.workspaces.show';

        return redirect()
            ->route($route, ['workspaceId' => $workspace->id, 'tab' => $tab])
            ->with('success', $message);
    }
}
