<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\AddCartItemRequest;
use App\Http\Requests\Shop\UpdateCartItemRequest;
use App\Models\CartItem;
use App\Services\Shop\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

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

    public function update(UpdateCartItemRequest $request, CartItem $item): JsonResponse
    {
        $this->authorizeOwnership($request, $item);

        $item = $this->carts->updateItem($item, (int) $request->validated('quantity'));

        return response()->json($item);
    }

    public function destroy(Request $request, CartItem $item): Response
    {
        $this->authorizeOwnership($request, $item);

        $this->carts->removeItem($item);

        return response()->noContent();
    }

    /**
     * Item phải thuộc cart của customer hiện tại — nếu không, 404 (không lộ tồn tại).
     */
    private function authorizeOwnership(Request $request, CartItem $item): void
    {
        abort_unless($item->cart->customer_id === $request->user()->id, 404);
    }
}
