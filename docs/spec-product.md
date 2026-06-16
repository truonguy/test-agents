# Technical Spec: Product Catalog Management (Shop + CRM)

> Nguồn: `srs-product.md` (BA). Technical spec dành cho dev, sinh qua spec-driven-development.
> Xây tiếp trên `laravel-api` (đã có feature Auth). Trạng thái: **APPROVED (Phase 1)** — quyết định chốt ở §0.

---

## 0. Quyết định đã chốt (stakeholder)

| # | Vấn đề | Quyết định |
|---|---|---|
| 1 | Price/Stock | **Gắn ở Variant** (mỗi SKU giá + inventory riêng). Product không giữ giá. Listing sort theo `min(variant.price)`. |
| 2 | Shop catalog auth | **Public** — không cần token; chỉ trả PUBLISHED. |
| 3 | Variant nesting | **1 cấp** (size/color phẳng). |
| 4 | Category | **Nested** (`parent_id` self-FK). |
| 5 | Media | **Local + `intervention/image`** (resize/optimize) qua `MediaService`; đổi S3 sau. |
| 6 | Review | **Out-of-scope** feature này. |
| 7 | Cart/checkout | **Out-of-scope** — chỉ model `reserved_stock`, trigger reserve để feature Order sau. |

Còn lại (không chặn Plan): **search dataset target & index strategy** — xem §9.

---

## 1. Objective

Quản lý danh mục sản phẩm cho 2 phân hệ đã tách guard ở feature Auth:
- **Shop** (`customer`/public) — xem catalog (list, detail, search).
- **CRM** (`employee`/`admin`) — CRUD category/product/variant/inventory/media, có RBAC.

**Success:** customer xem được catalog (chỉ PUBLISHED), CRM quản lý sản phẩm đầy đủ, search < 300ms,
inventory không âm, employee không publish được (chỉ admin/quyền publish).

---

## 2. Tech Stack (kế thừa Auth)

| Thành phần | Lựa chọn |
|---|---|
| Framework | Laravel 12, PHP 8.2+ |
| Auth/RBAC | Sanctum 2 guard + spatie/laravel-permission |
| Kiến trúc | Controller → Service → Repository(interface) → Model + FormRequest |
| DB | MySQL (`laravel` dev / `laravel_test` test) |
| Media | Storage local + `MediaService` abstraction; resize: **intervention/image** (mới — *ask first*) |
| Search | MySQL Query Builder + Criteria + index (KHÔNG Scout/Elastic) |
| Test | PHPUnit, MySQL `laravel_test` |

---

## 3. Commands
```
Migrate:  php artisan migrate
Seed:     php artisan db:seed
Test:     php artisan test
Test 1:   php artisan test --filter=Product
Lint:     ./vendor/bin/pint
```

---

## 4. Project Structure (theo pattern đã có)
```
app/Models/{Category,Product,ProductVariant,ProductMedia,Inventory}.php
app/Http/Controllers/Crm/{Category,Product,Variant,Inventory,ProductMedia}Controller.php
app/Http/Controllers/Shop/CatalogController.php
app/Http/Requests/Crm/...                         → FormRequests CRM
app/Services/Crm/{Product,Category,Inventory,...}Service.php
app/Services/Catalog/ProductSearchService.php     → Criteria-based search
app/Repositories/{Contracts,Eloquent}/...
app/Services/Media/MediaService.php               → abstraction (local now, S3 sau)
database/migrations/                              → categories, products, variants, media, inventories
tests/Feature/Product/                            → feature tests
docs/spec-product.md
```

---

## 5. Data Model (đề xuất)

> ⚠️ Vị trí Price/Stock & nesting category cần chốt — xem §9.

- **categories**: `id, name, slug(unique), parent_id(nullable, self-FK?), is_active, timestamps, deleted_at`
- **products**: `id, category_id(FK), name, slug(unique), description, publish_status(DRAFT|PUBLISHED|ARCHIVED), timestamps, deleted_at`
- **product_variants**: `id, product_id(FK), sku(unique), size, color, price(decimal), timestamps, deleted_at`
- **inventories**: `id, product_variant_id(FK unique), reserved_stock(uint), available_stock(uint), timestamps`
  - Invariant: `available_stock >= 0`, `reserved_stock >= 0`.
- **product_media**: `id, product_id(FK), path, disk, is_primary, sort_order, timestamps, deleted_at`

Soft delete (`deleted_at`) cho category/product/variant/media. RBAC permissions mới:
`manage_product` (đã có), **`publish_product`**, **`manage_inventory`**.

| Role | manage_product | publish_product | manage_inventory |
|---|---|---|---|
| employee | ✅ | ❌ | ✅ |
| admin | ✅ | ✅ | ✅ |

---

## 6. Functional Requirements & Acceptance Criteria

### FR-P1 — Migrations + Models (Task 1)
```
AC-P1.1  migrate:fresh sạch; soft delete hoạt động (deleted_at)
AC-P1.2  slug unique ở categories & products; sku unique ở variants
AC-P1.3  quan hệ: product→category, variant→product, inventory→variant(1-1), media→product
```

### FR-P2 — Category CRUD / CRM (Task 2) · `permission:manage_product`
```
AC-P2.1  GET/POST/PUT/DELETE /api/crm/categories — CRUD pass
AC-P2.2  DELETE = soft delete (không xoá cứng)
AC-P2.3  customer token → 401; employee không có manage_product → 403
AC-P2.4  slug auto-gen từ name, unique (trùng → 422)
```

### FR-P3 — Product CRUD / CRM (Task 3) · `permission:manage_product`
```
AC-P3.1  POST /api/crm/products: name, category_id, description, publish_status hợp lệ → 201
AC-P3.2  slug unique; category_id tồn tại; publish_status ∈ enum → else 422
AC-P3.3  PUT/DELETE (soft delete); GET list+detail (CRM thấy mọi status)
AC-P3.4  đổi publish_status sang PUBLISHED yêu cầu permission publish_product (xem FR-P10)
```

### FR-P4 — Product Variant (Task 4) · `permission:manage_product`
```
AC-P4.1  1 product có nhiều variant (size/color/sku/price)
AC-P4.2  sku unique toàn hệ → trùng 422
AC-P4.3  price >= 0
```

### FR-P5 — Inventory (Task 5) · `permission:manage_inventory`
```
AC-P5.1  mỗi variant có inventory (available, reserved)
AC-P5.2  available_stock >= 0 luôn đúng (cập nhật âm → 422)
AC-P5.3  employee có manage_inventory cập nhật được; thiếu quyền → 403
```

### FR-P6 — Shop Product Listing (Task 6) · public/customer
```
AC-P6.1  GET /api/products — chỉ trả PUBLISHED (ẩn DRAFT/ARCHIVED & soft-deleted)
AC-P6.2  filter (category, price range), sort (price/created), paginate
AC-P6.3  (nếu public) không cần token; (nếu customer-only) cần customer token
```

### FR-P7 — Shop Product Detail (Task 7) · public/customer
```
AC-P7.1  GET /api/products/{slug} — load category + variants (+ inventory available?)
AC-P7.2  product chưa PUBLISHED hoặc soft-deleted → 404
```

### FR-P8 — Search (Task 8)
```
AC-P8.1  keyword (name/description) + category + price + sort
AC-P8.2  chỉ PUBLISHED
AC-P8.3  có index phù hợp; benchmark < 300ms trên tập dữ liệu mẫu (kích thước cần chốt)
```

### FR-P9 — Upload Product Image (Task 9) · `permission:manage_product`
```
AC-P9.1  upload nhiều ảnh; lưu qua MediaService (disk hiện tại)
AC-P9.2  resize/optimize; 1 ảnh primary
AC-P9.3  validate mime/size; soft delete media
```

### FR-P10 — RBAC (Task 10)
```
AC-P10.1  employee KHÔNG publish (đổi sang PUBLISHED) → 403; admin publish được
AC-P10.2  manage_inventory tách: thiếu → 403 ở inventory endpoints
AC-P10.3  customer token → mọi /api/crm/* 401
```

---

## 7. Testing Strategy
- Feature tests trong `tests/Feature/Product/` (MySQL `laravel_test`, `RefreshDatabase`).
- Mỗi FR có test map AC; RBAC test cho từng permission mới.
- Search: test lọc/sort + (nếu khả thi) benchmark thời gian; coverage số cần Xdebug/PCOV (môi trường hiện thiếu — như feature Auth).

## 8. Boundaries
- **Always:** validate input; soft delete (không xoá cứng); chỉ PUBLISHED ở Shop; RBAC theo permission; Pint + test trước commit.
- **Ask first:** thêm dependency (intervention/image); đổi schema; dùng Scout/Elastic; chuyển media sang S3.
- **Never:** xoá cứng catalog; để available_stock âm; lộ sản phẩm chưa publish ở Shop; bỏ check permission.

## 9. Điểm chưa rõ — trạng thái

| # | Vấn đề | Trạng thái |
|---|---|---|
| 1 | Price/Stock ở Variant | ✅ **CHỐT: Variant**; sort listing theo `min(variant.price)` |
| 2 | Shop auth | ✅ **CHỐT: Public** |
| 3 | Variant nesting | ✅ **CHỐT: 1 cấp** |
| 4 | Media storage | ✅ **CHỐT: Local + intervention/image** |
| 5 | Reserve cart/checkout | ✅ **CHỐT: out-of-scope** (chỉ field `reserved_stock`) |
| 6 | Product review | ✅ **CHỐT: out-of-scope** |
| 7 | Category nested | ✅ **CHỐT: có `parent_id`** |
| 8 | Search dataset/index target | ⏳ **CHỜ** — cần BA cho kích thước dữ liệu mục tiêu; đề xuất index `(publish_status, category_id)`, `slug`, fulltext `name,description`. Benchmark <300ms trên tập mẫu (đề xuất 10k products). Không chặn Plan. |

## 10. Success Criteria
- [ ] AC-P1..P10 pass bằng feature test.
- [ ] Shop chỉ thấy PUBLISHED; CRM CRUD đầy đủ.
- [ ] Inventory không âm.
- [ ] employee không publish; customer không chạm CRM.
- [ ] Search có index + đo được thời gian (mục tiêu <300ms).
- [ ] 8 điểm §9 được chốt và spec cập nhật trước Implement.
