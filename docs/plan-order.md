# Implementation Plan: Cart + Checkout + Order Lifecycle

> Nguồn spec: `docs/spec-order.md`. Xây tiếp trên `laravel-api` (kế thừa Auth + Product Catalog:
> Sanctum 2 guard, spatie RBAC `manage_order`, layered, `PaginationService`, inventory per-variant).
> Trạng thái: **DRAFT — chờ review.**

## Overview
Flow mua hàng: Cart (DB) → Checkout (reserve + snapshot, 1 transaction) → Order (PENDING) → Lifecycle
(state machine). Shop=customer guard; CRM=manage_order. Reserve dùng `lockForUpdate` chống oversell.

## Architecture Decisions (từ spec §0)
- Reserve tại **checkout**: available−/reserved+ (lock). Cancel→release; Deliver→consume.
- State machine: PENDING→CONFIRMED→PACKING→SHIPPING→DELIVERED (+CANCELLED); actions confirm/pack/ship/complete/cancel.
- Cancel: CRM (PENDING/CONFIRMED/PACKING) + customer (own, PENDING).
- Idempotency: header `Idempotency-Key` + `orders.idempotency_key` unique.
- Shipping address **in scope**; payment/auto-release/partial-cancel **out**.
- `manage_order` đã seed (Auth) — không thêm permission.

## Dependency Graph
```
Cart schema ─ Add/Update/Remove/View Cart (customer)
     │
     └── Order schema (orders, order_items, OrderStatus)
              │
       InventoryReservationService (lock)  ── OrderStateMachine
              │                                    │
           Checkout (transaction) ────────────────┤
              │                                    │
       Customer Orders (list/detail/cancel)   CRM Order Mgmt (confirm/pack/ship/complete/cancel)
              │
        Idempotency + Concurrency hardening
```
Thứ tự: **Cart (T1–T5) → Order schema + reserve (T6–T7) → Checkout (T8) → Order views/ops (T9–T10) → Hardening (T11)**.

> Lưu ý thứ tự khác SRS: đưa **Order schema + reserve service** trước Checkout (checkout phụ thuộc cả hai).

---

## Task List

### Phase 1 — Cart (customer)

#### Task 1: Cart schema + models ✅ DONE
**Description:** Migration `carts` (customer_id unique), `cart_items` (unique cart_id+variant, qty>0); model Cart/CartItem + quan hệ + factories.
**Acceptance (FR-C1):** AC-C1.1 (migrate sạch, 1 customer=1 cart), AC-C1.2 (unique cart+variant, qty>0).
> 4 tests. carts.customer_id unique (1 cart/customer); cart_items unique(cart_id,variant). qty>0 enforce ở validation (T2). Models + factories.
**Verify:** `php artisan test --filter=CartSchemaTest`.
**Dependencies:** None
**Files:** `database/migrations/*`(×2), `app/Models/{Cart,CartItem}.php`, factories
**Scope:** S

#### Task 2: Add to cart (+ merge) · `ensure_guard:customer` ✅ DONE
**Description:** `POST /api/cart/items`; auto-tạo cart; validate published+variant+qty; merge qty khi trùng.
**Acceptance (FR-C2):** AC-C2.1–C2.5.
> 7 tests. Layered Controller→CartService→CartRepository (`activeCartFor` firstOrCreate). `AddCartItemRequest` closure rule (variant + product PUBLISHED). merge qty (`firstOrNew`). Route nhóm `ensure_guard:customer`. employee/no-token → 401.
**Verify:** `php artisan test --filter=AddToCartTest`.
**Dependencies:** T1
**Files:** `Shop/CartController`, `Http/Requests/Shop/AddCartItemRequest`, `Services/Shop/CartService`, `Repositories/.../CartRepository*`, route, test
**Scope:** M

#### Task 3: Update + Remove cart item ✅ DONE
**Description:** `PUT /api/cart/items/{item}` (qty>0), `DELETE /api/cart/items/{item}`; chỉ item thuộc cart mình.
**Acceptance (FR-C3, FR-C4):** AC-C3.1–C3.2, AC-C4.1.
> 6 tests. `authorizeOwnership` → item của customer khác trả 404 (không lộ tồn tại). qty min:1. UpdateCartItemRequest.
**Verify:** `php artisan test --filter=UpdateCartItemTest`.
**Dependencies:** T2
**Files:** `Shop/CartController`(update/destroy), `Http/Requests/Shop/UpdateCartItemRequest`, `CartService`, route, test
**Scope:** S

#### Task 4: View cart ✅ DONE
**Description:** `GET /api/cart` → items + subtotal + count (giá hiện tại variant).
**Acceptance (FR-C5):** AC-C5.1.
> 3 tests. `viewFor` (activeCartFor + summary). subtotal = Σ price×qty (giá hiện tại); cart rỗng → 0/0/[].
**Verify:** `php artisan test --filter=ViewCartTest`.
**Dependencies:** T2
**Files:** `Shop/CartController`(show), `CartService`(summary), route, test
**Scope:** S

### ✅ Checkpoint: Cart (T1–T4) — ĐẠT
- [x] Add/merge/update/remove/view cart hoạt động; RBAC customer. Full suite 166 passed.

---

### Phase 2 — Order foundation + reserve

#### Task 5: Order schema + OrderStatus + OrderStateMachine ✅ DONE
**Description:** Migration `orders` (status, total, idempotency_key unique, recipient/phone/address) + `order_items` (snapshot); enum `OrderStatus`; `OrderStateMachine` (transitions hợp lệ, thuần logic — chưa endpoint).
**Acceptance (FR-C8):** AC-C8.1–C8.2; unit: transition hợp lệ/không hợp lệ.
> 18 tests. `OrderStateMachine` (confirm/pack/ship/complete/cancel) + `InvalidOrderTransitionException`(422). orders/order_items + enum + factories. idempotency_key unique.
**Verify:** `php artisan test --filter="OrderSchemaTest|OrderStateMachineTest"`.
**Dependencies:** None (song song được với Cart)
**Files:** `database/migrations/*`(×2), `app/Models/{Order,OrderItem}.php`, `app/Enums/OrderStatus.php`, `app/Services/Order/OrderStateMachine.php`, factories, tests
**Scope:** M

#### Task 6: InventoryReservationService (reserve/release/consume)
**Description:** Service thao tác inventory với `lockForUpdate` trong transaction: `reserve(variant,qty)` (available−/reserved+, ném nếu thiếu), `release`, `consume`. Invariant available≥0.
**Acceptance (FR-C7):** AC-C7.1–C7.2.
**Verify:** `php artisan test --filter=InventoryReservationTest`.
**Dependencies:** None (dùng inventory có sẵn)
**Files:** `app/Services/Order/InventoryReservationService.php`, `app/Exceptions/InsufficientStockException.php`, test
**Scope:** M

### ✅ Checkpoint: Order foundation (T5–T6)
- [ ] State machine + reserve/release/consume đúng, invariant available≥0. Review.

---

### Phase 3 — Checkout + Order views

#### Task 7: Checkout (transaction) · `POST /api/checkout`
**Description:** `CheckoutService` trong DB transaction: validate cart non-empty + shipping info → reserve từng item (lock) → snapshot giá → tạo order PENDING → clear cart. Thiếu tồn → rollback toàn bộ.
**Acceptance (FR-C6):** AC-C6.1–C6.6 (rollback khi thiếu tồn; snapshot; cart rỗng sau; chỉ customer).
**Verify:** `php artisan test --filter=CheckoutTest`.
**Dependencies:** T4, T5, T6
**Files:** `Shop/CheckoutController`, `Http/Requests/Shop/CheckoutRequest`, `Services/Shop/CheckoutService`, `Services/Order/OrderService`, `Repositories/.../OrderRepository*`, route, test
**Scope:** L → nếu lớn tách T7a (happy path) / T7b (rollback/insufficient).

#### Task 8: Customer orders (list/detail/cancel) · `ensure_guard:customer`
**Description:** `GET /api/orders` (paginate), `GET /api/orders/{order}` (chỉ của mình), `POST /api/orders/{order}/cancel` (PENDING → CANCELLED + release).
**Acceptance (FR-C9, FR-C12):** AC-C9.1–C9.2, AC-C12.1–C12.2.
**Verify:** `php artisan test --filter=CustomerOrderTest`.
**Dependencies:** T7
**Files:** `Shop/OrderController`, `OrderService`(cancel dùng StateMachine + reservation), route, test
**Scope:** M

#### Task 9: CRM order management (state machine) · `permission:manage_order`
**Description:** list/detail CRM + `POST /api/crm/orders/{order}/{confirm|pack|ship|complete|cancel}`. Cancel→release, complete→consume.
**Acceptance (FR-C10):** AC-C10.1–C10.4.
**Verify:** `php artisan test --filter=CrmOrderTest`.
**Dependencies:** T7
**Files:** `Crm/OrderManagementController`, `OrderService`(transition), route, test
**Scope:** L → tách T9a (confirm/pack/ship/complete) / T9b (cancel+release, list/detail) nếu cần.

### ✅ Checkpoint: Order flow (T7–T9)
- [ ] Checkout→order→lifecycle end-to-end; reserve/release/consume đúng theo transition. Review.

---

### Phase 4 — Hardening

#### Task 10: Idempotency + Concurrency
**Description:** Header `Idempotency-Key` ở checkout → cột unique; key trùng trả lại order cũ (không tạo mới). Đảm bảo reserve dùng lockForUpdate (verify không oversell khi available=1).
**Acceptance (FR-C11):** AC-C11.1 (double checkout 1 order), AC-C11.2 (không oversell).
**Verify:** `php artisan test --filter=CheckoutIdempotencyTest`.
**Dependencies:** T7
**Files:** `CheckoutService`(idempotency), `CheckoutController`(đọc header), migration (nếu chưa có cột), test
**Scope:** M

#### Task 11: Wrap-up — tests + Pint + cập nhật spec
**Description:** Rà full suite, Pint, cập nhật spec-order §9/§10; ghi chú coverage (định tính).
**Acceptance:** Mọi AC-C* pass; full suite (gồm Auth+Product) xanh; Pint clean; spec cập nhật.
**Verify:** `php artisan test`; `./vendor/bin/pint --test`.
**Dependencies:** T1–T10
**Files:** `tests/*`, `docs/spec-order.md`
**Scope:** S

### ✅ Checkpoint: Complete
- [ ] Success Criteria spec §10 đạt; sẵn sàng review/merge.

---

## Risks and Mitigations
| Risk | Impact | Mitigation |
|---|---|---|
| Oversell khi 2 checkout đồng thời | High | `lockForUpdate` trên inventory trong transaction; test invariant; AC-C11.2 |
| Checkout không atomic → order tạo nhưng inventory chưa trừ (hoặc ngược lại) | High | Toàn bộ checkout trong `DB::transaction`; rollback khi InsufficientStock |
| Idempotency race (2 request cùng key) | Med | Cột unique idempotency_key → insert thứ 2 đụng unique → bắt và trả order cũ |
| Giá variant đổi giữa add-cart và checkout | Med | Snapshot giá tại checkout (order_items.unit_price); cart hiển thị giá hiện tại |
| State machine lọt transition sai | Med | `OrderStateMachine` tập trung + test mọi cặp; controller chỉ gọi action |
| Test single-thread không phản ánh true race | Low | Verify lock path + invariant; ghi rõ giới hạn (như benchmark Product) |

## Parallelization
- **Song song:** Cart (T1–T4) ⟂ Order foundation (T5–T6) — độc lập.
- **Tuần tự:** T7 cần T4+T5+T6; T8/T9 cần T7; T10 cần T7.

## Open Questions (không chặn code)
1. Đơn vị tiền/định dạng total (decimal 2) — theo variant.price hiện tại (đồng nhất).
2. Có cần endpoint xoá toàn bộ cart (clear) cho customer không? (đề xuất: optional, ngoài AC).
3. CRM list orders có filter theo status/customer không? (đề xuất: filter status + paginate, mở rộng nhẹ ở T9).
