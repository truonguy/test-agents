<?php

namespace App\Http\Controllers\Crm;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Order\OrderService;
use App\Services\Support\PaginationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderManagementController extends Controller
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaginationService $pagination,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->orders->listAll($request->query('per_page'), $request->query('status'));

        return response()->json($this->pagination->format($paginator));
    }

    public function show(Order $order): JsonResponse
    {
        return response()->json($order->load('items'));
    }

    /**
     * Áp dụng transition vòng đời (confirm/pack/ship/complete/cancel) — route đã giới hạn action.
     */
    public function apply(Order $order, string $action): JsonResponse
    {
        return response()->json($this->orders->transition($order, $action));
    }
}
