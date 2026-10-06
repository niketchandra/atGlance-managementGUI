<?php

namespace App\Services;

use App\Support\S3Settings;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use League\Flysystem\FileAttributes;
use RuntimeException;
use Throwable;
use ZipArchive;

class RestoreService
{
    public const SOURCE_S3 = 's3';
    public const SOURCE_LOCAL = 'local';

    public function __construct(private BackupService $backups)
    {
    }

    /**
     * Backups that can be restored, newest first: scheduled backups in S3,
     * local copies on this server, and pre-restore snapshots.
     *
     * @param array<int, string> $types backup types to include
     * @return array{backups: array<int, array>, errors: array<int, string>}
     */
    public function listBackups(array $types = [BackupService::TYPE_CONFIG, BackupService::TYPE_DATABASE, BackupService::TYPE_PORTAL]): array
    {
        $backups = [];
        $errors = [];

        if (S3Settings::enabled()) {
            if (S3Settings::configureDisk()) {
                try {
                    foreach ($types as $type) {
                        $listing = Storage::disk('s3')->getDriver()->listContents(BackupService::S3_PREFIX . $type, true);
                        $backups = array_merge($backups, $this->describeListing(self::SOURCE_S3, $listing, $types));
                    }
                } catch (Throwable $e) {
                    $errors[] = 'Could not list S3 backups: ' . ($e->getPrevious()?->getMessage() ?: $e->getMessage());
                }
            } else {
                $errors[] = 'S3 is enabled but its credentials are incomplete.';
            }
        }

        try {
            foreach ($types as $type) {
                foreach ([BackupService::LOCAL_PREFIX . $type, BackupService::SNAPSHOT_PREFIX . $type] as $prefix) {
                    $listing = Storage::disk('local')->getDriver()->listContents($prefix, true);
                    $backups = array_merge($backups, $this->describeListing(self::SOURCE_LOCAL, $listing, $types));
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'Could not list local backups: ' . $e->getMessage();
        }

        usort($backups, fn (array $a, array $b) => $b['last_modified'] <=> $a['last_modified']);

        return ['backups' => $backups, 'errors' => $errors];
    }

    /**
     * Returns the backup type (config, database or portal) for a restorable
     * path, or null when the path is not a backup this service created.
     */
    public function typeFor(string $source, string $path): ?string
    {
        if (str_contains($path, '..')) {
            return null;
        }

        $pattern = match ($source) {
            self::SOURCE_S3 => '#^backups/(config|database|portal)/\d{4}/\d{2}/[A-Za-z0-9._-]+$#',
            self::SOURCE_LOCAL => '#^backups/(?:snapshots/(config|database|portal)/|(config|database|portal)/\d{4}/\d{2}/)[A-Za-z0-9._-]+$#',
            default => null,
        };

        if ($pattern === null || preg_match($pattern, $path, $matches) !== 1) {
            return null;
        }

        return ($matches[1] ?? '') !== '' ? $matches[1] : $matches[2];
    }

    /**
     * Restores a backup. A snapshot of the current state is taken first; its
     * local path is returned so the restore can be undone.
     *
     * @return array{type: string, snapshot: string, summary: array}
     */
    public function restore(string $source, string $path): array
    {
        $type = $this->typeFor($source, $path);
        if ($type === null) {
            throw new InvalidArgumentException('Unknown backup.');
        }

        if ($source === self::SOURCE_S3 && !S3Settings::configureDisk()) {
            throw new RuntimeException('S3 credentials are incomplete.');
        }

        $workDir = storage_path('app/backups/tmp/restore-' . $type . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)));
        if (!is_dir($workDir) && !mkdir($workDir, 0755, true) && !is_dir($workDir)) {
            throw new RuntimeException('Could not create restore working directory.');
        }

        try {
            $localFile = $workDir . DIRECTORY_SEPARATOR . basename($path);
            $this->download($source, $path, $localFile);

            $snapshot = $this->backups->createLocalSnapshot($type);

            try {
                $summary = match ($type) {
                    BackupService::TYPE_CONFIG => $this->restoreConfig($localFile),
                    BackupService::TYPE_DATABASE => $this->restoreDatabase($localFile, $workDir),
                    default => $this->restorePortal($localFile, $workDir),
                };
            } catch (Throwable $e) {
                throw new RuntimeException($e->getMessage() . ' Snapshot of the previous state: ' . $snapshot, 0, $e);
            }

            return ['type' => $type, 'snapshot' => $snapshot, 'summary' => $summary];
        } finally {
            File::deleteDirectory($workDir);
        }
    }

    private function describeListing(string $source, iterable $listing, array $types): array
    {
        $backups = [];

        foreach ($listing as $item) {
            if (!$item instanceof FileAttributes) {
                continue;
            }

            $path = $item->path();
            $type = $this->typeFor($source, $path);
            if ($type === null || !in_array($type, $types, true)) {
                continue;
            }

            $backups[] = [
                'source' => $source,
                'kind' => str_starts_with($path, BackupService::SNAPSHOT_PREFIX) ? 'snapshot' : 'backup',
                'type' => $type,
                'path' => $path,
                'name' => basename($path),
                'size' => (int) $item->fileSize(),
                'last_modified' => (int) $item->lastModified(),
            ];
        }

        return $backups;
    }

    private function download(string $source, string $path, string $localFile): void
    {
        $stream = Storage::disk($source)->getDriver()->readStream($path);
        $target = fopen($localFile, 'wb');

        try {
            stream_copy_to_stream($stream, $target);
        } finally {
            fclose($target);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Adds back missing records and resets changed ones to their backed-up
     * values. Records created after the backup are kept. File contents are
     * written back from raw_data.
     */
    private function restoreConfig(string $localFile): array
    {
        $consoleFiles = [];

        if (str_ends_with($localFile, '.zip')) {
            if (!class_exists(ZipArchive::class)) {
                throw new RuntimeException('PHP zip extension is required for restores.');
            }

            $zip = new ZipArchive();
            if ($zip->open($localFile) !== true) {
                throw new RuntimeException('Not a configuration files backup archive.');
            }

            $json = $zip->getFromName('config.json');
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $target = $this->consoleFileTarget((string) $zip->getNameIndex($i));
                if ($target !== null) {
                    $consoleFiles[$target] = $zip->getFromIndex($i);
                }
            }
            $zip->close();

            $payload = $json === false ? null : json_decode($json, true);
        } else {
            // Backups made before console files were included.
            $payload = json_decode((string) gzdecode((string) file_get_contents($localFile)), true);
        }

        if (!is_array($payload) || ($payload['type'] ?? null) !== BackupService::TYPE_CONFIG || !is_array($payload['tables'] ?? null)) {
            throw new RuntimeException('Not a configuration files backup.');
        }

        $tables = $payload['tables'];
        $rows = [];

        DB::transaction(function () use ($tables, &$rows) {
            $this->withoutForeignKeyChecks(function () use ($tables, &$rows) {
                foreach (BackupService::CONFIG_TABLES as $table) {
                    $key = $this->backups->primaryKeyFor($table);
                    $columns = array_flip(Schema::getColumnListing($table));
                    $rows[$table] = 0;

                    foreach ($tables[$table] ?? [] as $row) {
                        // Ignore columns a newer schema no longer has.
                        $row = array_intersect_key((array) $row, $columns);
                        if (!isset($row[$key])) {
                            continue;
                        }

                        DB::table($table)->updateOrInsert([$key => $row[$key]], $row);
                        $rows[$table]++;
                    }
                }
            });
        });

        $contents = [];
        foreach ($tables['raw_data'] ?? [] as $raw) {
            $contents[(int) ($raw['file_id'] ?? 0)] = (string) ($raw['file_data'] ?? '');
        }

        $s3Ready = S3Settings::enabled() && S3Settings::configureDisk();
        $filesWritten = 0;

        foreach ($tables['configuration_files'] ?? [] as $file) {
            $id = (int) ($file['id'] ?? 0);
            $location = ltrim((string) ($file['file_location'] ?? ''), '/');
            if ($location === '' || !array_key_exists($id, $contents)) {
                continue;
            }

            $disk = ($file['storage_disk'] ?? 'local') === 's3' && $s3Ready ? 's3' : 'local';
            Storage::disk($disk)->getDriver()->write($location, $contents[$id]);
            $filesWritten++;

            if (($file['storage_disk'] ?? null) !== $disk && Schema::hasColumn('configuration_files', 'storage_disk')) {
                DB::table('configuration_files')->where('id', $id)->update(['storage_disk' => $disk]);
            }
        }

        $consoleWritten = 0;
        foreach ($consoleFiles as $target => $content) {
            if ($content === false) {
                continue;
            }
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $content);
            $consoleWritten++;
        }

        return ['rows' => $rows, 'files_written' => $filesWritten, 'console_files_written' => $consoleWritten];
    }

    /**
     * Maps an archive entry under files/ to its path in storage/, or null when
     * the entry is not a console file this service backs up.
     */
    private function consoleFileTarget(string $entry): ?string
    {
        if (!str_starts_with($entry, 'files/') || str_ends_with($entry, '/') || str_contains($entry, '..') || str_contains($entry, '\\')) {
            return null;
        }

        $relative = substr($entry, strlen('files/'));
        foreach (BackupService::CONSOLE_FILE_ROOTS as $archiveFolder => $storageFolder) {
            if (str_starts_with($relative, $archiveFolder . '/')) {
                $rest = substr($relative, strlen($archiveFolder) + 1);

                return $rest === '' ? null : storage_path($storageFolder . '/' . $rest);
            }
        }

        return null;
    }

    /**
     * Replaces the whole database with the backup's SQL dump, then runs
     * pending migrations so an older backup is brought up to the current schema.
     */
    private function restoreDatabase(string $localFile, string $workDir): array
    {
        $sqlPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
        $in = gzopen($localFile, 'rb');
        $out = fopen($sqlPath, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Could not read the database backup.');
        }

        try {
            while (!gzeof($in)) {
                fwrite($out, (string) gzread($in, 1024 * 1024));
            }
        } finally {
            gzclose($in);
            fclose($out);
        }

        if (!str_contains((string) file_get_contents($sqlPath, false, null, 0, 65536), 'CREATE TABLE')) {
            throw new RuntimeException('Not a database backup.');
        }

        $statements = $this->runSqlFile($sqlPath);
        Artisan::call('migrate', ['--force' => true]);

        return ['statements' => $statements];
    }

    private function runSqlFile(string $sqlPath): int
    {
        $statements = 0;
        $this->withoutForeignKeyChecks(function () use ($sqlPath, &$statements) {
            foreach ($this->sqlStatements($sqlPath, DB::connection()->getDriverName()) as $statement) {
                DB::unprepared($statement);
                $statements++;
            }
        });

        return $statements;
    }

    /**
     * Replaces the whole database with the backup's SQL dump and writes the
     * backup's .env back. Pending migrations run afterwards, so an older
     * backup is brought up to the current schema.
     */
    private function restorePortal(string $localFile, string $workDir): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP zip extension is required for portal restores.');
        }

        $zip = new ZipArchive();
        if ($zip->open($localFile) !== true) {
            throw new RuntimeException('Not a portal backup archive.');
        }

        $sql = $zip->getFromName('database.sql');
        $env = $zip->getFromName('.env');
        $zip->close();

        if ($sql === false) {
            throw new RuntimeException('Portal backup has no database.sql.');
        }

        $sqlPath = $workDir . DIRECTORY_SEPARATOR . 'database.sql';
        file_put_contents($sqlPath, $sql);
        unset($sql);

        $statements = $this->runSqlFile($sqlPath);

        Artisan::call('migrate', ['--force' => true]);

        $envRestored = false;
        if ($env !== false && $env !== '') {
            File::put(base_path('.env'), $env);
            $envRestored = true;
        }

        return ['statements' => $statements, 'env_restored' => $envRestored];
    }

    /**
     * Splits a dump into statements on `;` outside quotes and skips `--` comment
     * lines. MySQL strings use backslash escapes; SQLite strings do not.
     */
    private function sqlStatements(string $path, string $driver): \Generator
    {
        $sql = (string) file_get_contents($path);
        $length = strlen($sql);
        $backslashEscapes = in_array($driver, ['mysql', 'mariadb'], true);
        $start = 0;
        $quote = null;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                if ($char === '\\' && $backslashEscapes && $quote === "'") {
                    $i++;
                } elseif ($char === $quote) {
                    if (($sql[$i + 1] ?? '') === $quote) {
                        $i++;
                    } else {
                        $quote = null;
                    }
                }

                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
            } elseif ($char === '-' && ($sql[$i + 1] ?? '') === '-' && trim(substr($sql, $start, $i - $start)) === '') {
                $newline = strpos($sql, "\n", $i);
                $i = $newline === false ? $length : $newline;
                $start = $i + 1;
            } elseif ($char === ';') {
                $statement = trim(substr($sql, $start, $i - $start));
                if ($statement !== '') {
                    yield $statement;
                }
                $start = $i + 1;
            }
        }

        $statement = trim(substr($sql, $start));
        if ($statement !== '') {
            yield $statement;
        }
    }

    private function withoutForeignKeyChecks(callable $callback): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // PRAGMA foreign_keys cannot change inside a transaction; deferring works everywhere.
            DB::statement('PRAGMA defer_foreign_keys = ON');
            $callback();

            return;
        }

        Schema::withoutForeignKeyConstraints($callback);
    }
}
