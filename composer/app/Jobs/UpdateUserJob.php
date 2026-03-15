<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 60, 120, 300, 600];
    public $timeout = 60;

    private int $userId;
    private array $userData;
    private ?string $requestId;

    public function __construct(int $userId, array $userData, ?string $requestId = null)
    {
        $this->userId = $userId;
        $this->userData = $userData;
        $this->requestId = $requestId;
    }

    public function handle(): void
    {
        try {
            Log::info("Processing UpdateUserJob", [
                'request_id' => $this->requestId,
                'user_id' => $this->userId,
                'attempt' => $this->attempts()
            ]);

            $user = User::findOrFail($this->userId);
            $user->fill($this->userData);
            $user->save();

            Log::info("User updated successfully", [
                'request_id' => $this->requestId,
                'user_id' => $user->id
            ]);

        } catch (\Exception $e) {
            Log::error("UpdateUserJob failed", [
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
        Log::critical("UpdateUserJob permanently failed", [
            'request_id' => $this->requestId,
            'user_id' => $this->userId,
            'error' => $exception->getMessage()
        ]);
    }
}
