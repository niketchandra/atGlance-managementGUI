<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanExpiredMessages extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'messages:cleanup-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete expired temporary credential messages from the database';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        // Fix CRIT-002: Delete messages older than their TTL
        $deleted = DB::table('temporary_credential_messages')
            ->where('expires_at', '<', now())
            ->delete();

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} expired temporary messages.");
        } else {
            $this->info('No expired messages to clean up.');
        }

        return Command::SUCCESS;
    }
}
