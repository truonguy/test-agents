<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Http\Requests\Crm\UploadMediaRequest;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\Media\MediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class ProductMediaController extends Controller
{
    public function __construct(
        private readonly MediaService $media,
    ) {}

    public function index(Product $product): JsonResponse
    {
        return response()->json(['data' => $product->media]);
    }

    public function store(UploadMediaRequest $request, Product $product): JsonResponse
    {
        $created = collect($request->file('images'))
            ->map(fn ($file) => $this->media->upload($product, $file))
            ->values();

        return response()->json(['data' => $created], 201);
    }

    public function setPrimary(ProductMedia $media): JsonResponse
    {
        return response()->json($this->media->setPrimary($media));
    }

    public function destroy(ProductMedia $media): Response
    {
        $media->delete();

        return response()->noContent();
    }
}
