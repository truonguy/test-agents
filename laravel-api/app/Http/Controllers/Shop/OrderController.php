<?php

namespace App\Http\Controllers\Shop;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Support\PaginationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaginationService $pagination,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->orders->listForCustomer($request->user(), $request->query('per_page'));

        return response()->json($this->pagination->format($paginator));
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOwnership($request, $order);

        return response()->json($order->load('items'));
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->authorizeOwnership($request, $order);

        // Customer chỉ được huỷ order đang PENDING của mình (spec FR-C12).
        abort_unless($order->status === OrderStatus::PENDING, 422, 'Only pending orders can be cancelled.');

        return response()->json($this->orders->transition($order, 'cancel'));
    }

    private function authorizeOwnership(Request $request, Order $order): void
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
    }
}
