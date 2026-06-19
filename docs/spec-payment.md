# Technical Spec: Payment + Reconciliation

> Nguồn: `srs-payment.md` (BA). Technical spec dành cho dev. Xây tiếp trên `laravel-api`
> (kế thừa Auth + Catalog + Order). Trạng thái: **APPROVED (Phase 1)** — quyết định ở §0.

---

## 0. Quyết định đã chốt (stakeholder)

| # | Vấn đề | Quyết định |
|---|---|---|
| 1 | COD behavior | **CONFIRMED ngay**: tạo payment COD → payment SUCCESS ngay → order PENDING→CONFIRMED (không redirect/webhook). |
| 2 | FAILED/EXPIRED + inventory | **Giữ reserve**, order giữ PENDING (auto-release out-of-scope, nhất quán Order feature). |
| 3 | Schema | **1 payment/order + nhiều payment_attempts**. Retry = attempt mới. |
| 4 | Source of truth | **Webhook/IPN** (verify chữ ký), không tin return-url. |
| 5 | Retry | **Có** — FAILED/EXPIRED → tạo attempt mới (order vẫn PENDING). |
| 6 | Reconciliation | **In-scope** (Task 8 cron). |
| 7 | Timeout | **15 phút** (config, override env) → EXPIRED. |
| 8 | Refund/installment/partial/wallet | **Out-of-scope**. |

---

## 1. Objective

Thêm thanh toán cho Order: `Order(PENDING) → Create Payment → Redirect → Webhook → Verify → Update Order`.
- **Shop** (`customer`): tạo payment cho order của mình.
- **Payment Gateway**: callback/webhook (public, verify chữ ký).
- **CRM** (`manage_order`): dashboard theo dõi/retry/search giao dịch.

Gateway: **COD** + **VNPay**. **Success:** checkout → pay → webhook → order tự cập nhật (CONFIRMED), idempotent & retry-safe.

---

## 2. Tech Stack (kế thừa)
Laravel 12, Sanctum 2 guard, spatie RBAC (`manage_order`), Controller→Service→Repository+FormRequest, `PaginationService`, `OrderStateMachine`/`OrderService.transition`, MySQL `laravel_test`.

---

## 3. Commands
```
Migrate: php artisan migrate
Test:    php artisan test --filter=Payment
Cron:    php artisan payments:reconcile   (Task 8)
Lint:    ./vendor/bin/pint
```

---

## 4. Project Structure (đề xuất)
```
app/Models/{Payment,PaymentAttempt}.php
app/Enums/{PaymentStatus,PaymentMethod}.php
app/Http/Controllers/Shop/PaymentController.php          → tạo payment
app/Http/Controllers/Payment/WebhookController.php       → callback (public)
app/Http/Controllers/Crm/PaymentDashboardController.php  → manage_order
app/Services/Payment/{PaymentService,PaymentStateMachine,PaymentReconciler}.php
app/Services/Payment/Gateways/{PaymentGateway(interface),VnpayAdapter,CodAdapter,GatewayManager}.php
app/Repositories/{Contracts,Eloquent}/PaymentRepository*.php
app/Console/Commands/ReconcilePaymentsCommand.php
database/migrations/  → payments, payment_attempts, (webhook_events?)
tests/Feature/Payment/
docs/spec-payment.md
```

---

## 5. Data Model (đề xuất)

- **payments**: `id, order_id(FK unique), method(COD|VNPAY), gateway, amount(int VND), status(enum), timestamps`
  - 1 order ↔ 1 payment (logic). Retry = thêm attempt mới, không tạo payment mới.
- **payment_attempts**: `id, payment_id(FK), provider_txn_ref(nullable), status(enum), raw_payload(json nullable), created_at`
  - 1 payment → nhiều attempt. **unique(provider_txn_ref)** để dedupe webhook.

`PaymentStatus`: `PENDING, PROCESSING, SUCCESS, FAILED, EXPIRED`.
`PaymentMethod`: `COD, VNPAY`.

> ⚠️ Vị trí dedupe / 1-payment-vs-nhiều, COD behavior — xem §9.

---

## 6. Functional Requirements & Acceptance Criteria

### FR-PM1 — Payment Schema (Task 1)
```
AC-PM1.1  migrate sạch; 1 order → 1 payment → nhiều payment_attempts
AC-PM1.2  unique(provider_txn_ref) cho dedupe
```

### FR-PM2 — Payment State Machine (Task 2)
```
AC-PM2.1  start PENDING→PROCESSING; success PROCESSING→SUCCESS; fail PROCESSING→FAILED; expire (PENDING/PROCESSING)→EXPIRED
AC-PM2.2  transition sai → 422; SUCCESS/FAILED/EXPIRED terminal cho 1 attempt
```

### FR-PM3 — Create Payment (Task 3) · `ensure_guard:customer`
`POST /api/orders/{order}/payment {method}`
```
AC-PM3.1  chỉ owner of order; order phải PENDING → else 422; người khác → 404
AC-PM3.2  trả { payment_url } (VNPay: URL gateway; COD: xem §9 COD behavior)
AC-PM3.3  amount = order.total (snapshot)
```

### FR-PM4 — Gateway Adapter (Task 4)
```
AC-PM4.1  interface create(payment): {url, ref}; verify(payload): {ref, status, valid}; query(ref): status
AC-PM4.2  VnpayAdapter: build URL + verify secure hash; CodAdapter: xem §9
AC-PM4.3  GatewayManager resolve adapter theo method
```

### FR-PM5 — Webhook (Task 5) · public `POST /api/payment/webhook`
```
AC-PM5.1  verify chữ ký (adapter); sai → 400, không đổi gì
AC-PM5.2  dedupe: cùng provider_txn_ref xử lý 2 lần → idempotent (chỉ áp 1 lần)
AC-PM5.3  hợp lệ SUCCESS → payment SUCCESS; FAILED → payment FAILED
```

### FR-PM6 — Order Sync (Task 6)
```
AC-PM6.1  payment SUCCESS → order PENDING→CONFIRMED (OrderService.transition 'confirm')
AC-PM6.2  payment FAILED/EXPIRED → order GIỮ PENDING
AC-PM6.3  webhook lặp (đã SUCCESS) → không confirm 2 lần (idempotent)
```

### FR-PM7 — CRM Payment Dashboard (Task 7) · `permission:manage_order`
`GET /api/crm/payments` (paginate, search/filter) · `GET /api/crm/payments/{payment}` · `POST /api/crm/payments/{payment}/retry`
```
AC-PM7.1  list + filter (status/method) + paginate; detail kèm attempts
AC-PM7.2  retry: tạo attempt mới cho payment chưa SUCCESS
AC-PM7.3  customer token → 401; thiếu manage_order → 403
```

### FR-PM8 — Reconciliation (Task 8) · cron
```
AC-PM8.1  query payment PENDING/PROCESSING quá hạn → gateway.query → sync status
AC-PM8.2  quá timeout không kết quả → EXPIRED
AC-PM8.3  eventual consistency: chạy lại an toàn (idempotent)
```

---

## 7. Testing Strategy
Feature tests `tests/Feature/Payment/`, MySQL `laravel_test`. **Fake gateway** (không gọi mạng) + fake chữ ký webhook. State machine test transitions. Webhook idempotency test (gửi 2 lần). Reconcile test bằng `php artisan payments:reconcile` + travel time.

## 8. Boundaries
- **Always:** webhook là source of truth; verify chữ ký; idempotent (dedupe); amount snapshot; Pint + test.
- **Ask first:** thêm package (vd SDK VNPay); đổi schema order; secret gateway (env).
- **Never:** confirm order khi chưa verify webhook; commit secret gateway; xử lý webhook trùng 2 lần; refund (out-of-scope).

## 9. Điểm chưa rõ — trạng thái

| # | Vấn đề | Trạng thái |
|---|---|---|
| 1 | COD confirm | ✅ **CONFIRMED ngay** (payment SUCCESS khi tạo) |
| 2 | Timeout | ✅ **15 phút** (config) → EXPIRED |
| 3 | Source of truth | ✅ **Webhook/IPN** (verify chữ ký) |
| 4 | Retry | ✅ **Có** (attempt mới khi FAILED/EXPIRED) |
| 5 | Schema | ✅ **1 payment/order + nhiều attempts** |
| 6 | FAILED/EXPIRED + inventory | ✅ **Giữ reserve** (order PENDING; auto-release out-of-scope) |
| 7 | Amount/currency | ✅ **VND integer**; nhân 100 khi build URL VNPay |
| 8 | Webhook auth | ✅ **Chỉ verify chữ ký**, không guard (endpoint public) |

## 10. Success Criteria — trạng thái (sau T1–T9)
- [x] AC-PM1..PM8 pass bằng feature test (**268 tests / 664 assertions xanh** — gồm Auth+Product+Order+Payment).
- [x] COD → CONFIRMED ngay; VNPay → create payment → webhook (verify) → order CONFIRMED.
- [x] Webhook idempotent (gửi 2 lần không double-confirm; dedupe provider_txn_ref + terminal-guard).
- [x] Gateway abstraction: thêm gateway mới = thêm adapter, không sửa caller.
- [x] CRM xem/retry/filter payment (manage_order); customer chỉ tạo payment cho order mình.
- [x] Reconciliation cron: payment treo quá 15' → EXPIRED / sync; idempotent.
- [x] 8 điểm §9 đã chốt; spec cập nhật.
- [~] Coverage số: không đo tự động (môi trường thiếu Xdebug/PCOV — như các feature trước). Định tính: tests/Feature/Payment phủ schema/state-machine/gateway/create/webhook/sync/dashboard/reconcile.

> ⚠️ Trước deploy: cấu hình VNPay creds thật (env) + cắm API `query()` thật vào `VnpayAdapter` cho reconciliation.
