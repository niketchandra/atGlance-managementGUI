<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times the job may be attempted.
     */
    public $tries = 5;

    /**
     * Exponential backoff delays in seconds.
     */
    public $backoff = [30, 60, 120, 300, 600];

    /**
     * Job timeout in seconds.
     */
    public $timeout = 60;

    private array $userData;
    private ?string $requestId;

    /**
     * Create a new job instance.
     */
    public function __construct(array $userData, ?string $requestId = null)
    {
        $this->userData = $userData;
        $this->requestId = $requestId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("Processing CreateUserJob", [
                'request_id' => $this->requestId,
                'attempt' => $this->attempts(),
                'email' => $this->userData['email'] ?? null
            ]);

            $user = User::create($this->userData);

            Log::info("User created successfully", [
                'request_id' => $this->requestId,
                'user_id' => $user->id,
                'email' => $user->email
            ]);

        } catch (\Exception $e) {
            Log::error("CreateUserJob failed", [
                'request_id' => $this->requestId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle job failure after all retries exhausted.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical("CreateUserJob permanently failed after all retries", [
            'request_id' => $this->requestId,
            'email' => $this->userData['email'] ?? null,
            'error' => $exception->getMessage()
        ]);

        // Here you could:
        // - Send notification to admin
        // - Store in dead letter table
        // - Send webhook to client
    }
}
