# Technical Spec: Cart + Checkout + Order Lifecycle

> Nguồn: `srs-cart.md` (BA). Technical spec dành cho dev. Xây tiếp trên `laravel-api`
> (kế thừa Auth + Product Catalog). Trạng thái: **APPROVED (Phase 1)** — quyết định ở §0.

---

## 0. Quyết định đã chốt (stakeholder)

| # | Vấn đề | Quyết định |
|---|---|---|
| 1 | State machine | **Thêm action `pack`**: PENDING→CONFIRMED→PACKING→SHIPPING→DELIVERED (+ CANCELLED). |
| 2 | Reserve lifecycle | **Checkout**: available−=qty, reserved+=qty. **CANCELLED**: release (available+=qty, reserved−=qty). **DELIVERED**: consume (reserved−=qty). |
| 3 | Cancel quyền | **CRM** (manage_order) huỷ ở PENDING/CONFIRMED/PACKING; **customer** tự huỷ order **PENDING** của mình. |
| 4 | Idempotency | **Header `Idempotency-Key`** + cột `orders.idempotency_key` unique. |
| 5 | Auto-release reserve | **Out-of-scope** (chỉ nhả khi cancel). |
| 6 | Partial cancel | **Out-of-scope** (huỷ cả order). |
| 7 | Shipping address | **TRONG scope** — order lưu thông tin nhận hàng (recipient, phone, address); checkout yêu cầu. Payment vẫn out-of-scope. |

---

## 1. Objective

Flow mua hàng end-to-end: Catalog → Add Cart → Checkout → Create Order → Reserve Inventory → Order Lifecycle.

- **Shop** (`customer`): cart, checkout, xem order của mình.
- **CRM** (`employee`/`admin`, `permission:manage_order`): vận hành order (confirm/cancel/ship/complete).

**Success:** customer checkout được; inventory không bao giờ âm/oversell; order lifecycle theo state machine; không duplicate order.

---

## 2. Tech Stack (kế thừa)
Laravel 12, Sanctum 2 guard, spatie RBAC, Controller→Service→Repository+FormRequest, `PaginationService`, MySQL `laravel_test`.

---

## 3. Commands
```
Migrate: php artisan migrate
Test:    php artisan test --filter=Cart   (hoặc Order/Checkout)
Lint:    ./vendor/bin/pint
```

---

## 4. Project Structure (đề xuất)
```
app/Models/{Cart,CartItem,Order,OrderItem}.php
app/Http/Controllers/Shop/{CartController,CheckoutController,OrderController}.php
app/Http/Controllers/Crm/OrderManagementController.php
app/Services/Shop/{CartService,CheckoutService}.php
app/Services/Order/{OrderService,OrderStateMachine,InventoryReservationService}.php
app/Repositories/{Contracts,Eloquent}/{Cart,Order}Repository*.php
app/Enums/OrderStatus.php
database/migrations/  → carts, cart_items, orders, order_items, (checkout_idempotency?)
tests/Feature/{Cart,Checkout,Order}/
docs/spec-order.md
```

---

## 5. Data Model (đề xuất)

- **carts**: `id, customer_id(FK unique → 1 active cart), timestamps`
- **cart_items**: `id, cart_id(FK), product_variant_id(FK), quantity(uint>0), timestamps` · **unique(cart_id, product_variant_id)**
- **orders**: `id, customer_id(FK), status(enum), total(decimal), idempotency_key(nullable, unique), recipient_name, recipient_phone, shipping_address, timestamps`
- **order_items**: `id, order_id(FK), product_variant_id(FK), product_name, sku, unit_price(decimal snapshot), quantity, line_total(decimal)`

`OrderStatus` enum: `PENDING, CONFIRMED, PACKING, SHIPPING, DELIVERED, CANCELLED`.

Inventory dùng lại `inventories` (variant): `available_stock`, `reserved_stock` (đã có từ Product Catalog).

---

## 6. Functional Requirements & Acceptance Criteria

### FR-C1 — Cart Schema (Task 1)
```
AC-C1.1  migrate sạch; 1 customer = 1 active cart (unique customer_id)
AC-C1.2  unique(cart_id, product_variant_id); quantity > 0
```

### FR-C2 — Add To Cart (Task 2) · `ensure_guard:customer`
`POST /api/cart/items {product_variant_id, quantity}`
```
AC-C2.1  thêm item → 201/200; auto tạo cart nếu chưa có
AC-C2.2  product phải PUBLISHED + variant tồn tại → else 422
AC-C2.3  quantity > 0 → else 422
AC-C2.4  thêm trùng variant → MERGE quantity (cộng dồn), không tạo dòng mới
AC-C2.5  customer khác / employee token → 401/403
```

### FR-C3 — Update Cart Item (Task 3)
`PUT /api/cart/items/{item}`
```
AC-C3.1  đổi quantity (>0) của item thuộc cart của chính mình
AC-C3.2  item không thuộc cart mình → 403/404
```

### FR-C4 — Remove Item (Task 4) · `DELETE /api/cart/items/{item}`
```
AC-C4.1  xoá item của mình → 204; item người khác → 403/404
```

### FR-C5 — View Cart (Task 5) · `GET /api/cart`
```
AC-C5.1  trả items + subtotal + count (giá hiện tại của variant)
```

### FR-C6 — Checkout (Task 6) · `POST /api/checkout`
Req: `{recipient_name, recipient_phone, shipping_address}`. Flow trong **1 transaction**: validate cart + thông tin nhận hàng → reserve inventory (lock) → snapshot giá → tạo order (PENDING) → clear cart.
```
AC-C6.1  cart rỗng → 422
AC-C6.2  reserve thành công: order PENDING, order_items snapshot unit_price/name/sku, total đúng, lưu recipient/phone/address
AC-C6.3  thiếu tồn (available < qty) cho 1 variant → 422, KHÔNG tạo order, KHÔNG đổi inventory (rollback)
AC-C6.4  sau checkout: cart rỗng
AC-C6.5  chỉ customer; employee → 403/401
AC-C6.6  thiếu recipient_name/phone/address → 422
```

### FR-C7 — Inventory Reserve (Task 7)
```
AC-C7.1  reserve: available -= qty, reserved += qty (atomic, lockForUpdate)
AC-C7.2  available_stock không bao giờ < 0 (invariant)
```

### FR-C8 — Order Schema (Task 8)
```
AC-C8.1  orders/order_items migrate sạch; status enum đúng 6 trạng thái
AC-C8.2  order_items giữ snapshot (unit_price, product_name, sku, line_total)
```

### FR-C9 — Customer Orders (Task 9) · `ensure_guard:customer`
`GET /api/orders` (paginate) · `GET /api/orders/{order}`
```
AC-C9.1  customer chỉ thấy order của chính mình (order người khác → 404)
AC-C9.2  list paginate (PaginationService), detail kèm order_items
```

### FR-C10 — CRM Order Management (Task 10) · `permission:manage_order`
`POST /api/crm/orders/{order}/{confirm|pack|ship|complete|cancel}` (+ list/detail CRM)
```
AC-C10.1  state machine: confirm PENDING→CONFIRMED; pack CONFIRMED→PACKING; ship PACKING→SHIPPING; complete SHIPPING→DELIVERED; cancel (PENDING/CONFIRMED/PACKING)→CANCELLED
AC-C10.2  transition sai (vd complete khi PENDING, cancel khi SHIPPING) → 422
AC-C10.3  cancel → release reserve (available += qty, reserved -= qty); complete → consume reserve (reserved -= qty)
AC-C10.4  customer token → 401; employee thiếu manage_order → 403
```

### FR-C12 — Customer cancel order (own, PENDING) · `ensure_guard:customer`
`POST /api/orders/{order}/cancel`
```
AC-C12.1  customer huỷ order PENDING của mình → CANCELLED + release reserve
AC-C12.2  order không PENDING → 422; order người khác → 404
```

### FR-C11 — Idempotency + Concurrency (Task 11)
```
AC-C11.1  double checkout cùng Idempotency-Key → chỉ 1 order (lần 2 trả lại order cũ)
AC-C11.2  reserve race (đồng thời) → không oversell (lockForUpdate; tổng reserved ≤ stock)
```

---

## 7. Testing Strategy
Feature tests `tests/Feature/{Cart,Checkout,Order}/` trên MySQL `laravel_test`, `RefreshDatabase`. State machine test mọi transition hợp lệ/không hợp lệ. Concurrency: test reserve dưới giới hạn + lockForUpdate (mô phỏng tuần tự — true race khó trong test đơn luồng; verify invariant + lock path).

## 8. Boundaries
- **Always:** checkout trong DB transaction + lockForUpdate; chỉ customer checkout; snapshot giá; validate state machine; soft/no oversell; Pint + test.
- **Ask first:** đổi schema inventory; thêm dependency; cơ chế idempotency; auto-release job.
- **Never:** oversell (available < 0); customer thao tác order người khác; CRM bỏ check manage_order; sửa giá order đã tạo.

## 9. Điểm chưa rõ — trạng thái

| # | Vấn đề | Trạng thái |
|---|---|---|
| 1 | Reserve khi DELIVERED | ✅ **consume** (reserved−=qty) |
| 2 | PACKING | ✅ **thêm action `pack`** (CONFIRMED→PACKING→SHIPPING) |
| 3 | Cancel quyền | ✅ **CRM + customer (PENDING của mình)** |
| 4 | Cancel ở trạng thái nào | ✅ CRM: PENDING/CONFIRMED/PACKING; customer: PENDING. Sau SHIPPING không huỷ |
| 5 | Auto-release timeout | ✅ **out-of-scope** |
| 6 | Sửa cart sau checkout | ✅ N/A — cart clear sau checkout |
| 7 | Partial cancel | ✅ **out-of-scope** (huỷ cả order) |
| 8 | Shipping address | ✅ **TRONG scope** — order lưu recipient/phone/address; checkout yêu cầu |
| 9 | Idempotency | ✅ **header `Idempotency-Key`** + `orders.idempotency_key` unique |

## 10. Success Criteria
- [ ] AC-C1..C11 pass bằng feature test.
- [ ] Customer checkout được; cart→order đúng snapshot.
- [ ] Inventory không âm / không oversell (lock + invariant).
- [ ] Order lifecycle theo state machine; transition sai → 422.
- [ ] Không duplicate order (idempotency).
- [ ] customer chỉ thấy order mình; CRM cần manage_order.
- [ ] 9 điểm §9 được chốt và spec cập nhật trước Implement.
