<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreProductRequest;
use App\Http\Requests\Crm\UpdateProductRequest;
use App\Models\Product;
use App\Services\Crm\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductService $products,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->products->list()]);
    }

    public function show(Product $product): JsonResponse
    {
        return response()->json($product->load(['category', 'variants']));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->products->create($request->validated());

        return response()->json($product, 201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->products->update($product, $request->validated());

        return response()->json($product);
    }

    public function destroy(Product $product): Response
    {
        $this->products->delete($product);

        return response()->noContent();
    }
}
