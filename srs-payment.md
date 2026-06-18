# Implementation Plan: Payment + Payment Reconciliation

> Nguồn spec: `docs/spec-payment.md`
> Kế thừa Auth + Catalog + Order.
> Trạng thái: DRAFT

## Overview

Thêm khả năng thanh toán cho Order.

Flow:

```text id="fj5l1r"
Order (PENDING)
↓
Create Payment
↓
Redirect
↓
Webhook
↓
Verify
↓
Update Order
```

Phân hệ:

* Shop → tạo payment
* Payment Gateway → callback
* CRM → theo dõi giao dịch

---

## Architecture Decisions

* Payment độc lập Order.
* Gateway abstraction.
* Webhook là source of truth.
* Retry-safe.
* Không refund phase này.

---

## Scope

Gateway:

```text id="v2aflk"
COD
VNPay
```

Out-of-scope:

```text id="f5h0zt"
refund
installment
partial payment
wallet
```

---

## Dependency Graph

```text id="2yajp6"
Order
│
├── Payment Schema
│
├── Gateway
│
├── Redirect
│
├── Callback
│
├── Webhook Verify
│
└── Reconciliation
```

---

# Phase 1 — Foundation

### Task 1: Payment Schema

Tables:

```text id="4v07l8"
payments
payment_attempts
```

Acceptance:

```text id="iksthn"
1 order
→ many attempts
```

Scope: M

---

### Task 2: Payment State Machine

States:

```text id="ox8avz"
PENDING
PROCESSING
SUCCESS
FAILED
EXPIRED
```

Transitions:

```text id="15b6ca"
start
success
fail
expire
```

Scope: S

---

# Phase 2 — Payment Creation

### Task 3: Create Payment

Endpoint:

```http id="f0vxu9"
POST /api/orders/{order}/payment
```

Response:

```text id="x1vsx7"
payment_url
```

Acceptance:

* chỉ owner

Scope: M

---

### Task 4: Gateway Adapter

Interface:

```text id="w0knpf"
create()

verify()

query()
```

Implement:

```text id="s4b7av"
VnpayAdapter
CodAdapter
```

Scope: L

---

# Phase 3 — Callback

### Task 5: Webhook

Endpoint:

```http id="8ep8lu"
POST /api/payment/webhook
```

Rules:

```text id="i6n7dr"
verify signature
dedupe
```

Acceptance:

* idempotent

Scope: L

---

### Task 6: Order Sync

Rules:

```text id="uqp4nh"
SUCCESS
→ CONFIRMED
```

```text id="o1j59e"
FAILED
→ PENDING
```

Scope: M

---

# Phase 4 — CRM

### Task 7: Payment Dashboard

Permission:

```text id="o7yl2o"
manage_order
```

Features:

```text id="5f2v8d"
view
retry
search
```

Scope: M

---

# Phase 5 — Hardening

### Task 8: Reconciliation

Cron:

```text id="r8jpyu"
query pending
sync result
```

Acceptance:

* eventual consistency

Scope: M

---

## Success Criteria

```text id="7a4vwc"
checkout
→ pay
→ webhook
→ order update
```

---

## Open Questions

1. COD có confirm ngay không?
2. Payment timeout bao lâu?
3. Gateway nào là nguồn sự thật?
4. Có cho retry payment không?
