# Implementation Plan: Cart + Checkout + Order Lifecycle

> Nguồn spec: `docs/spec-order.md`
> Kế thừa Auth + Product Catalog.
> Trạng thái: DRAFT

## Overview

Xây flow mua hàng:

```text
Catalog
↓
Add Cart
↓
Checkout
↓
Create Order
↓
Reserve Inventory
↓
Order Lifecycle
```

Phân hệ:

* Shop → Cart / Checkout / Order
* CRM → Order Operations

---

## Architecture Decisions

* Chỉ customer được checkout.
* Cart lưu DB.
* Inventory reserve tại checkout.
* Snapshot giá khi tạo order.
* Payment out-of-scope.

---

## Dependency Graph

```text
Cart
│
├── Cart Item
│
├── Checkout
│
├── Order
│
├── Inventory Reserve
│
└── CRM Order
```

---

# Phase 1 — Cart

### Task 1: Cart Schema

Tables:

```text
carts
cart_items
```

Acceptance:

* 1 customer = 1 active cart
* unique(cart_id, variant_id)

Scope: S

---

### Task 2: Add To Cart

Endpoint:

```http
POST /api/cart/items
```

Rules:

```text
published product
variant exists
qty > 0
```

Acceptance:

* merge quantity

Scope: M

---

### Task 3: Update Cart

Endpoint:

```http
PUT /api/cart/items/{id}
```

Acceptance:

* quantity update

Scope: S

---

### Task 4: Remove Item

Endpoint:

```http
DELETE /api/cart/items/{id}
```

Scope: S

---

### Task 5: View Cart

Endpoint:

```http
GET /api/cart
```

Response:

```text
items
subtotal
count
```

Scope: S

---

# Phase 2 — Checkout

### Task 6: Checkout

Endpoint:

```http
POST /api/checkout
```

Flow:

```text
Validate
↓
Inventory reserve
↓
Snapshot price
↓
Create order
```

Acceptance:

* transaction

Scope: L

---

### Task 7: Inventory Reserve

Rules:

```text
available
reserved
```

Acceptance:

```text
available >= reserve
```

Scope: M

---

# Phase 3 — Orders

### Task 8: Order Schema

Tables:

```text
orders
order_items
```

Status:

```text
PENDING
CONFIRMED
PACKING
SHIPPING
DELIVERED
CANCELLED
```

Scope: M

---

### Task 9: Customer Orders

Endpoints:

```http
GET /api/orders
GET /api/orders/{id}
```

Acceptance:

* chỉ thấy order của mình

Scope: M

---

### Task 10: CRM Order Management

Permission:

```text
manage_order
```

Actions:

```text
confirm
cancel
ship
complete
```

Acceptance:

* state machine

Scope: L

---

# Phase 4 — Hardening

### Task 11: Idempotency + Concurrency

Rules:

```text
double checkout
reserve race
```

Acceptance:

* không oversell

Scope: M

---

## Success Criteria

* Customer checkout được.
* Inventory không âm.
* Order lifecycle đúng.
* Không duplicate order.

---

## Open Questions

1. Reserve lúc checkout hay payment?
2. Auto release reserve sau bao lâu?
3. Cho sửa cart sau checkout không?
4. Partial cancel có support không?
