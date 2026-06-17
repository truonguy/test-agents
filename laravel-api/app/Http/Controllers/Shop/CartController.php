<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddCartItemRequest;
use App\Services\Shop\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function __construct(
        private readonly CartService $carts,
    ) {}

    public function store(AddCartItemRequest $request): JsonResponse
    {
        $item = $this->carts->addItem(
            $request->user(),
            (int) $request->validated('product_variant_id'),
            (int) $request->validated('quantity'),
        );

        return response()->json($item, 201);
    }
}
