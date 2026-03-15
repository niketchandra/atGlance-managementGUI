<?php

namespace App\Jobs;

use App\Models\Product;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class UpdateProductJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;
    public $backoff = [30, 60, 120, 300, 600];
    public $timeout = 60;

    private int $productId;
    private array $productData;
    private ?string $requestId;

    public function __construct(int $productId, array $productData, ?string $requestId = null)
    {
        $this->productId = $productId;
        $this->productData = $productData;
        $this->requestId = $requestId;
    }

    public function handle(): void
    {
        try {
            Log::info("Processing UpdateProductJob", [
                'request_id' => $this->requestId,
                'product_id' => $this->productId,
                'attempt' => $this->attempts()
            ]);

            $product = Product::findOrFail($this->productId);
            $product->fill($this->productData);
            $product->save();

            Log::info("Product updated successfully", [
                'request_id' => $this->requestId,
                'product_id' => $product->id
            ]);

        } catch (\Exception $e) {
            Log::error("UpdateProductJob failed", [
                'request_id' => $this->requestId,
                'product_id' => $this->productId,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::critical("UpdateProductJob permanently failed", [
            'request_id' => $this->requestId,
            'product_id' => $this->productId,
            'error' => $exception->getMessage()
        ]);
    }
}
