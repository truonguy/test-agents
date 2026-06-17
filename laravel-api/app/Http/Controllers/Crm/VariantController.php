<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\StoreVariantRequest;
use App\Http\Requests\Crm\UpdateVariantRequest;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\Crm\VariantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class VariantController extends Controller
{
    public function __construct(
        private readonly VariantService $variants,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return response()->json(['data' => $this->variants->listFor($product)]);
    }

    public function store(StoreVariantRequest $request, Product $product): JsonResponse
    {
        $variant = $this->variants->create($product, $request->validated());

        return response()->json($variant, 201);
    }

    public function update(UpdateVariantRequest $request, ProductVariant $variant): JsonResponse
    {
        $variant = $this->variants->update($variant, $request->validated());

        return response()->json($variant);
    }

    public function destroy(ProductVariant $variant): Response
    {
        $this->variants->delete($variant);

        return response()->noContent();
    }
}
