<?php

use App\Services\BackupService;
use App\Services\LicenseClient;
use App\Support\InstallationState;
use App\Support\License;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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
    ->purpose('Back up configuration files and console files (local copies and/or S3)');

Artisan::command('backup:database', $runBackup(BackupService::TYPE_DATABASE))
    ->purpose('Back up the database as a gzipped SQL dump (local copies and/or S3)');

// Older name, kept so existing host crontabs keep working.
Artisan::command('backup:portal', $runBackup(BackupService::TYPE_DATABASE))
    ->purpose('Same as backup:database');

Artisan::command('activity:prune {--days= : Keep this many days (default ACTIVITY_RETENTION_DAYS, 180)}', function () {
    $days = (int) ($this->option('days') ?: config('app.activity_retention_days', 180));
    if ($days < 1) {
        $this->error('--days must be at least 1.');

        return 1;
    }

    $deleted = DB::table('activity_logs')->where('created_at', '<', now()->subDays($days))->delete();
    $this->info("Deleted {$deleted} activity log entries older than {$days} days.");

    return 0;
})->purpose('Delete activity log entries older than the retention period');

Artisan::command('license:check', function (LicenseClient $client) {
    $check = License::check($client);

    match ($check['result']) {
        'active' => $this->info($check['message']),
        'inactive' => $this->error('Licence deactivated: ' . $check['message']),
        default => $this->warn($check['message']),
    };

    if ($check['result'] === 'skipped') {
        Log::warning('Licence check skipped', ['message' => $check['message']]);
    }

    return 0;
})->purpose('Check the stored licence with atglance.live (check only) and update its status');

if (InstallationState::isInstalled()) {
    Schedule::command('activity:prune')->dailyAt('03:30')->withoutOverlapping();
    Schedule::command('license:check')->dailyAt('02:15')->withoutOverlapping();
}

// Frequencies come from the Backup & Restore tab. The scheduler re-reads them on
// every schedule:run (cron) or each minute (schedule:work), so changes apply
// without a restart. Settings live in the DB, which may not exist yet on a
// fresh, un-installed instance.
if (InstallationState::isInstalled()) {
    try {
        $backups = app(BackupService::class);

        foreach (['backup:config' => BackupService::TYPE_CONFIG, 'backup:database' => BackupService::TYPE_DATABASE] as $command => $type) {
            $expression = $backups->scheduleExpression($type);

            if ($expression !== null) {
                Schedule::command($command)->cron($expression)->withoutOverlapping(120);
            }
        }
    } catch (Throwable $e) {
        Log::warning('Backup schedules not registered', ['error' => $e->getMessage()]);
    }
}
