<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\WorkspaceBackupRun;
use App\Notifications\NotificationEvents;
use App\Support\BackupSettings;
use App\Support\S3Settings;
use App\Support\WorkspaceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    /** Configuration & console files: a zip of config.json (tables) and files/ (storage). */
    public const TYPE_CONFIG = BackupSettings::TYPE_CONFIG;
    /** Database: a gzipped SQL dump of every table. */
    public const TYPE_DATABASE = BackupSettings::TYPE_DATABASE;
    /** Older full-portal zip (.env, settings, database.sql). Still restorable; no longer scheduled. */
    public const TYPE_PORTAL = 'portal';

    public const STATUS_SUCCESS = 'success';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    // Restored in this order, matched on the primary key, so saved AI validations keep pointing at their file.
    public const CONFIG_TABLES = ['services', 'system_register', 'configuration_files', 'raw_data', 'config_ai_validations'];

    /**
     * The console's own files in the configuration & console files backup:
     * archive folder => directory under storage/. Branding and other public
     * uploads, and the built-in proxy's certificates and local CA.
     */
    public const CONSOLE_FILE_ROOTS = [
        'public' => 'app/public',
        'caddy/pki' => 'caddy/pki',
        'caddy/certificates' => 'caddy/certificates',
    ];

    /**
     * Scheduled backups live under backups/{type}/YYYY/MM/ in S3 and on the
     * local disk; pre-restore snapshots under backups/snapshots/{type}/ locally.
     */
    public const S3_PREFIX = 'backups/';
    public const LOCAL_PREFIX = 'backups/';
    public const SNAPSHOT_PREFIX = 'backups/snapshots/';
    public const WORKSPACE_FILE_PATTERN = '/^workspace-backup-\d{8}-\d{6}\.zip$/';

    /**
     * Returns the scheduler cron expression for a backup type, or null when
     * that backup is not scheduled.
     */
    public function scheduleExpression(string $type): ?string
    {
        if (!in_array($type, BackupSettings::TYPES, true) || $this->skipReason($type) !== null) {
            return null;
        }

        return BackupSettings::expression(BackupSettings::get($type));
    }

    /**
     * Returns why a backup must not run right now, or null when it can run.
     * A manual run ("Run now") ignores the backup's own schedule switch.
     */
    public function skipReason(string $type, bool $manual = false): ?string
    {
        if (!in_array($type, BackupSettings::TYPES, true)) {
            return 'Unknown backup type.';
        }

        if (!BackupSettings::masterEnabled()) {
            return 'Backup & Restore is disabled.';
        }

        $settings = BackupSettings::get($type);
        if (!$manual && !$settings['enabled']) {
            return BackupSettings::LABELS[$type] . ' is turned off.';
        }

        if (!$settings['to_local'] && !$settings['to_s3']) {
            return 'No destination is selected. Choose local copies, S3, or both.';
        }

        if (!$settings['to_local'] && !S3Settings::enabled()) {
            return 'S3 is disabled.';
        }

        return null;
    }

    public function run(string $type, bool $manual = false): array
    {
        $startedAt = Carbon::now();

        $skipReason = $this->skipReason($type, $manual);
        if ($skipReason !== null) {
            return $this->recordRun($type, $startedAt, self::STATUS_SKIPPED, $skipReason);
        }

        $settings = BackupSettings::get($type);
        $workDir = $this->makeWorkDir($type . '-' . $startedAt->format('YmdHis'));
        $messages = [];
        $failed = false;
        $objectKey = null;

        try {
            [$localFile, $fileName] = $this->buildArchive($type, $workDir, $startedAt);
            $key = sprintf('%s%s/%s/%s', self::S3_PREFIX, $type, $startedAt->format('Y/m'), $fileName);

            if ($settings['to_s3'] && !S3Settings::enabled()) {
                $messages[] = 'S3 is disabled, so no copy was uploaded.';
            } elseif ($settings['to_s3']) {
                try {
                    if (!S3Settings::configureDisk()) {
                        throw new RuntimeException('S3 credentials are incomplete.');
                    }
                    $this->writeFile('s3', $key, $localFile);
                    $pruned = $this->prune('s3', $type, $settings['keep_s3']);
                    $messages[] = 'Uploaded ' . $key . $this->prunedNote($pruned);
                    $objectKey = $key;
                } catch (Throwable $e) {
                    $failed = true;
                    $messages[] = $e->getMessage();
                }
            }

            if ($settings['to_local']) {
                try {
                    $this->writeFile('local', $key, $localFile);
                    $pruned = $this->prune('local', $type, $settings['keep_local']);
                    $messages[] = 'Saved local copy ' . $key . $this->prunedNote($pruned);
                    $objectKey ??= $key;
                } catch (Throwable $e) {
                    $failed = true;
                    $messages[] = 'Local copy failed: ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $failed = true;
            $messages[] = $e->getMessage();
        } finally {
            $this->removeDirectory($workDir);
        }

        $message = implode(' ', $messages);
        if ($failed) {
            Log::error('Scheduled backup failed', ['type' => $type, 'error' => $message]);
        }

        return $this->recordRun($type, $startedAt, $failed ? self::STATUS_FAILED : self::STATUS_SUCCESS, $message, $objectKey);
    }

    public function lastRun(string $type): array
    {
        $raw = AdminSetting::getValue(BackupSettings::lastRunKey($type), '');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Builds a backup of the current state and keeps it on the local disk under
     * backups/snapshots/{type}/. Used before a restore so it can be undone.
     * Returns the path on the local disk.
     */
    public function createLocalSnapshot(string $type): string
    {
        $createdAt = Carbon::now();
        $workDir = $this->makeWorkDir('snapshot-' . $type . '-' . $createdAt->format('YmdHis'));

        try {
            [$localFile, $fileName] = $this->buildArchive($type, $workDir, $createdAt);
            $path = sprintf('%s%s/pre-restore-%s', self::SNAPSHOT_PREFIX, $type, $fileName);
            $this->writeFile('local', $path, $localFile);

            return $path;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * File name pattern of a scheduled backup of the given type.
     */
    public function fileNamePattern(string $type): string
    {
        return match ($type) {
            self::TYPE_DATABASE => '/^database-backup-\d{8}-\d{6}\.sql\.gz$/',
            self::TYPE_CONFIG => '/^config-backup-\d{8}-\d{6}\.(zip|json\.gz)$/',
            self::TYPE_PORTAL => '/^portal-backup-\d{8}-\d{6}\.zip$/',
            default => '/^$/',
        };
    }

    public function primaryKeyFor(string $table): string
    {
        return match ($table) {
            'services' => 'service_id',
            default => 'id',
        };
    }

    private function buildArchive(string $type, string $workDir, Carbon $startedAt): array
    {
        return match ($type) {
            self::TYPE_CONFIG => $this->buildConfigArchive($workDir, $startedAt),
            self::TYPE_DATABASE => $this->buildDatabaseArchive($workDir, $startedAt),
            self::TYPE_PORTAL => $this->buildPortalArchive($workDir, $startedAt),
            default => throw new RuntimeException('Unknown backup type.'),
        };
    }

    /**
     * Configuration & console files: config.json holds every uploaded config
     * file with its content (raw_data), the service and system rows needed to
     * restore it, and saved AI validations; files/ holds the console's own
     * files (CONSOLE_FILE_ROOTS).
     */
    private function buildConfigArchive(string $workDir, Carbon $startedAt): array
    {
        $this->requireZip();

        $jsonPath = $workDir . DIRECTORY_SEPARATOR . 'config.json';
        $handle = fopen($jsonPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not create configuration backup file.');
        }

        try {
            fwrite($handle, '{"type":"config","created_at":' . json_encode($startedAt->toIso8601String()) . ',"tables":{');

            foreach (self::CONFIG_TABLES as $index => $table) {
                fwrite($handle, ($index > 0 ? ',' : '') . json_encode($table) . ':[');

                $first = true;
                foreach (DB::table($table)->orderBy($this->primaryKeyFor($table))->lazy(500) as $row) {
                    fwrite($handle, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                    $first = false;
                }

                fwrite($handle, ']');
            }

            fwrite($handle, '}}');
        } finally {
            fclose($handle);
        }

        $fileName = 'config-backup-' . $startedAt->format('Ymd-His') . '.zip';
        $zipPath = $workDir . DIRECTORY_SEPARATOR . $fileName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create configuration backup archive.');
        }

        $zip->addFile($jsonPath, 'config.json');

        foreach (self::CONSOLE_FILE_ROOTS as $archiveFolder => $storageFolder) {
            $root = storage_path($storageFolder);
            if (!is_dir($root)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
                    $zip->addFile($file->getPathname(), 'files/' . $archiveFolder . '/' . $relative);
                }
            }
        }

        if (!$zip->close()) {
            throw new RuntimeException('Could not write configuration backup archive.');
        }

        return [$zipPath, $fileName];
    }

    /**
     * Database: every table's schema and rows as SQL, gzipped.
     */
    private function buildDatabaseArchive(string $workDir, Carbon $startedAt): array
    {
        $sqlPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
        $this->dumpDatabase($sqlPath);

        $fileName = 'database-backup-' . $startedAt->format('Ymd-His') . '.sql.gz';
        $gzPath = $workDir . DIRECTORY_SEPARATOR . $fileName;

        $in = fopen($sqlPath, 'rb');
        $out = gzopen($gzPath, 'wb6');
        if ($in === false || $out === false) {
            throw new RuntimeException('Could not create database backup file.');
        }

        try {
            while (!feof($in)) {
                gzwrite($out, (string) fread($in, 1024 * 1024));
            }
        } finally {
            fclose($in);
            gzclose($out);
            @unlink($sqlPath);
        }

        return [$gzPath, $fileName];
    }

    /**
     * Older portal backup: .env, admin settings and a full SQL dump. Only made
     * as the snapshot taken before restoring a portal backup.
     */
    private function buildPortalArchive(string $workDir, Carbon $startedAt): array
    {
        $this->requireZip();

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
            fwrite($handle, '-- AtGlance database backup, ' . Carbon::now()->toIso8601String() . "\n");
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

        throw new RuntimeException("Database driver [{$driver}] is not supported for database backups.");
    }

    private function quoteIdentifier(string $name, string $driver): string
    {
        return $driver === 'mysql' || $driver === 'mariadb'
            ? '`' . str_replace('`', '``', $name) . '`'
            : '"' . str_replace('"', '""', $name) . '"';
    }

    private function writeFile(string $disk, string $path, string $localFile): void
    {
        $stream = fopen($localFile, 'rb');
        try {
            // The Flysystem driver throws with the real error; the Laravel disk
            // wrapper ('throw' => false) would only return false.
            Storage::disk($disk)->getDriver()->writeStream($path, $stream);
        } catch (FilesystemException $e) {
            $reason = $e->getPrevious()?->getMessage() ?: $e->getMessage();

            throw new RuntimeException(($disk === 's3' ? 'Upload to S3 failed: ' : 'Could not save the backup: ') . $reason, 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Keeps the newest $keep scheduled backups of a type on a disk and deletes
     * the rest. Only files named like this service's backups are touched.
     *
     * @return int how many were deleted
     */
    private function prune(string $disk, string $type, int $keep): int
    {
        $pattern = $this->fileNamePattern($type);
        $paths = [];

        foreach (Storage::disk($disk)->getDriver()->listContents(self::S3_PREFIX . $type, true) as $item) {
            if ($item instanceof FileAttributes && preg_match($pattern, basename($item->path())) === 1) {
                $paths[] = $item->path();
            }
        }

        // The timestamp is in the file name, so name order is age order.
        usort($paths, fn (string $a, string $b) => strcmp(basename($b), basename($a)));

        $deleted = 0;
        foreach (array_slice($paths, $keep) as $path) {
            Storage::disk($disk)->getDriver()->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    private function prunedNote(int $pruned): string
    {
        return $pruned > 0 ? sprintf(' (removed %d older %s).', $pruned, $pruned === 1 ? 'copy' : 'copies') : '.';
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

        AdminSetting::putValue('storage', BackupSettings::lastRunKey($type), $run);

        if ($status !== self::STATUS_SKIPPED) {
            $label = BackupSettings::LABELS[$type] ?? 'Backup';
            app(Notifier::class)->notify(
                $status === self::STATUS_SUCCESS ? NotificationEvents::BACKUP_SUCCEEDED : NotificationEvents::BACKUP_FAILED,
                null,
                $label . ($status === self::STATUS_SUCCESS ? ' succeeded' : ' failed'),
                array_filter([
                    'Backup' => $label,
                    'Result' => $message,
                    'Finished' => $run['finished_at'],
                ]),
                ['type' => $type, 'status' => $status, 'object_key' => $objectKey],
            );
        }

        return $run;
    }

    /**
     * Backup of one workspace's stored configuration files (set on the workspace's
     * Backups tab): config.json with the workspace's systems, services, config
     * files, their content and AI reviews, plus workspace.json and notes.txt.
     * Saved under backups/workspace-{id}/YYYY/MM/ locally and/or in S3.
     */
    public function runWorkspace(int $workspaceId, ?int $userId = null): WorkspaceBackupRun
    {
        $startedAt = Carbon::now();
        $workspace = DB::table('workspaces')->where('id', $workspaceId)->first(['id', 'name']);
        $settings = WorkspaceSettings::get($workspaceId);
        $notes = trim((string) $settings['backup_notes']);

        $messages = [];
        $failed = false;
        $objectKey = null;
        $disk = null;
        $workDir = $this->makeWorkDir('workspace-' . $workspaceId . '-' . $startedAt->format('YmdHis'));

        try {
            if ($workspace === null) {
                throw new RuntimeException('Workspace not found.');
            }
            if (!$settings['backup_to_local'] && !$settings['backup_to_s3']) {
                throw new RuntimeException('No destination is selected (local copy or S3).');
            }

            [$localFile, $fileName] = $this->buildWorkspaceArchive($workspace, $notes, $workDir, $startedAt);
            $prefix = self::workspacePrefix($workspaceId);
            $key = sprintf('%s%s/%s', $prefix, $startedAt->format('Y/m'), $fileName);

            if ($settings['backup_to_s3'] && !S3Settings::enabled()) {
                $messages[] = 'S3 is disabled, so no copy was uploaded.';
            } elseif ($settings['backup_to_s3']) {
                try {
                    if (!S3Settings::configureDisk()) {
                        throw new RuntimeException('S3 credentials are incomplete.');
                    }
                    $this->writeFile('s3', $key, $localFile);
                    $pruned = $this->prunePrefix('s3', $prefix, self::WORKSPACE_FILE_PATTERN, (int) $settings['backup_keep_s3']);
                    $messages[] = 'Uploaded ' . $key . $this->prunedNote($pruned);
                    [$objectKey, $disk] = [$key, 's3'];
                } catch (Throwable $e) {
                    $failed = true;
                    $messages[] = $e->getMessage();
                }
            }

            if ($settings['backup_to_local']) {
                try {
                    $this->writeFile('local', $key, $localFile);
                    $pruned = $this->prunePrefix('local', $prefix, self::WORKSPACE_FILE_PATTERN, (int) $settings['backup_keep_local']);
                    $messages[] = 'Saved local copy ' . $key . $this->prunedNote($pruned);
                    // Download from the local copy when there is one.
                    [$objectKey, $disk] = [$key, 'local'];
                } catch (Throwable $e) {
                    $failed = true;
                    $messages[] = 'Local copy failed: ' . $e->getMessage();
                }
            }
        } catch (Throwable $e) {
            $failed = true;
            $messages[] = $e->getMessage();
        } finally {
            $this->removeDirectory($workDir);
        }

        $message = implode(' ', $messages);
        if ($failed) {
            Log::error('Workspace backup failed', ['workspace_id' => $workspaceId, 'error' => $message]);
        }

        $run = WorkspaceBackupRun::create([
            'workspace_id' => $workspaceId,
            'status' => $failed ? self::STATUS_FAILED : self::STATUS_SUCCESS,
            'message' => $message,
            'object_key' => $objectKey,
            'disk' => $disk,
            'notes' => $notes !== '' ? $notes : null,
            'triggered_by' => $userId,
            'started_at' => $startedAt,
            'finished_at' => Carbon::now(),
        ]);

        app(Notifier::class)->notify(
            $failed ? NotificationEvents::WORKSPACE_BACKUP_FAILED : NotificationEvents::WORKSPACE_BACKUP_SUCCEEDED,
            $workspaceId,
            'Workspace backup ' . ($failed ? 'failed' : 'succeeded') . ': ' . ($workspace->name ?? '#' . $workspaceId),
            array_filter([
                'Workspace' => $workspace->name ?? (string) $workspaceId,
                'Result' => $message,
                'Notes' => $notes,
                'Finished' => $run->finished_at?->toIso8601String(),
            ]),
            ['run_id' => $run->id, 'status' => $run->status, 'object_key' => $objectKey],
        );

        return $run;
    }

    public static function workspacePrefix(int $workspaceId): string
    {
        return self::S3_PREFIX . 'workspace-' . $workspaceId . '/';
    }

    private function buildWorkspaceArchive(object $workspace, string $notes, string $workDir, Carbon $startedAt): array
    {
        $this->requireZip();

        $systemIds = DB::table('system_register')->where('workspace_id', $workspace->id)->pluck('id');
        $configIds = DB::table('configuration_files')->whereIn('system_register_id', $systemIds)->pluck('id');

        $tables = [
            'system_register' => DB::table('system_register')->whereIn('id', $systemIds),
            'services' => DB::table('services')->whereIn('system_id', $systemIds),
            'configuration_files' => DB::table('configuration_files')->whereIn('id', $configIds),
            'raw_data' => DB::table('raw_data')->whereIn('file_id', $configIds),
            'config_ai_validations' => DB::table('config_ai_validations')->whereIn('configuration_file_id', $configIds),
        ];

        $jsonPath = $workDir . DIRECTORY_SEPARATOR . 'config.json';
        $handle = fopen($jsonPath, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Could not create workspace backup file.');
        }

        try {
            fwrite($handle, '{"type":"workspace","workspace_id":' . (int) $workspace->id . ',"created_at":' . json_encode($startedAt->toIso8601String()) . ',"tables":{');

            $index = 0;
            foreach ($tables as $table => $query) {
                fwrite($handle, ($index++ > 0 ? ',' : '') . json_encode($table) . ':[');

                $first = true;
                foreach ($query->orderBy($this->primaryKeyFor($table))->lazy(500) as $row) {
                    fwrite($handle, ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
                    $first = false;
                }

                fwrite($handle, ']');
            }

            fwrite($handle, '}}');
        } finally {
            fclose($handle);
        }

        $fileName = 'workspace-backup-' . $startedAt->format('Ymd-His') . '.zip';
        $zipPath = $workDir . DIRECTORY_SEPARATOR . $fileName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create workspace backup archive.');
        }

        $zip->addFile($jsonPath, 'config.json');
        $zip->addFromString('workspace.json', json_encode([
            'id' => (int) $workspace->id,
            'name' => $workspace->name,
            'notes' => $notes,
            'created_at' => $startedAt->toIso8601String(),
            'counts' => ['systems' => $systemIds->count(), 'configuration_files' => $configIds->count()],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        if ($notes !== '') {
            $zip->addFromString('notes.txt', $notes . "\n");
        }

        if (!$zip->close()) {
            throw new RuntimeException('Could not write workspace backup archive.');
        }

        return [$zipPath, $fileName];
    }

    /**
     * Keeps the newest $keep files matching $pattern under $prefix and deletes the rest.
     */
    private function prunePrefix(string $disk, string $prefix, string $pattern, int $keep): int
    {
        $keep = max(1, min($keep, BackupSettings::MAX_KEEP));
        $paths = [];

        foreach (Storage::disk($disk)->getDriver()->listContents(rtrim($prefix, '/'), true) as $item) {
            if ($item instanceof FileAttributes && preg_match($pattern, basename($item->path())) === 1) {
                $paths[] = $item->path();
            }
        }

        usort($paths, fn (string $a, string $b) => strcmp(basename($b), basename($a)));

        $deleted = 0;
        foreach (array_slice($paths, $keep) as $path) {
            Storage::disk($disk)->getDriver()->delete($path);
            $deleted++;
        }

        return $deleted;
    }

    private function requireZip(): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for backups.');
        }
    }

    private function makeWorkDir(string $name): string
    {
        $workDir = storage_path('app/backups/tmp/' . $name . '-' . bin2hex(random_bytes(4)));
        if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new RuntimeException('Could not create backup working directory.');
        }

        return $workDir;
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
