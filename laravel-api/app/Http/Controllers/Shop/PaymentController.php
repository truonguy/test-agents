<?php

namespace App\Http\Controllers\Shop;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Requests\Shop\CreatePaymentRequest;
use App\Models\Order;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $payments,
    ) {}

    public function store(CreatePaymentRequest $request, Order $order): JsonResponse
    {
        abort_unless($order->customer_id === $request->user()->id, 404);
        abort_unless($order->status === OrderStatus::PENDING, 422, 'Order is not payable.');

        $result = $this->payments->createForOrder(
            $order,
            PaymentMethod::from($request->validated('method')),
        );

        return response()->json([
            'payment' => $result['payment'],
            'payment_url' => $result['payment_url'],
        ], 201);
    }
}
