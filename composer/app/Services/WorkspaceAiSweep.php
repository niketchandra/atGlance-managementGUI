<?php

namespace App\Services;

use App\Jobs\ValidateConfigWithAi;
use App\Models\WorkspaceAiRun;
use App\Support\AiSettings;
use App\Support\WorkspaceSettings;
use Illuminate\Support\Facades\DB;

/**
 * The scheduled AI re-check of a workspace: queues one ValidateConfigWithAi job
 * for the latest version of every config file on the workspace's systems.
 * With "skip unchanged", files whose latest version already has a review are left out.
 */
class WorkspaceAiSweep
{
    /**
     * @return array{queued: int, skipped: int, message: string}
     */
    public function run(int $workspaceId, ?int $userId = null): array
    {
        $settings = WorkspaceSettings::get($workspaceId);

        if (!AiSettings::enabled()) {
            return $this->record($workspaceId, 0, 0, 'AI Connect is not enabled, so nothing was checked.');
        }

        $latestIds = DB::table('configuration_files as cf')
            ->join('system_register as sr', 'sr.id', '=', 'cf.system_register_id')
            ->where('sr.workspace_id', $workspaceId)
            ->groupBy('cf.system_register_id', 'cf.file_name')
            ->selectRaw('MAX(cf.id) as id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $alreadyReviewed = $settings['ai_sweep_skip_unchanged']
            ? DB::table('config_ai_validations')->whereIn('configuration_file_id', $latestIds)
                ->distinct()->pluck('configuration_file_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $toQueue = $latestIds->reject(fn (int $id) => in_array($id, $alreadyReviewed, true))->values();
        $queued = $toQueue->count();

        // The run row drives the progress bar; each job reports back to it.
        $run = WorkspaceAiRun::create([
            'workspace_id' => $workspaceId,
            'trigger' => $userId === null ? ConfigAiReviewer::TRIGGER_SCHEDULE : ConfigAiReviewer::TRIGGER_MANUAL,
            'total' => $queued,
            'started_by' => $userId,
            'finished_at' => $queued === 0 ? now() : null,
        ]);

        foreach ($toQueue as $id) {
            ValidateConfigWithAi::dispatch($id, ConfigAiReviewer::TRIGGER_SCHEDULE, (int) $run->id);
        }

        $skipped = count($alreadyReviewed);

        return $this->record($workspaceId, $queued, $skipped, sprintf(
            'Queued %d file%s for AI review%s.',
            $queued,
            $queued === 1 ? '' : 's',
            $skipped > 0 ? sprintf(', skipped %d already reviewed', $skipped) : ''
        ));
    }

    private function record(int $workspaceId, int $queued, int $skipped, string $message): array
    {
        $run = ['queued' => $queued, 'skipped' => $skipped, 'message' => $message, 'at' => now()->toIso8601String()];
        WorkspaceSettings::save($workspaceId, ['ai_last_sweep' => $run]);

        return $run;
    }
}
