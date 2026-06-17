<?php

namespace App\Services\Order;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Repositories\Contracts\OrderRepositoryInterface;
use App\Services\Support\PaginationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orders,
        private readonly OrderStateMachine $stateMachine,
        private readonly InventoryReservationService $reservation,
        private readonly PaginationService $pagination,
    ) {}

    /**
     * Tạo order + order_items (snapshot). Gọi trong transaction của CheckoutService.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<int, array<string, mixed>>  $items
     */
    public function create(array $attributes, array $items): Order
    {
        $order = $this->orders->create($attributes);
        $order->items()->createMany($items);

        return $order->load('items');
    }

    public function listForCustomer(Customer $customer, mixed $perPage = null): LengthAwarePaginator
    {
        $query = Order::query()->where('customer_id', $customer->id)->latest('id');

        return $this->pagination->paginate($query, $perPage);
    }

    /**
     * Danh sách order toàn hệ (CRM), filter status tuỳ chọn.
     */
    public function listAll(mixed $perPage = null, ?string $status = null): LengthAwarePaginator
    {
        $query = Order::query()->latest('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }

        return $this->pagination->paginate($query, $perPage);
    }

    /**
     * Áp dụng transition (confirm/pack/ship/complete/cancel) + side-effect tồn kho:
     * cancel → release; complete → consume. Tất cả trong 1 transaction.
     */
    public function transition(Order $order, string $action): Order
    {
        $target = $this->stateMachine->target($order->status, $action);

        return DB::transaction(function () use ($order, $action, $target) {
            if ($action === 'cancel') {
                $this->releaseStock($order);
            } elseif ($action === 'complete') {
                $this->consumeStock($order);
            }

            $order->update(['status' => $target]);

            return $order->refresh();
        });
    }

    private function releaseStock(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($variant = $this->variantOf($item)) {
                $this->reservation->release($variant, $item->quantity);
            }
        }
    }

    private function consumeStock(Order $order): void
    {
        foreach ($order->items as $item) {
            if ($variant = $this->variantOf($item)) {
                $this->reservation->consume($variant, $item->quantity);
            }
        }
    }

    private function variantOf(OrderItem $item): ?\App\Models\ProductVariant
    {
        // withTrashed: variant có thể đã bị soft-delete sau khi đặt hàng.
        return $item->variant()->withTrashed()->first();
    }
}
