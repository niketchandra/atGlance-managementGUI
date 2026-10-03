<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One Vulnerability Checks run of a workspace ("Run check now" or the schedule).
 * Each queued ValidateConfigWithAi job adds 1 to done, failed or skipped when it
 * ends, so the workspace page can show a progress bar.
 */
class WorkspaceAiRun extends Model
{
    protected $fillable = [
        'workspace_id',
        'trigger',
        'total',
        'done',
        'failed',
        'skipped',
        'started_by',
        'finished_at',
        'cancelled_at',
        'cancelled_by',
    ];

    protected function casts(): array
    {
        return [
            'finished_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Records one finished job: $outcome is 'done', 'failed' or 'skipped'.
     * Atomic, because several queue workers may finish jobs at the same time.
     */
    public static function recordJob(int $runId, string $outcome): void
    {
        if (!in_array($outcome, ['done', 'failed', 'skipped'], true)) {
            return;
        }

        DB::table('workspace_ai_runs')->where('id', $runId)->increment($outcome, 1, ['updated_at' => now()]);
        DB::table('workspace_ai_runs')
            ->where('id', $runId)
            ->whereNull('finished_at')
            ->whereRaw('done + failed + skipped >= total')
            ->update(['finished_at' => now()]);
    }

    public static function isCancelled(int $runId): bool
    {
        return DB::table('workspace_ai_runs')->where('id', $runId)->whereNotNull('cancelled_at')->exists();
    }

    /**
     * Stops every unfinished run of the workspace. Their queued jobs then end
     * without calling the AI (ValidateConfigWithAi checks isCancelled()).
     *
     * @return int how many runs were stopped
     */
    public static function cancelUnfinished(int $workspaceId, ?int $userId): int
    {
        return DB::table('workspace_ai_runs')
            ->where('workspace_id', $workspaceId)
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->update(['cancelled_at' => now(), 'cancelled_by' => $userId, 'finished_at' => now(), 'updated_at' => now()]);
    }

    /**
     * @return array{id: int, total: int, processed: int, done: int, failed: int, skipped: int, percent: int, finished: bool, started_at: ?string, finished_at: ?string}
     */
    public function progress(): array
    {
        $processed = min($this->total, $this->done + $this->failed + $this->skipped);

        return [
            'id' => (int) $this->id,
            'total' => (int) $this->total,
            'processed' => $processed,
            'done' => (int) $this->done,
            'failed' => (int) $this->failed,
            'skipped' => (int) $this->skipped,
            'percent' => $this->total > 0 ? (int) floor($processed * 100 / $this->total) : 100,
            'finished' => $this->finished_at !== null || $processed >= $this->total,
            'cancelled' => $this->cancelled_at !== null,
            'started_at' => $this->created_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
