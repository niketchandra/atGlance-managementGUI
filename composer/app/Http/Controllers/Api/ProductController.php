<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\CreateProductJob;
use App\Jobs\UpdateProductJob;
use App\Jobs\DeleteProductJob;
use App\Models\Product;
use App\Services\DatabaseCircuitBreaker;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    private DatabaseCircuitBreaker $circuitBreaker;

    public function __construct(DatabaseCircuitBreaker $circuitBreaker)
    {
        $this->circuitBreaker = $circuitBreaker;
    }

    public function index()
    {
        try {
            // Read operations with circuit breaker
            return $this->circuitBreaker->query(function () {
                return Product::query()->get();
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Database service is temporarily unavailable',
                'message' => 'Please try again later',
                'circuit_state' => $this->circuitBreaker->getState()
            ], 503);
        }
    }

    public function store(Request $request)
    {
        $baseRules = [
            'name' => ['required', 'string', 'max:200'],
            'sku' => ['required', 'string', 'max:100'],
            'price_cents' => ['required', 'integer', 'min:0'],
        ];

        $requestId = Str::uuid()->toString();

        try {
            $rules = $baseRules;

            if ($this->circuitBreaker->isAvailable()) {
                $rules['sku'][] = 'unique:products,sku';
            }

            $data = $request->validate($rules);
        } catch (\Exception $e) {
            $data = $request->validate($baseRules);

            CreateProductJob::dispatch($data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database validation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        // Check if database is available
        if (!$this->circuitBreaker->isAvailable()) {
            // Queue the operation
            CreateProductJob::dispatch($data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database service is temporarily unavailable. Request has been queued.',
                'request_id' => $requestId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        try {
            // Try to create product directly with circuit breaker
            $product = $this->circuitBreaker->query(function () use ($data) {
                return Product::create($data);
            });

            return response()->json($product, 201);

        } catch (\Exception $e) {
            // Circuit breaker triggered - queue the operation
            CreateProductJob::dispatch($data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database operation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }
    }

    public function show(Product $product)
    {
        try {
            // Read operations with circuit breaker
            return $this->circuitBreaker->query(function () use ($product) {
                return $product;
            });
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Database service is temporarily unavailable',
                'message' => 'Please try again later',
                'circuit_state' => $this->circuitBreaker->getState()
            ], 503);
        }
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:200'],
            'sku' => ['sometimes', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product->id)],
            'price_cents' => ['sometimes', 'integer', 'min:0'],
        ]);

        $requestId = Str::uuid()->toString();

        // Check if database is available
        if (!$this->circuitBreaker->isAvailable()) {
            UpdateProductJob::dispatch($product->id, $data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database service is temporarily unavailable. Request has been queued.',
                'request_id' => $requestId,
                'product_id' => $product->id,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        try {
            // Try to update product directly with circuit breaker
            $updatedProduct = $this->circuitBreaker->query(function () use ($product, $data) {
                $product->fill($data);
                $product->save();
                return $product;
            });

            return response()->json($updatedProduct);

        } catch (\Exception $e) {
            // Circuit breaker triggered - queue the operation
            UpdateProductJob::dispatch($product->id, $data, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database operation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'product_id' => $product->id,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }
    }

    public function destroy(Product $product)
    {
        $requestId = Str::uuid()->toString();
        $productId = $product->id;

        // Check if database is available
        if (!$this->circuitBreaker->isAvailable()) {
            DeleteProductJob::dispatch($productId, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database service is temporarily unavailable. Request has been queued.',
                'request_id' => $requestId,
                'product_id' => $productId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }

        try {
            // Try to delete product directly with circuit breaker
            $this->circuitBreaker->query(function () use ($product) {
                $product->delete();
            });

            return response()->noContent();

        } catch (\Exception $e) {
            // Circuit breaker triggered - queue the operation
            DeleteProductJob::dispatch($productId, $requestId);

            return response()->json([
                'status' => 'queued',
                'message' => 'Database operation failed. Request has been queued for retry.',
                'request_id' => $requestId,
                'product_id' => $productId,
                'circuit_state' => $this->circuitBreaker->getState()
            ], 202);
        }
    }
}
