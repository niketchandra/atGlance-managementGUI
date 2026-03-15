<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DeleteUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 60, 120, 300, 600];
    public $timeout = 60;

    private int $userId;
    private ?string $requestId;

    public function __construct(int $userId, ?string $requestId = null)
    {
        $this->userId = $userId;
        $this->requestId = $requestId;
    }

    public function handle(): void
    {
        try {
            Log::info("Processing DeleteUserJob", [
                'request_id' => $this->requestId,
                'user_id' => $this->userId,
                'attempt' => $this->attempts()
            ]);

            $user = User::findOrFail($this->userId);
            $user->delete();

            Log::info("User deleted successfully", [
                'request_id' => $this->requestId,
                'user_id' => $this->userId
            ]);

        } catch (\Exception $e) {
            Log::error("DeleteUserJob failed", [
                'request_id' => $this->requestId,
                'user_id' => $this->userId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("DeleteUserJob permanently failed", [
            'request_id' => $this->requestId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage()
        ]);
    }
}
