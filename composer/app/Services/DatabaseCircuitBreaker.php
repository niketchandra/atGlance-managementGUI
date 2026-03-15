<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DatabaseCircuitBreaker
{
    private CircuitBreaker $breaker;

    public function __construct()
    {
        // Configure circuit breaker for database operations
        $this->breaker = new CircuitBreaker(
            serviceName: 'database',
            failureThreshold: 3,
            timeout: 30,
            successThreshold: 2,
            monitoringWindow: 60
        );
    }

    /**
     * Execute a database query with circuit breaker protection
     */
    public function query(callable $callback)
    {
        return $this->breaker->call(function () use ($callback) {
            // Test database connection first
            try {
                DB::select('SELECT 1');
            } catch (\Exception $e) {
                Log::error("Database connection test failed", [
                    'error' => $e->getMessage()
                ]);
                throw $e;
            }

            // Execute the actual query
            return $callback();
        });
    }

    /**
     * Check if database service is available
     */
    public function isAvailable(): bool
    {
        return !$this->breaker->isOpen();
    }

    /**
     * Get current circuit breaker state
     */
    public function getState(): string
    {
        return $this->breaker->getState();
    }

    /**
     * Reset the circuit breaker
     */
    public function reset(): void
    {
        $this->breaker->reset();
    }
}
