# Implementation Plan: Product Catalog Management (Shop + CRM)

> Nguồn spec: `docs/spec-product.md`. Xây tiếp trên `laravel-api` (kế thừa Auth: Sanctum 2 guard,
> spatie RBAC, Controller→Service→Repository, MySQL `laravel_test`). Trạng thái: **DRAFT — chờ review.**

## Overview

Catalog 2 phân hệ: CRM quản lý (category/product/variant/inventory/media, RBAC) + Shop xem (public,
chỉ PUBLISHED, list/detail/search). Price+stock ở variant; category nested; media local + resize.
Vertical slice — mỗi task để hệ thống chạy & test được.

## Architecture Decisions (từ spec §0)
- Price + Inventory **ở variant**; listing sort theo `min(variant.price)`.
- Category **nested** (`parent_id`); Variant **1 cấp**.
- Shop catalog **public**; chỉ PUBLISHED + ẩn soft-deleted.
- Media **local + intervention/image** qua `MediaService`.
- Permissions mới: **`publish_product`**, **`manage_inventory`** (employee KHÔNG publish).
- Review & cart/checkout **out-of-scope** (chỉ field `reserved_stock`).

## Dependency Graph
```
RBAC perms (publish_product, manage_inventory)        Migrations+Models (category,product,variant,inventory,media)
        │                                                      │
        └──────────────┬───────────────────────────────────────┘
                       │
   ┌─── Category CRUD (CRM) ─── Product CRUD (CRM) ─┬─ Variant CRUD ─ Inventory ─┐
   │                                                │                            │
   │                                          Publish gate (publish_product)     │
   │                                                                             │
   └────────────────────────── Shop Catalog (list/detail) ── Search ── Media ────┘
```
Thứ tự: **Foundation (T1–T2) → CRM CRUD (T3–T6) → Shop (T7–T9) → Media (T10) → Hardening (T11)**.

---

## Task List

### Phase 1 — Foundation

#### Task 1: Dependency + Migrations + Models + soft delete ✅ DONE
**Description:** Cài `intervention/image`; migration `categories`(parent_id, slug), `products`, `product_variants`, `inventories`, `product_media`; model + SoftDeletes + quan hệ; enum `PublishStatus`.
**Acceptance:**
- [x] `migrate:fresh` sạch; SoftDeletes hoạt động.
- [x] slug unique (categories, products); sku unique (variants); `inventories.product_variant_id` unique (1-1).
- [x] Quan hệ: product→category, variant→product, inventory→variant, media→product; category self-FK `parent_id`.
> 6 tests. Cài `intervention/image-laravel ^1.5`. 5 model + 5 factory + `PublishStatus` enum. Index `(publish_status,category_id)`, `(product_id,price)`. Inventory không soft-delete (theo spec). Unique slug DB hard — validate ignore soft-deleted ở T3/T4.
**Verify:** `php artisan test --filter=ProductSchema`.
**Dependencies:** None
**Files:** `composer.json`, `database/migrations/*` (×5), `app/Models/{Category,Product,ProductVariant,Inventory,ProductMedia}.php`, `app/Enums/PublishStatus.php`
**Scope:** L → *thực thi theo 2 lần verify (schema rồi models)*; nếu quá lớn tách T1a (migrations) / T1b (models).
> ⚠️ *Ask first* — dependency `intervention/image`.

#### Task 2: RBAC permissions mới + seeder ✅ DONE
**Description:** Thêm `publish_product`, `manage_inventory` vào `RolePermissionSeeder`; admin có hết, employee có `manage_product`+`manage_inventory` (không publish).
**Acceptance:**
- [x] Seeder tạo 2 permission mới; employee KHÔNG có `publish_product`.
- [x] Test RBAC seed mở rộng pass; không vỡ test Auth cũ.
**Verify:** `php artisan test --filter=RolePermissionSeeder`.
**Dependencies:** None (độc lập T1)
**Files:** `database/seeders/RolePermissionSeeder.php`, `tests/Feature/Auth/RolePermissionSeederTest.php`
**Scope:** S

### ✅ Checkpoint: Foundation (T1–T2) — ĐẠT
- [x] `migrate:fresh --seed` sạch; full suite 76 passed (gồm test Auth cũ).

---

### Phase 2 — CRM CRUD

#### Task 3: Category CRUD (CRM) · `permission:manage_product` ✅ DONE
**Description:** `GET/POST/PUT/DELETE /api/crm/categories`; nested parent_id; slug auto-gen; soft delete.
**Acceptance (FR-P2):** AC-P2.1–P2.4 pass (CRUD, soft delete, RBAC 401/403, slug unique 422).
> 9 tests. Layered Controller→Service→Repository. slug auto-gen (`prepareForValidation`). **Quyết định:** hard unique slug + validation tính cả bản trashed (KHÔNG reuse slug sau soft-delete — không yêu cầu trong spec; tránh 500). customer→401, employee thiếu manage_product→403.
**Verify:** `php artisan test --filter=CategoryCrudTest`.
**Dependencies:** T1, T2
**Files:** `Crm/CategoryController`, `Http/Requests/Crm/{Store,Update}CategoryRequest`, `Services/Crm/CategoryService`, `Repositories/{Contracts,Eloquent}/CategoryRepository*`, route, test
**Scope:** M

#### Task 4: Product CRUD (CRM) · `permission:manage_product` ✅ DONE
**Description:** CRUD product (name/slug/description/category_id/publish_status); CRM thấy mọi status; soft delete. Đổi sang PUBLISHED tách sang T6 (publish gate).
**Acceptance (FR-P3):** AC-P3.1–P3.3 pass; slug unique, category tồn tại, publish_status ∈ enum.
> 11 tests. Layered. CRUD chỉ nhận DRAFT/ARCHIVED (PUBLISHED qua action gated ở T6 → set PUBLISHED qua CRUD = 422). CRM index thấy mọi status. slug auto-gen hard-unique. Service set default DRAFT (DB default chưa nạp in-memory).
**Verify:** `php artisan test --filter=ProductCrudTest`.
**Dependencies:** T3
**Files:** `Crm/ProductController`, `Http/Requests/Crm/{Store,Update}ProductRequest`, `Services/Crm/ProductService`, `Repositories/.../ProductRepository*`, route, test
**Scope:** L → nếu cần tách T4a (create/update) / T4b (list/detail/delete).

#### Task 5: Product Variant CRUD · `permission:manage_product` ✅ DONE
**Description:** Variant (size/color/sku/price) thuộc product; nhiều variant; sku unique; price>=0.
**Acceptance (FR-P4):** AC-P4.1–P4.3 pass.
> 10 tests. Nested routes `products/{product}/variants` (index/store) + `variants/{variant}` (update/delete). sku hard-unique, price `numeric|min:0`, soft delete.
**Verify:** `php artisan test --filter=VariantCrudTest`.
**Dependencies:** T4
**Files:** `Crm/VariantController`, `Http/Requests/Crm/StoreVariantRequest`, `Services/Crm/VariantService`, `Repositories/.../VariantRepository*`, route, test
**Scope:** M

#### Task 6: Inventory + Publish gate ✅ DONE
**Description:** (a) Inventory per-variant (`manage_inventory`): cập nhật available/reserved, invariant available>=0. (b) Publish gate: đổi publish_status→PUBLISHED yêu cầu `publish_product`.
**Acceptance (FR-P5, FR-P10 phần publish):** AC-P5.1–P5.3, AC-P10.1 (employee không publish→403), AC-P10.2.
> 10 tests. Inventory `PUT /variants/{variant}/inventory` (`manage_inventory`, upsert, min:0). Publish/unpublish `POST /products/{product}/(un)publish` (`publish_product`). employee không publish→403; customer→401.
**Verify:** `php artisan test --filter="InventoryTest|PublishGateTest"`.
**Dependencies:** T5
**Files:** `Crm/InventoryController`, `Http/Requests/Crm/UpdateInventoryRequest`, `Services/Crm/InventoryService`, `Policies/ProductPolicy`(publish) hoặc middleware `permission:publish_product`, route, tests
**Scope:** M

### ✅ Checkpoint: CRM CRUD (T3–T6) — ĐẠT
- [x] CRM CRUD đầy đủ; RBAC (manage_product/manage_inventory/publish_product) đúng; inventory không âm. Full suite 116 passed.

---

### Phase 3 — Shop APIs (public)

#### Task 7: Shop Product Listing · public ✅ DONE
**Description:** `GET /api/products` — chỉ PUBLISHED; filter (category, price range), sort (price/created), paginate. Giá = min(variant.price).
**Acceptance (FR-P6):** AC-P6.1–P6.2; ẩn DRAFT/ARCHIVED/soft-deleted; không cần token.
> 6 tests. `ProductSearchService` (`withMin('variants','price')`, filter category/price, sort price_asc/desc, paginate). Route public `GET /api/products` ngoài ensure_guard.
> **Tái sử dụng:** thêm `Services\Support\PaginationService` (chuẩn hoá per_page, clamp ≤100, envelope `{data, meta}`) — dùng cho listing + search (T9) + có thể CRM lists. Response listing/search dùng envelope `meta.total/per_page/...`. (+2 tests `PaginationServiceTest`)
**Verify:** `php artisan test --filter=ShopListingTest`.
**Dependencies:** T5 (cần variant price)
**Files:** `Shop/CatalogController`(index), `Services/Catalog/ProductSearchService`(criteria), route (ngoài ensure_guard), test
**Scope:** M

#### Task 8: Shop Product Detail · public
**Description:** `GET /api/products/{slug}` — load category + variants (+ available stock); chưa publish/soft-deleted → 404.
**Acceptance (FR-P7):** AC-P7.1–P7.2.
**Verify:** `php artisan test --filter=ShopDetailTest`.
**Dependencies:** T7
**Files:** `Shop/CatalogController`(show), route, test
**Scope:** S

#### Task 9: Search · public
**Description:** keyword (name/description) + category + price + sort, chỉ PUBLISHED; index phù hợp; benchmark.
**Acceptance (FR-P8):** AC-P8.1–P8.2; AC-P8.3 có index + đo thời gian (mục tiêu <300ms, dataset chốt sau — OQ §9.8).
**Verify:** `php artisan test --filter=ProductSearchTest`.
**Dependencies:** T7
**Files:** `Services/Catalog/ProductSearchService`(criteria mở rộng), migration thêm index/fulltext, `Shop/CatalogController`(search hoặc gộp index), test
**Scope:** L → tách T9a (filter/sort) / T9b (keyword+index+benchmark) nếu cần.
> ⏳ OQ §9.8: dataset target & index strategy — dùng đề xuất, BA chốt trước merge.

### ✅ Checkpoint: Shop (T7–T9)
- [ ] Shop public xem được catalog PUBLISHED, filter/sort/search hoạt động, ẩn unpublished. Review.

---

### Phase 4 — Media

#### Task 10: Upload Product Image · `permission:manage_product`
**Description:** `MediaService` (disk local) upload nhiều ảnh, resize/optimize (intervention/image), 1 primary; validate mime/size; soft delete media.
**Acceptance (FR-P9):** AC-P9.1–P9.3.
**Verify:** `php artisan test --filter=ProductMediaTest` (dùng `Storage::fake()`).
**Dependencies:** T4
**Files:** `Services/Media/MediaService`, `Crm/ProductMediaController`, `Http/Requests/Crm/UploadMediaRequest`, route, test
**Scope:** M

### ✅ Checkpoint: Media (T10)
- [ ] Upload + resize + primary + soft delete pass. Review.

---

### Phase 5 — Hardening

#### Task 11: Tests + benchmark + cập nhật spec
**Description:** Rà coverage (định tính nếu thiếu driver), benchmark search, generic errors, Pint; cập nhật spec OQ §9.8.
**Acceptance (FR-P11):**
- [ ] Mọi AC-P1..P10 pass; full suite (gồm Auth) xanh.
- [ ] Search benchmark đo được (báo cáo thời gian); index có mặt.
- [ ] `docs/spec-product.md` cập nhật kết luận; Pint clean.
**Verify:** `php artisan test`; `./vendor/bin/pint --test`.
**Dependencies:** T1–T10
**Files:** `tests/*`, `docs/spec-product.md`
**Scope:** M

### ✅ Checkpoint: Complete
- [ ] Success Criteria spec §10 đạt; sẵn sàng review/merge.

---

## Risks and Mitigations
| Risk | Impact | Mitigation |
|---|---|---|
| Search <300ms khó đạt khi data lớn (không full-text engine) | High | Fulltext index MySQL + index `(publish_status,category_id)`; benchmark T9; nếu fail → cân nhắc Scout (ask first) |
| `min(variant.price)` cho listing/sort tốn query | Med | Subquery/join + index price; cân nhắc cột `min_price` denormalized nếu cần |
| intervention/image bản v3 API khác v2 | Med | Khoá version trong composer; wrap trong MediaService để cô lập |
| Soft delete làm slug "unique" va chạm với bản đã xoá | Med | Unique theo `(slug, deleted_at)` hoặc validate `whereNull(deleted_at)` |
| Publish gate lọt nếu chỉ check ở create | Med | Test riêng PublishGate cho cả create & update sang PUBLISHED |

## Parallelization
- **Song song được:** T2 (RBAC seed) ⟂ T1 (schema).
- **Tuần tự:** T1→T3→T4→T5→T6; T7 cần T5; T8/T9 sau T7; T10 cần T4.
- **Sau T5:** T7 (shop listing) có thể song song với T6 (inventory/publish).

## Open Questions (không chặn bắt đầu code)
1. (§9.8) Search dataset target + index strategy — chốt trước T9 merge.
2. Listing có hiện tồn kho (available) không, hay chỉ ở detail? (đề xuất: detail).
3. Ảnh resize ở kích thước nào (thumbnail/medium)? — chốt trước T10.
