<?php

use App\Services\BackupService;
use App\Services\WorkspaceAiSweep;
use App\Support\InstallationState;
use App\Support\License;
use App\Support\Licensing\Entitlements;
use App\Support\Licensing\LicenseException;
use App\Support\WorkspaceSettings;
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
        if (!Entitlements::isFeatureEnabled('backup_restore')) {
            $this->warn(Entitlements::notLicensedMessage('backup_restore'));

            return 0;
        }
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

// Startup and hourly check of the stored licence file (offline, without the 3-hour rule).
// Also records the current time, which detects a clock moved back. Exit code 1 when the
// console is not licensed; the entrypoint only prints the result.
Artisan::command('license:verify', function () {
    License::reset();
    $summary = License::summary();

    if ($summary['active']) {
        $limit = fn (?int $max) => $max === 0 ? 'unlimited' : (string) $max;
        $this->info("Licence active: {$summary['customer']} ({$summary['plan']}, {$summary['policy_type']} policy, {$limit($summary['max_nodes'])} nodes, {$limit($summary['max_users'])} users), expires {$summary['expires_at']} UTC, {$summary['days_left']} days left.");
        if ($banner = License::banner()) {
            $this->warn($banner['text']);
        }
    } elseif ($summary['state'] === License::NONE) {
        $this->warn('No licence file is installed. The console is read-only until one is uploaded in Admin Settings > Licence.');
    } else {
        $this->error("Licence not valid ({$summary['error']}): {$summary['error_message']} The console is read-only.");
    }
    if ($summary['clock_warning']) {
        $this->warn(LicenseException::MESSAGES[LicenseException::CLOCK]);
    }

    return $summary['active'] ? 0 : 1;
})->purpose('Verify the stored licence file (offline) and show its status');

// No online re-check: a licence key is activated with atglance.live once (Admin Settings >
// Licence or the installer), which returns the licence file; license:verify checks it offline.

if (InstallationState::isInstalled()) {
    Schedule::command('activity:prune')->dailyAt('03:30')->withoutOverlapping();
    Schedule::command('license:verify')->hourly()->withoutOverlapping();
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
                Schedule::command($command)->cron($expression)->withoutOverlapping(120)
                    ->when(fn () => Entitlements::isFeatureEnabled('backup_restore'));
            }
        }
    } catch (Throwable $e) {
        Log::warning('Backup schedules not registered', ['error' => $e->getMessage()]);
    }
}

Artisan::command('ai:validate-workspace {workspace : Workspace id}', function (WorkspaceAiSweep $sweep) {
    if (!Entitlements::isFeatureEnabled('ai_connect')) {
        $this->warn(Entitlements::notLicensedMessage('ai_connect'));

        return 0;
    }
    $run = $sweep->run((int) $this->argument('workspace'));
    $this->info($run['message']);

    return 0;
})->purpose('Queue AI reviews of the latest config versions in one workspace');

Artisan::command('atglance:credentials-sweep', function (\App\Services\CredentialSweep $sweep) {
    $result = $sweep->run();
    $this->info("Expiry notices: {$result['expiring']}, expired notices: {$result['expired']}.");

    return 0;
})->purpose('Send API key and service account key expiry notices');

// CRIT-002: Cleanup expired temporary credential messages hourly
if (InstallationState::isInstalled()) {
    Schedule::command('atglance:credentials-sweep')->dailyAt('06:10')->withoutOverlapping();
    Schedule::command('messages:cleanup-expired')->hourly()->withoutOverlapping();
}

Artisan::command('backup:workspace {workspace : Workspace id}', function (BackupService $backups) {
    if (!Entitlements::isFeatureEnabled('backup_restore')) {
        $this->warn(Entitlements::notLicensedMessage('backup_restore'));

        return 0;
    }
    $run = $backups->runWorkspace((int) $this->argument('workspace'));
    $run->status === BackupService::STATUS_SUCCESS ? $this->info($run->message) : $this->error($run->message);

    return $run->status === BackupService::STATUS_SUCCESS ? 0 : 1;
})->purpose("Back up one workspace's stored configuration files");

// MCP container management: start/stop based on site setting.
if (InstallationState::isInstalled()) {
    Schedule::command('mcp:manage')->everyMinute()->withoutOverlapping();
}

// Per-workspace schedules from the workspace settings page (Vulnerability Checks and Backups tabs).
if (InstallationState::isInstalled()) {
    try {
        foreach (WorkspaceSettings::workspacesWith('ai_sweep_enabled') as $workspaceId => $settings) {
            $expression = WorkspaceSettings::expression($settings, 'ai_sweep');

            if ($expression !== null) {
                Schedule::command('ai:validate-workspace ' . $workspaceId)->cron($expression)->withoutOverlapping(120)
                    ->when(fn () => Entitlements::isFeatureEnabled('ai_connect'));
            }
        }

        foreach (WorkspaceSettings::workspacesWith('backup_enabled') as $workspaceId => $settings) {
            $expression = WorkspaceSettings::expression($settings, 'backup');

            if ($expression !== null) {
                Schedule::command('backup:workspace ' . $workspaceId)->cron($expression)->withoutOverlapping(120)
                    ->when(fn () => Entitlements::isFeatureEnabled('backup_restore'));
            }
        }
    } catch (Throwable $e) {
        Log::warning('Workspace schedules not registered', ['error' => $e->getMessage()]);
    }
}
