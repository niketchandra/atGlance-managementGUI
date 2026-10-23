<?php

namespace App\Jobs;

use App\Models\WorkspaceAiRun;
use App\Services\ConfigAiReviewer;
use App\Support\AiSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatic AI check of one configuration file, queued on upload or by the
 * workspace schedule. Only the latest version of a file on a system is checked:
 * when a newer upload arrived before the job ran, the job does nothing.
 *
 * One try only, so a failing provider is not billed again by retries; the
 * timeout covers ConfigAiValidator's own 180 s request timeout.
 */
class ValidateConfigWithAi implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;
    public int $timeout = 200;

    /**
     * @param int|null $runId the Vulnerability Checks run (WorkspaceAiRun) to report progress to
     */
    public function __construct(
        public int $configurationFileId,
        public string $trigger = ConfigAiReviewer::TRIGGER_UPLOAD,
        public ?int $runId = null,
    ) {
    }

    public function handle(ConfigAiReviewer $reviewer): void
    {
        // "Reset queue" cancelled this run: end without calling the AI or counting.
        if ($this->runId !== null && WorkspaceAiRun::isCancelled($this->runId)) {
            return;
        }

        $outcome = 'skipped';

        try {
            if (AiSettings::enabled() && self::isLatestVersion($this->configurationFileId)) {
                $reviewer->review($this->configurationFileId, null, $this->trigger);
                $outcome = 'done';
            }
        } catch (Throwable $e) {
            $outcome = 'failed';
            Log::warning('Automatic AI validation failed', [
                'configuration_file_id' => $this->configurationFileId,
                'trigger' => $this->trigger,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($this->runId !== null) {
                WorkspaceAiRun::recordJob($this->runId, $outcome);
            }
        }
    }

    /**
     * A job killed by the worker (timeout) still counts, so the progress bar can finish.
     */
    public function failed(?Throwable $exception): void
    {
        if ($this->runId !== null && !WorkspaceAiRun::isCancelled($this->runId)) {
            WorkspaceAiRun::recordJob($this->runId, 'failed');
        }
    }

    /**
     * Whether no newer upload of the same file exists on the same system.
     */
    public static function isLatestVersion(int $configurationFileId): bool
    {
        $config = DB::table('configuration_files')->where('id', $configurationFileId)
            ->first(['id', 'system_register_id', 'file_name']);

        if (!$config) {
            return false;
        }

        return !DB::table('configuration_files')
            ->where('system_register_id', $config->system_register_id)
            ->where('file_name', $config->file_name)
            ->where('id', '>', $config->id)
            ->exists();
    }
}
