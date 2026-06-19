# Implementation Plan: Payment + Reconciliation

> Nguồn spec: `docs/spec-payment.md`. Xây tiếp trên `laravel-api` (kế thừa Auth + Catalog + Order:
> guard customer, spatie `manage_order`, layered, `PaginationService`, `OrderService.transition`).
> Trạng thái: **DRAFT — chờ review.**

## Overview
Thanh toán cho Order: COD (confirm ngay) + VNPay (redirect → webhook source-of-truth → confirm).
1 payment/order + nhiều attempts; webhook idempotent; retry; reconciliation cron; timeout 15'.

## Architecture Decisions (từ spec §0)
- 1 `payments`/order + `payment_attempts` (many). Retry = attempt mới.
- Gateway abstraction (interface create/verify/query) + `VnpayAdapter` + `CodAdapter` + `GatewayManager`.
- Webhook = source of truth (verify chữ ký), dedupe theo `provider_txn_ref`.
- SUCCESS → `OrderService.transition(order,'confirm')`; FAILED/EXPIRED → order giữ PENDING (giữ reserve).
- COD → SUCCESS + confirm ngay (không webhook). Timeout 15' (config) → EXPIRED (reconcile).
- Refund/installment/partial/wallet out-of-scope.

## Dependency Graph
```
Payment schema + PaymentStatus/PaymentMethod + PaymentStateMachine
        │
   Gateway abstraction (interface + Cod + Vnpay + Manager)
        │
   Create Payment (Shop) ── COD: confirm ngay ; VNPAY: trả payment_url
        │
   Webhook (verify + dedupe) ── Order Sync (SUCCESS→confirm)
        │
   CRM Dashboard (list/detail/retry) ── Reconciliation cron (timeout/EXPIRED)
```
Thứ tự: **Foundation (T1–T2) → Gateway (T3) → Create (T4) → Webhook+Sync (T5–T6) → CRM (T7) → Reconcile (T8) → Wrap (T9)**.

> Thêm T9 wrap-up (Pint/spec) so với SRS 8 task — đồng bộ quy trình các feature trước.

---

## Task List

### Phase 1 — Foundation

#### Task 1: Payment schema + enums + models ✅ DONE
**Description:** Migration `payments` (order_id unique, method, gateway, amount int, status) + `payment_attempts` (payment_id, provider_txn_ref unique-nullable, status, raw_payload json); enums `PaymentStatus`/`PaymentMethod`; models + quan hệ + factories.
**Acceptance (FR-PM1):** AC-PM1.1 (1 order→1 payment→many attempts), AC-PM1.2 (unique provider_txn_ref).
> 6 tests. payments.order_id unique (1/order); payment_attempts.provider_txn_ref unique-nullable (dedupe). amount unsignedBigInteger (VND). enums + raw_payload array cast. factories (cod()/status()).
**Verify:** `php artisan test --filter=PaymentSchemaTest`.
**Dependencies:** None
**Files:** `database/migrations/*`(×2), `app/Models/{Payment,PaymentAttempt}.php`, `app/Enums/{PaymentStatus,PaymentMethod}.php`, factories
**Scope:** M

#### Task 2: PaymentStateMachine ✅ DONE
**Description:** `PaymentStateMachine` (start PENDING→PROCESSING; success →SUCCESS; fail →FAILED; expire (PENDING/PROCESSING)→EXPIRED) + `InvalidPaymentTransitionException`(422). Thuần logic.
**Acceptance (FR-PM2):** AC-PM2.1–PM2.2; unit transitions hợp lệ/không.
> 12 tests (#[DataProvider]). Mirror OrderStateMachine. success/fail chỉ từ PROCESSING; expire từ PENDING/PROCESSING. Terminal SUCCESS/FAILED/EXPIRED.
**Verify:** `php artisan test --filter=PaymentStateMachineTest`.
**Dependencies:** None (song song T1)
**Files:** `app/Services/Payment/PaymentStateMachine.php`, `app/Exceptions/InvalidPaymentTransitionException.php`, test
**Scope:** S

### ✅ Checkpoint: Foundation (T1–T2) — ĐẠT
- [x] migrate sạch; state machine đúng. Full suite 233 passed.

---

### Phase 2 — Gateway + Create

#### Task 3: Gateway abstraction (interface + Cod + Vnpay + Manager) ✅ DONE
**Description:** Interface `PaymentGateway` (`create(Payment): {url, ref}`, `verify(array): {ref, status, valid}`, `query(ref): status`); `CodAdapter` (create → SUCCESS ngay, no url), `VnpayAdapter` (build URL + verify secure hash HMAC), `GatewayManager` resolve theo method. Config secret qua env.
**Acceptance (FR-PM4):** AC-PM4.1–PM4.3.
> 7 tests. `config/payment.php` (timeout 15', vnpay creds env). VnpayAdapter HMAC-SHA512 (`hash()` public, amount ×100); verify check chữ ký + ResponseCode 00→SUCCESS. CodAdapter url=null ref=COD-. GatewayManager resolve cod/vnpay, lạ→InvalidArgumentException. Không thêm SDK.
**Verify:** `php artisan test --filter=GatewayTest`.
**Dependencies:** T1
**Files:** `app/Services/Payment/Gateways/{PaymentGateway,CodAdapter,VnpayAdapter,GatewayManager}.php`, `config/payment.php`, test
**Scope:** L → tách T3a (interface+Manager+Cod) / T3b (Vnpay+hash) nếu cần.
> ⚠️ *Ask first* nếu cần SDK VNPay; mặc định tự build URL + HMAC (không thêm package).

#### Task 4: Create Payment (Shop) · `ensure_guard:customer` ✅ DONE
**Description:** `POST /api/orders/{order}/payment {method}`; owner + order PENDING; tạo payment (amount=order.total) + attempt; COD → SUCCESS + confirm order ngay; VNPAY → PROCESSING + trả payment_url.
**Acceptance (FR-PM3, + COD §0):** AC-PM3.1–PM3.3; COD → order CONFIRMED ngay.
> 7 tests. `PaymentService.createForOrder` (DB::transaction): gateway.create → attempt + state machine start; COD → success + OrderService.transition('confirm'); VNPay → PROCESSING + payment_url. amount snapshot. owner→404, !PENDING→422, method lạ→422, employee→401.
**Verify:** `php artisan test --filter=CreatePaymentTest`.
**Dependencies:** T2, T3
**Files:** `Shop/PaymentController`, `Http/Requests/Shop/CreatePaymentRequest`, `Services/Payment/PaymentService`, `Repositories/.../PaymentRepository*`, route, test
**Scope:** M

### ✅ Checkpoint: Create (T3–T4) — ĐẠT
- [x] COD confirm ngay; VNPay trả payment_url; owner-only. Full suite 247 passed.

---

### Phase 3 — Webhook + Order Sync

#### Task 5: Webhook (verify + dedupe) · public `POST /api/payment/webhook` ✅ DONE
**Description:** verify chữ ký (adapter); dedupe theo provider_txn_ref (xử lý 1 lần); cập nhật payment + attempt theo kết quả. Chữ ký sai → 400.
**Acceptance (FR-PM5):** AC-PM5.1–PM5.3 (idempotent, dedupe, signature).
> 5 tests. `handleWebhook` verify→400 nếu sai; tìm payment theo ref→404 nếu lạ; terminal-status→idempotent return; success/fail cập nhật payment+attempt+raw_payload. Route public; controller loại `gateway` (query) khỏi payload verify. Order sync ở T6.
**Verify:** `php artisan test --filter=WebhookTest`.
**Dependencies:** T3, T4
**Files:** `Payment/WebhookController`, `Services/Payment/PaymentService`(handleWebhook), route (ngoài guard), test
**Scope:** L → tách T5a (verify+update) / T5b (dedupe/idempotency) nếu cần.

#### Task 6: Order Sync ✅ DONE
**Description:** payment SUCCESS → `OrderService.transition(order,'confirm')` (idempotent nếu đã CONFIRMED); FAILED/EXPIRED → order giữ PENDING. Side-effect gắn trong handleWebhook/PaymentService.
**Acceptance (FR-PM6):** AC-PM6.1–PM6.3.
> 4 tests. `confirmOrder` chỉ confirm khi order PENDING (idempotent — webhook trùng/đã-confirmed an toàn). FAILED → order PENDING. Lưu ý: tránh đặt tên helper test `setup()` (đụng setUp).
**Verify:** `php artisan test --filter=OrderSyncTest`.
**Dependencies:** T5
**Files:** `Services/Payment/PaymentService`(sync order), test
**Scope:** M

### ✅ Checkpoint: Webhook+Sync (T5–T6) — ĐẠT
- [x] VNPay webhook → payment SUCCESS → order CONFIRMED; idempotent; chữ ký sai 400. Full suite 256 passed.

---

### Phase 4 — CRM + Reconciliation

#### Task 7: CRM Payment Dashboard · `permission:manage_order` ✅ DONE
**Description:** `GET /api/crm/payments` (filter status/method, paginate), `GET /api/crm/payments/{payment}` (kèm attempts), `POST /api/crm/payments/{payment}/retry` (attempt mới nếu chưa SUCCESS).
**Acceptance (FR-PM7):** AC-PM7.1–PM7.3.
> 7 tests. Thêm action `retry` vào PaymentStateMachine (→PROCESSING, trừ SUCCESS). `listAll` filter+paginate, `retry` (422 nếu SUCCESS). customer→401, thiếu manage_order→403.
**Verify:** `php artisan test --filter=PaymentDashboardTest`.
**Dependencies:** T4
**Files:** `Crm/PaymentDashboardController`, `PaymentService`(retry/list), route, test
**Scope:** M

#### Task 8: Reconciliation cron ✅ DONE
**Description:** `php artisan payments:reconcile`: query payment PENDING/PROCESSING quá timeout → `gateway.query(ref)` sync; quá 15' không kết quả → EXPIRED. Idempotent, chạy lại an toàn.
**Acceptance (FR-PM8):** AC-PM8.1–PM8.3.
> 5 tests. `PaymentReconciler.reconcile` quét payment treo quá cutoff → `reconcilePayment` (query gateway: SUCCESS→confirm, FAILED→fail, else→EXPIRED). Command `payments:reconcile`. Refactor `markSuccess` dùng chung webhook+reconcile. Terminal untouched (idempotent).
**Verify:** `php artisan test --filter=ReconcileTest` (travel time + fake gateway query).
**Dependencies:** T5
**Files:** `Console/Commands/ReconcilePaymentsCommand`, `Services/Payment/PaymentReconciler`, `config/payment.php`(timeout), `bootstrap/app.php`(schedule? optional), test
**Scope:** M

### ✅ Checkpoint: CRM+Reconcile (T7–T8) — ĐẠT
- [x] Dashboard list/detail/retry; reconcile sync/EXPIRED đúng. Full suite 268 passed.

---

### Phase 5 — Wrap-up

#### Task 9: Tests + Pint + cập nhật spec
**Description:** Full suite, Pint, cập nhật spec-payment §9/§10; ghi chú coverage (định tính).
**Acceptance:** Mọi AC-PM* pass; full suite (gồm Auth+Product+Order) xanh; Pint clean; spec cập nhật.
**Verify:** `php artisan test`; `./vendor/bin/pint --test`.
**Dependencies:** T1–T8
**Files:** `tests/*`, `docs/spec-payment.md`
**Scope:** S

### ✅ Checkpoint: Complete
- [ ] Success Criteria spec §10 đạt; sẵn sàng review/merge.

---

## Risks and Mitigations
| Risk | Impact | Mitigation |
|---|---|---|
| Webhook xử lý trùng → double-confirm | High | dedupe `provider_txn_ref` unique + check payment đã SUCCESS trước khi confirm; test gửi 2 lần |
| Chữ ký webhook giả mạo | High | verify HMAC trong adapter; sai → 400; secret qua env, không commit |
| Confirm order khi payment chưa verify | High | Chỉ confirm trong handleWebhook sau verify; COD confirm là path riêng có chủ đích |
| Reserve treo khi payment EXPIRED | Med | Theo §0 giữ reserve (out-of-scope auto-release); ghi rõ — không tự cancel |
| VNPay amount sai đơn vị (×100) | Med | Lưu VND integer; nhân 100 chỉ khi build URL; test amount |
| Reconcile chạy chồng / double-process | Med | Idempotent qua state machine + query; lock/`->where(status PROCESSING)` |

## Parallelization
- **Song song:** T1 (schema) ⟂ T2 (state machine).
- **Tuần tự:** T3→T4; T5 cần T3+T4; T6 cần T5; T7 cần T4; T8 cần T5.

## Open Questions (không chặn code)
1. VNPay sandbox creds thật cho integration — dev dùng fake adapter; env thật khi deploy.
2. CRM retry có giới hạn số lần không? (đề xuất: không giới hạn cứng, ngoài AC).
3. Lưu raw webhook payload để audit bao lâu? (đề xuất: giữ trong payment_attempts.raw_payload).
