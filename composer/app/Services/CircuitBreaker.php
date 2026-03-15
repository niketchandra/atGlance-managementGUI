<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Exception;

class CircuitBreaker
{
    private string $serviceName;
    private int $failureThreshold;
    private int $timeout;
    private int $successThreshold;
    private int $monitoringWindow;

    public function __construct(
        string $serviceName,
        int $failureThreshold = 5,
        int $timeout = 60,
        int $successThreshold = 2,
        int $monitoringWindow = 120
    ) {
        $this->serviceName = $serviceName;
        $this->failureThreshold = $failureThreshold;
        $this->timeout = $timeout;
        $this->successThreshold = $successThreshold;
        $this->monitoringWindow = $monitoringWindow;
    }

    private function getStateKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:state";
    }

    private function getFailureCountKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:failures";
    }

    private function getSuccessCountKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:successes";
    }

    private function getOpenedAtKey(): string
    {
        return "circuit_breaker:{$this->serviceName}:opened_at";
    }

    public function call(callable $callback)
    {
        $state = Cache::get($this->getStateKey(), 'closed');

        if ($state === 'open') {
            $openedAt = Cache::get($this->getOpenedAtKey());
            if ($openedAt && (time() - $openedAt) > $this->timeout) {
                // Move to half-open state
                Cache::put($this->getStateKey(), 'half-open', 300);
                Cache::put($this->getSuccessCountKey(), 0, 300);
                Log::info("Circuit breaker {$this->serviceName} moved to half-open state");
            } else {
                throw new \Exception("Circuit breaker is open for {$this->serviceName}");
            }
        }

        try {
            $result = $callback();
            $this->onSuccess();
            return $result;
        } catch (Exception $e) {
            $this->onFailure();
            throw $e;
        }
    }

    private function onSuccess(): void
    {
        $state = Cache::get($this->getStateKey(), 'closed');

        if ($state === 'half-open') {
            $successes = Cache::increment($this->getSuccessCountKey());
            
            if ($successes >= $this->successThreshold) {
                // Close the circuit
                Cache::put($this->getStateKey(), 'closed', 300);
                Cache::forget($this->getFailureCountKey());
                Cache::forget($this->getSuccessCountKey());
                Cache::forget($this->getOpenedAtKey());
                Log::info("Circuit breaker closed for {$this->serviceName}");
            }
        } elseif ($state === 'closed') {
            // Reset failure count on success in closed state
            Cache::forget($this->getFailureCountKey());
        }
    }

    private function onFailure(): void
    {
        $failures = Cache::increment($this->getFailureCountKey());
        
        // Set expiration for failure count based on monitoring window
        if ($failures === 1) {
            Cache::put($this->getFailureCountKey(), 1, $this->monitoringWindow);
        }

        if ($failures >= $this->failureThreshold) {
            // Open the circuit
            Cache::put($this->getStateKey(), 'open', $this->timeout + 60);
            Cache::put($this->getOpenedAtKey(), time(), $this->timeout + 60);
            Log::error("Circuit breaker opened for {$this->serviceName} after {$failures} failures");
        }
    }

    public function isOpen(): bool
    {
        $state = Cache::get($this->getStateKey(), 'closed');
        
        if ($state === 'open') {
            $openedAt = Cache::get($this->getOpenedAtKey());
            if ($openedAt && (time() - $openedAt) > $this->timeout) {
                return false; // Will transition to half-open on next call
            }
            return true;
        }
        
        return false;
    }

    public function getState(): string
    {
        return Cache::get($this->getStateKey(), 'closed');
    }

    public function reset(): void
    {
        Cache::forget($this->getStateKey());
        Cache::forget($this->getFailureCountKey());
        Cache::forget($this->getSuccessCountKey());
        Cache::forget($this->getOpenedAtKey());
        Log::info("Circuit breaker reset for {$this->serviceName}");
    }
}
