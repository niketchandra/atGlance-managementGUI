<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Support\S3Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public const TYPE_CONFIG = 'config';
    public const TYPE_PORTAL = 'portal';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    /**
     * Scheduler cron expressions for each frequency the Backup & Restore tab offers.
     */
    private const FREQUENCY_EXPRESSIONS = [
        'hourly' => '0 * * * *',
        'every_six_hours' => '0 */6 * * *',
        'every_twelve_hours' => '0 */12 * * *',
        'daily' => '0 0 * * *',
        'weekly' => '0 0 * * 0',
        'monthly' => '0 0 1 * *',
    ];

    private const CONFIG_TABLES = ['services', 'system_register', 'configuration_files', 'raw_data'];

    /**
     * Returns the scheduler cron expression for a backup type, or null when
     * that backup is not enabled or has no valid frequency.
     */
    public function scheduleExpression(string $type): ?string
    {
        if ($this->skipReason($type) !== null) {
            return null;
        }

        $frequency = strtolower(trim((string) AdminSetting::getValue($this->cronSettingKey($type), '')));

        return self::FREQUENCY_EXPRESSIONS[$frequency] ?? null;
    }

    /**
     * Returns why a backup type must not run right now, or null when it can run.
     */
    public function skipReason(string $type): ?string
    {
        if (!$this->isEnabledSetting('backup_restore_enabled')) {
            return 'Backup & Restore is disabled.';
        }

        $toggleKey = $type === self::TYPE_CONFIG ? 'backup_config_to_s3' : 'backup_portal_to_s3';
        if (!$this->isEnabledSetting($toggleKey)) {
            return $type === self::TYPE_CONFIG
                ? 'Configuration files backup to S3 is disabled.'
                : 'Portal backup to S3 is disabled.';
        }

        if (!S3Settings::enabled()) {
            return 'S3 is disabled.';
        }

        return null;
    }

    public function run(string $type): array
    {
        $startedAt = Carbon::now();

        $skipReason = $this->skipReason($type);
        if ($skipReason !== null) {
            return $this->recordRun($type, $startedAt, self::STATUS_SKIPPED, $skipReason);
        }

        $workDir = storage_path('app/backups/tmp/' . $type . '-' . $startedAt->format('YmdHis') . '-' . bin2hex(random_bytes(4)));

        try {
            if (!S3Settings::configureDisk()) {
                throw new RuntimeException('S3 credentials are incomplete.');
            }

            if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
                throw new RuntimeException('Could not create backup working directory.');
            }

            [$localFile, $fileName] = $type === self::TYPE_CONFIG
                ? $this->buildConfigArchive($workDir, $startedAt)
                : $this->buildPortalArchive($workDir, $startedAt);

            $objectKey = sprintf('backups/%s/%s/%s', $type, $startedAt->format('Y/m'), $fileName);

            $stream = fopen($localFile, 'rb');
            try {
                // The Flysystem driver throws with the S3 error; the Laravel disk
                // wrapper ('throw' => false) would only return false.
                Storage::disk('s3')->getDriver()->writeStream($objectKey, $stream);
            } catch (FilesystemException $e) {
                $reason = $e->getPrevious()?->getMessage() ?: $e->getMessage();

                throw new RuntimeException('Upload to S3 failed: ' . $reason, 0, $e);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            return $this->recordRun($type, $startedAt, self::STATUS_SUCCESS, 'Uploaded ' . $objectKey, $objectKey);
        } catch (Throwable $e) {
            Log::error('Scheduled backup failed', ['type' => $type, 'error' => $e->getMessage()]);

            return $this->recordRun($type, $startedAt, self::STATUS_FAILED, $e->getMessage());
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function lastRun(string $type): array
    {
        $raw = AdminSetting::getValue($this->lastRunSettingKey($type), '');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Configuration files backup: every uploaded config file with its content
     * (raw_data) plus the service and system rows needed to restore it.
     */
    private function buildConfigArchive(string $workDir, Carbon $startedAt): array
    {
        $fileName = 'config-backup-' . $startedAt->format('Ymd-His') . '.json.gz';
        $path = $workDir . DIRECTORY_SEPARATOR . $fileName;

        $gz = gzopen($path, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('Could not create configuration backup file.');
        }

        try {
            gzwrite($gz, '{"type":"config","created_at":' . json_encode($startedAt->toIso8601String()) . ',"tables":{');

            foreach (self::CONFIG_TABLES as $index => $table) {
                gzwrite($gz, ($index > 0 ? ',' : '') . json_encode($table) . ':[');

                $first = true;
                foreach (DB::table($table)->orderBy($this->primaryKeyFor($table))->lazy(500) as $row) {
                    gzwrite($gz, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                    $first = false;
                }

                gzwrite($gz, ']');
            }

            gzwrite($gz, '}}');
        } finally {
            gzclose($gz);
        }

        return [$path, $fileName];
    }

    /**
     * Portal backup: .env, admin settings and a full SQL dump of the database.
     */
    private function buildPortalArchive(string $workDir, Carbon $startedAt): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for portal backups.');
        }

        $sqlPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
        $this->dumpDatabase($sqlPath);

        $settingsPath = $workDir . DIRECTORY_SEPARATOR . 'admin_settings.json';
        file_put_contents(
            $settingsPath,
            json_encode(DB::table('admin_settings')->orderBy('id')->get(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        $fileName = 'portal-backup-' . $startedAt->format('Ymd-His') . '.zip';
        $zipPath = $workDir . DIRECTORY_SEPARATOR . $fileName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create portal backup archive.');
        }

        $envPath = base_path('.env');
        if (is_file($envPath)) {
            $zip->addFile($envPath, '.env');
        }
        $zip->addFile($settingsPath, 'admin_settings.json');
        $zip->addFile($sqlPath, 'database.sql');

        if (!$zip->close()) {
            throw new RuntimeException('Could not write portal backup archive.');
        }

        return [$zipPath, $fileName];
    }

    private function dumpDatabase(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not create database dump file.');
        }

        $connection = DB::connection();
        $pdo = $connection->getPdo();
        $driver = $connection->getDriverName();

        try {
            fwrite($handle, '-- AtGlance portal backup, ' . Carbon::now()->toIso8601String() . "\n");
            if ($driver === 'mysql' || $driver === 'mariadb') {
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n");
            }

            foreach ($this->tableNames() as $table) {
                $quoted = $this->quoteIdentifier($table, $driver);

                fwrite($handle, "\n-- Table {$table}\n");
                fwrite($handle, "DROP TABLE IF EXISTS {$quoted};\n");
                fwrite($handle, rtrim($this->createTableStatement($table, $driver), ';') . ";\n");

                foreach ($connection->table($table)->cursor() as $row) {
                    $row = (array) $row;
                    $columns = implode(', ', array_map(fn ($column) => $this->quoteIdentifier($column, $driver), array_keys($row)));
                    $values = implode(', ', array_map(
                        fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value),
                        array_values($row)
                    ));

                    fwrite($handle, "INSERT INTO {$quoted} ({$columns}) VALUES ({$values});\n");
                }
            }

            if ($driver === 'mysql' || $driver === 'mariadb') {
                fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
            }
        } finally {
            fclose($handle);
        }
    }

    private function tableNames(): array
    {
        $prefix = DB::connection()->getTablePrefix();

        return collect(DB::connection()->getSchemaBuilder()->getTables())
            ->pluck('name')
            ->reject(fn ($name) => str_starts_with($name, 'sqlite_'))
            ->map(fn ($name) => $prefix !== '' && str_starts_with($name, $prefix) ? substr($name, strlen($prefix)) : $name)
            ->values()
            ->all();
    }

    private function createTableStatement(string $table, string $driver): string
    {
        if ($driver === 'sqlite') {
            return (string) DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        }

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $row = (array) DB::selectOne('SHOW CREATE TABLE ' . $this->quoteIdentifier($table, $driver));

            return (string) ($row['Create Table'] ?? '');
        }

        throw new RuntimeException("Database driver [{$driver}] is not supported for portal backups.");
    }

    private function quoteIdentifier(string $name, string $driver): string
    {
        return $driver === 'mysql' || $driver === 'mariadb'
            ? '`' . str_replace('`', '``', $name) . '`'
            : '"' . str_replace('"', '""', $name) . '"';
    }

    private function primaryKeyFor(string $table): string
    {
        return match ($table) {
            'services' => 'service_id',
            default => 'id',
        };
    }

    private function recordRun(string $type, Carbon $startedAt, string $status, string $message, ?string $objectKey = null): array
    {
        $run = [
            'status' => $status,
            'message' => $message,
            'object_key' => $objectKey,
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => Carbon::now()->toIso8601String(),
        ];

        AdminSetting::putValue('storage', $this->lastRunSettingKey($type), $run);

        return $run;
    }

    private function isEnabledSetting(string $key, bool $default = false): bool
    {
        return filter_var((string) AdminSetting::getValue($key, $default ? 'true' : 'false'), FILTER_VALIDATE_BOOL);
    }

    private function cronSettingKey(string $type): string
    {
        return $type === self::TYPE_CONFIG ? 'backup_config_cron' : 'backup_portal_cron';
    }

    private function lastRunSettingKey(string $type): string
    {
        return $type === self::TYPE_CONFIG ? 'backup_config_last_run' : 'backup_portal_last_run';
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }

        @rmdir($directory);
    }
}
