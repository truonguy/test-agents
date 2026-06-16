# Implementation Plan: Product Catalog Management (Shop + CRM)

> Nguồn spec: `docs/spec-product.md`
> Laravel 11 + Repository + Policy + Search + Inventory foundation
> Trạng thái: DRAFT

## Overview

Xây dựng Product Catalog cho 2 phân hệ:

* Shop → xem sản phẩm
* CRM → quản lý sản phẩm

Triển khai theo vertical slice để luôn deploy được.

---

## Architecture Decisions

* Product tách Inventory.
* Variant độc lập Product.
* Soft delete toàn bộ catalog.
* Search dùng Query Builder + Criteria.
* Upload media qua abstraction (`MediaService`).
* Publish state kiểm soát hiển thị.

---

## Dependency Graph

```text
Category
 │
 ├── Product
 │      │
 │      ├── Variant
 │      │
 │      ├── Inventory
 │      │
 │      ├── Media
 │      │
 │      └── Search
 │
 └── Shop Catalog
```

Thứ tự:

```text
Foundation
→ Product CRUD
→ Inventory
→ Search
→ Shop APIs
→ Hardening
```

---

# Phase 1 — Foundation

### Task 1: Migrations + Models

Description:

* categories
* products
* product_variants
* product_media

Acceptance:

* migrate sạch
* soft delete hoạt động
* slug unique

Files:

```text
database/migrations/*
app/Models/*
```

Scope: M

---

### Task 2: Category CRUD (CRM)

Endpoints:

```http
GET /crm/categories
POST /crm/categories
PUT /crm/categories/{id}
DELETE /crm/categories/{id}
```

Permission:

```text
manage_product
```

Acceptance:

* CRUD pass
* soft delete

Scope: M

---

# Phase 2 — Product CRUD

### Task 3: Create Product

Description:

Admin/Employee tạo sản phẩm.

Fields:

```text
name
slug
description
category_id
publish_status
```

Acceptance:

* validation pass
* slug unique

Files:

```text
ProductController
ProductService
ProductRepository
```

Scope: L

---

### Task 4: Product Variant

Description:

Cho phép:

```text
Size
Color
SKU
Price
```

Acceptance:

* nhiều variant
* SKU unique

Scope: M

---

### Task 5: Inventory

Description:

Tách tồn kho.

Rules:

```text
reserved_stock
available_stock
```

Acceptance:

```text
available >= 0
```

Scope: M

---

# Phase 3 — Shop APIs

### Task 6: Product Listing

Endpoint:

```http
GET /api/products
```

Features:

* filter
* sort
* paginate

Acceptance:

* chỉ hiện published

Scope: M

---

### Task 7: Product Detail

Endpoint:

```http
GET /api/products/{slug}
```

Acceptance:

* load category
* load variants

Scope: S

---

### Task 8: Search

Features:

```text
keyword
category
price
sort
```

Acceptance:

response < 300ms

Scope: L

---

# Phase 4 — Media

### Task 9: Upload Product Image

Acceptance:

* multiple upload
* resize
* optimize

Scope: M

---

# Phase 5 — Authorization

### Task 10: RBAC

Permission:

```text
manage_product
publish_product
manage_inventory
```

Acceptance:

employee không publish.

Scope: S

---

# Phase 6 — Hardening

### Task 11: Tests + Coverage

Acceptance:

* coverage ≥ 80%
* query benchmark

Verification:

```bash
php artisan test
```

Scope: M

---

## Success Criteria

* Customer xem catalog.
* CRM CRUD sản phẩm.
* Search < 300ms.
* Inventory không âm.

---

## Open Questions

1. Variant có hỗ trợ nhiều cấp không?
2. Media lưu local hay S3?
3. Inventory reserve lúc add cart hay checkout?
4. Product review nằm phase nào?
