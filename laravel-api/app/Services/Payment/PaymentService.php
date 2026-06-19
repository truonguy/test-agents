<?php

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\Order\OrderService;
use App\Services\Payment\Gateways\GatewayManager;
use App\Services\Support\PaginationService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $payments,
        private readonly GatewayManager $gateways,
        private readonly PaymentStateMachine $stateMachine,
        private readonly OrderService $orders,
        private readonly PaginationService $pagination,
    ) {}

    /**
     * Danh sách payment (CRM), filter status/method tuỳ chọn.
     */
    public function listAll(mixed $perPage = null, ?string $status = null, ?string $method = null): LengthAwarePaginator
    {
        $query = Payment::query()->latest('id');

        if ($status !== null && $status !== '') {
            $query->where('status', $status);
        }
        if ($method !== null && $method !== '') {
            $query->where('method', $method);
        }

        return $this->pagination->paginate($query, $perPage);
    }

    /**
     * Retry payment chưa thành công: đưa về PROCESSING + tạo attempt mới qua gateway.
     *
     * @return array{payment: Payment, payment_url: string|null}
     */
    public function retry(Payment $payment): array
    {
        abort_if($payment->status === PaymentStatus::SUCCESS, 422, 'Payment already completed.');

        return DB::transaction(function () use ($payment) {
            $this->applyStatus($payment, 'retry'); // → PROCESSING

            $result = $this->gateways->for($payment->method)->create($payment);

            $payment->attempts()->create([
                'provider_txn_ref' => $result['ref'],
                'status' => PaymentStatus::PROCESSING->value,
            ]);

            return [
                'payment' => $payment->load('attempts'),
                'payment_url' => $result['url'],
            ];
        });
    }

    /**
     * Tạo payment cho order (PENDING). COD → SUCCESS + confirm order ngay; VNPAY → PROCESSING + payment_url.
     *
     * @return array{payment: Payment, payment_url: string|null}
     */
    public function createForOrder(Order $order, PaymentMethod $method): array
    {
        return DB::transaction(function () use ($order, $method) {
            $gateway = $this->gateways->for($method);

            $payment = $this->payments->create([
                'order_id' => $order->id,
                'method' => $method->value,
                'gateway' => $gateway->name(),
                'amount' => (int) round((float) $order->total),
                'status' => PaymentStatus::PENDING->value,
            ]);

            $result = $gateway->create($payment);

            $attempt = $payment->attempts()->create([
                'provider_txn_ref' => $result['ref'],
                'status' => PaymentStatus::PENDING->value,
            ]);

            $this->applyStatus($payment, 'start'); // PENDING → PROCESSING

            if ($method === PaymentMethod::COD) {
                $this->applyStatus($payment, 'success'); // PROCESSING → SUCCESS
                $attempt->update(['status' => PaymentStatus::SUCCESS->value]);
                $this->orders->transition($order, 'confirm'); // PENDING → CONFIRMED
            } else {
                $attempt->update(['status' => PaymentStatus::PROCESSING->value]);
            }

            return [
                'payment' => $payment->load('attempts'),
                'payment_url' => $result['url'],
            ];
        });
    }

    /**
     * Xử lý webhook/callback gateway (source of truth). Verify chữ ký → dedupe (idempotent) →
     * cập nhật payment + attempt. Chữ ký sai → 400; ref lạ → 404.
     *
     * @param  array<string, mixed>  $payload
     */
    public function handleWebhook(string $gateway, array $payload): Payment
    {
        $result = $this->gateways->for($gateway)->verify($payload);

        abort_unless($result['valid'], 400, 'Invalid signature.');

        $ref = $result['ref'];
        $payment = $ref !== null ? $this->payments->findByProviderRef((string) $ref) : null;

        abort_if($payment === null, 404, 'Unknown transaction.');

        // Idempotent: payment đã ở trạng thái terminal → bỏ qua (dedupe webhook trùng).
        if (in_array($payment->status, [PaymentStatus::SUCCESS, PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
            return $payment;
        }

        return DB::transaction(function () use ($payment, $result, $payload, $ref) {
            $payment->attempts()
                ->where('provider_txn_ref', $ref)
                ->first()
                ?->update(['status' => $result['status']->value, 'raw_payload' => $payload]);

            if ($result['status'] === PaymentStatus::SUCCESS) {
                $this->markSuccess($payment);
            } else {
                $this->applyStatus($payment, 'fail');
                // FAILED/EXPIRED → order giữ PENDING (không đổi).
            }

            return $payment->fresh();
        });
    }

    /**
     * Đối soát 1 payment treo: query gateway → SUCCESS/FAILED đồng bộ; không có kết quả → EXPIRED.
     * Idempotent: bỏ qua nếu đã terminal.
     */
    public function reconcilePayment(Payment $payment): void
    {
        if (in_array($payment->status, [PaymentStatus::SUCCESS, PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
            return;
        }

        $ref = $payment->attempts()->latest('id')->first()?->provider_txn_ref;
        $status = $ref !== null
            ? $this->gateways->for($payment->method)->query($ref)
            : PaymentStatus::PROCESSING;

        DB::transaction(function () use ($payment, $status) {
            match ($status) {
                PaymentStatus::SUCCESS => $this->markSuccess($payment),
                PaymentStatus::FAILED => $this->applyStatus($payment, 'fail'),
                default => $this->applyStatus($payment, 'expire'), // không có kết quả sau timeout
            };
        });
    }

    /**
     * Payment SUCCESS → đổi status + confirm order.
     */
    private function markSuccess(Payment $payment): void
    {
        $this->applyStatus($payment, 'success');
        $this->confirmOrder($payment);
    }

    /**
     * Payment SUCCESS → confirm order (PENDING→CONFIRMED). Idempotent: chỉ confirm khi order PENDING.
     */
    private function confirmOrder(Payment $payment): void
    {
        $order = $payment->order;

        if ($order !== null && $order->status === OrderStatus::PENDING) {
            $this->orders->transition($order, 'confirm');
        }
    }

    private function applyStatus(Payment $payment, string $action): void
    {
        $target = $this->stateMachine->target($payment->status, $action);
        $payment->update(['status' => $target->value]);
    }
}

