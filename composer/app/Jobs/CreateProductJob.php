<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CreateProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 60, 120, 300, 600];
    public $timeout = 60;

    private array $productData;
    private ?string $requestId;

    public function __construct(array $productData, ?string $requestId = null)
    {
        $this->productData = $productData;
        $this->requestId = $requestId;
    }

    public function handle(): void
    {
        try {
            Log::info("Processing CreateProductJob", [
                'request_id' => $this->requestId,
                'attempt' => $this->attempts(),
                'sku' => $this->productData['sku'] ?? null
            ]);

            $product = Product::create($this->productData);

            Log::info("Product created successfully", [
                'request_id' => $this->requestId,
                'product_id' => $product->id,
                'sku' => $product->sku
            ]);

        } catch (\Exception $e) {
            Log::error("CreateProductJob failed", [
                'request_id' => $this->requestId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("CreateProductJob permanently failed", [
            'request_id' => $this->requestId,
            'sku' => $this->productData['sku'] ?? null,
            'error' => $exception->getMessage()
        ]);
    }
}
