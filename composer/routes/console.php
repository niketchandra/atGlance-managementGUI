<?php

use App\Services\BackupService;
use App\Support\InstallationState;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

$runBackup = function (string $type) {
    return function (BackupService $backups) use ($type) {
        $run = $backups->run($type);

        match ($run['status']) {
            BackupService::STATUS_SUCCESS => $this->info($run['message']),
            BackupService::STATUS_SKIPPED => $this->warn('Skipped: ' . $run['message']),
            default => $this->error('Failed: ' . $run['message']),
        };

        return $run['status'] === BackupService::STATUS_FAILED ? 1 : 0;
    };
};

Artisan::command('backup:config', $runBackup(BackupService::TYPE_CONFIG))
    ->purpose('Back up configuration files to S3');

Artisan::command('backup:portal', $runBackup(BackupService::TYPE_PORTAL))
    ->purpose('Back up the portal (.env, admin settings, database dump) to S3');

// Frequencies come from the Backup & Restore tab. The scheduler re-reads them on
// every schedule:run (cron) or each minute (schedule:work), so changes apply
// without a restart. Settings live in the DB, which may not exist yet on a
// fresh, un-installed instance.
if (InstallationState::isInstalled()) {
    try {
        $backups = app(BackupService::class);

        foreach (['backup:config' => BackupService::TYPE_CONFIG, 'backup:portal' => BackupService::TYPE_PORTAL] as $command => $type) {
            $expression = $backups->scheduleExpression($type);

            if ($expression !== null) {
                Schedule::command($command)->cron($expression)->withoutOverlapping(120);
            }
        }
    } catch (Throwable $e) {
        Log::warning('Backup schedules not registered', ['error' => $e->getMessage()]);
    }
}
