<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\FileController;
use App\Models\ConfigurationFile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fills configuration_files.content_sha256 for versions stored before uploads kept it.
 * Reads each stored file once, through the same disk lookup as a download (local or S3),
 * in chunks by id. Only rows without a hash are touched, so it can be stopped and run
 * again. A missing or unreadable file is logged and skipped (its hash stays null and the
 * CLI downloads that version instead); it never fails the run. Storage is only read.
 */
class BackfillConfigHashes extends Command
{
    protected $signature = 'atglance:config-hash-backfill {--chunk=200 : Rows per batch}';

    protected $description = 'Compute the sha256 of stored config versions that have none yet';

    public function handle(FileController $files): int
    {
        if (!FileController::hasContentHashColumn()) {
            $this->error('configuration_files.content_sha256 does not exist; run the migrations first.');

            return self::FAILURE;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $filled = 0;
        $missing = 0;

        ConfigurationFile::query()
            ->whereNull('content_sha256')
            ->select(['id', 'file_location', 'storage_disk'])
            ->chunkById($chunk, function ($rows) use ($files, &$filled, &$missing): void {
                foreach ($rows as $row) {
                    try {
                        $content = $files->storedContent($row);
                    } catch (Throwable $e) {
                        $content = null;
                        Log::warning('Config hash backfill: file not readable', ['file_id' => $row->id, 'error' => $e->getMessage()]);
                    }

                    if ($content === null) {
                        $missing++;
                        Log::warning('Config hash backfill: file missing in storage', ['file_id' => $row->id, 'file_location' => $row->file_location]);
                        $this->warn('Missing in storage: config file ' . $row->id);

                        continue;
                    }

                    // Only a row still without a hash (a concurrent upload is never overwritten);
                    // updated_at is left alone.
                    $filled += ConfigurationFile::query()
                        ->whereKey($row->id)
                        ->whereNull('content_sha256')
                        ->toBase()
                        ->update(['content_sha256' => hash('sha256', $content)]);
                }
            });

        $this->info("Hashed {$filled} config version(s); {$missing} missing in storage.");

        return self::SUCCESS;
    }
}
