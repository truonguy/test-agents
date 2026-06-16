# Implementation Plan: Authentication & Authorization (Shop + CRM)

> Nguồn spec: `docs/spec-auth.md`. Laravel 11 + Sanctum (2 guard) + spatie RBAC.
> Trạng thái: **DRAFT — chờ review trước khi Implement.**

## Overview

Xây auth 2 phân hệ độc lập (Shop/CRM) trên Laravel Sanctum với 2 guard + 2 model, RBAC bằng
spatie, kèm register/reset/logout và các endpoint quản trị có phân quyền. Triển khai theo
**vertical slice**: mỗi task để hệ thống ở trạng thái chạy được và test được.

## Architecture Decisions (từ spec §0)

- **2 bảng** `customers` + `employees`, 2 guard Sanctum `customer`/`employee` — cách ly tuyệt đối.
- **Sanctum token (Bearer), stateless** — không session cookie.
- **RBAC** qua `spatie/laravel-permission` gắn vào model `Employee` (roles: employee, admin).
- **Generic auth error** chống user-enumeration ở mọi nhánh login/reset.

## Dependency Graph

```
Setup (Sanctum + spatie + config 2 guard)
   │
   ├── Migrations: customers, employees, audit_logs, spatie tables
   │       │
   │       ├── Models + RBAC seed (roles/permissions)
   │       │       │
   │       │       ├── [Slice A] Customer Login (FR-01)
   │       │       ├── [Slice B] CRM Login + audit + rate-limit (FR-02, §7)
   │       │       ├── [Slice C] Register Customer (FR-03)
   │       │       ├── [Slice D] Forgot/Reset Password (FR-04)
   │       │       ├── [Slice E] Logout / Logout-all (FR-05)
   │       │       └── [Slice F] Admin endpoints + RBAC enforce (FR-06)
   │       │
   │       └── EnsureGuard middleware (chặn cross-access — dùng chung cho mọi slice)
   │
   └── Lockout / TTL / password policy (§7 — cross-cutting, làm cùng login)
```

Thứ tự: **Foundation (T1–T3) → Login slices (T4–T6) → Account flows (T7–T9) → Authz (T10–T11) → Hardening (T12)**.

---

## Task List

### Phase 1 — Foundation

#### Task 1: Cài đặt & cấu hình Sanctum + spatie + 2 guard ✅ DONE
**Description:** Cài package, publish config, khai báo guard `customer`/`employee` với 2 provider, đăng ký spatie.
**Acceptance criteria:**
- [x] `config/auth.php` có 2 guard `customer`,`employee` + 2 provider trỏ `Customer`/`Employee`.
- [x] Sanctum (^4.3) + `spatie/laravel-permission` (^6.25) cài đặt, config publish.
- [x] `php artisan config:cache` không lỗi.
**Verification:** `composer install && php artisan about` chạy sạch; `php artisan test` (chưa có test mới) vẫn pass.
**Dependencies:** None
**Files:** `composer.json`, `config/auth.php`, `config/sanctum.php`, `config/permission.php`
**Scope:** S
> ⚠️ *Ask first* — thêm dependency `spatie/laravel-permission` (theo boundary spec).

#### Task 2: Migrations + Models ✅ DONE
**Description:** Tạo migration `customers`, `employees`, `audit_logs` + chạy migration spatie; tạo model `Customer`, `Employee` (HasApiTokens; Employee + HasRoles), enum status.
**Acceptance criteria:**
- [x] Bảng customers/employees có cột theo spec §5 (email unique, status enum).
- [x] `Employee` dùng trait `HasRoles`, `HasApiTokens`; `Customer` dùng `HasApiTokens`.
- [x] `audit_logs` đúng cột (guard, email, ip, user_agent, action, result).
> Env note: PHP CLI thiếu `pdo_sqlite` → test chạy trên MySQL DB riêng `laravel_test` (phpunit.xml). Thêm `App\Enums\UserStatus` + factories Customer/Employee.
**Verification:** `php artisan migrate:fresh` thành công; `php artisan test --filter=Migration` (smoke) pass.
**Dependencies:** Task 1
**Files:** `database/migrations/*`, `app/Models/Customer.php`, `app/Models/Employee.php`
**Scope:** M

#### Task 3: Seed roles/permissions + EnsureGuard middleware ✅ DONE
**Description:** Seeder tạo permissions (manage_product/order/customer/employee, system_config) + role employee/admin với mapping spec §5; middleware `EnsureGuard` chặn token sai phân hệ.
**Acceptance criteria:**
- [x] Seeder gán đúng permission cho `employee` (3) và `admin` (5).
- [x] `EnsureGuard:customer` từ chối token employee và ngược lại (→ 401).
> Cross-access đã có sẵn ở Sanctum (`hasValidProvider`); EnsureGuard thêm `Auth::shouldUse`. Đã bật API routing (`bootstrap/app.php`) + alias `ensure_guard` + `routes/api.php` (ping routes tạm).
**Verification:** `php artisan db:seed`; feature test `EnsureGuardTest` pass.
**Dependencies:** Task 2
**Files:** `database/seeders/RolePermissionSeeder.php`, `app/Http/Middleware/EnsureGuard.php`, `bootstrap/app.php`(alias)
**Scope:** S

### ✅ Checkpoint: Foundation (sau T1–T3) — ĐẠT
- [x] `migrate:fresh --seed` sạch (2 role, 5 permission). `php artisan test` xanh (18 passed).

---

### Phase 2 — Login slices

#### Task 4: FR-01 Customer Login (+ generic error, password policy nền) ✅ DONE
**Description:** `POST /api/shop/auth/login` qua guard customer, trả `{access_token,type:"customer"}`, generic error, chặn status != ACTIVE.
**Acceptance criteria (map AC-01.*):**
- [x] AC-01.1, AC-01.3, AC-01.4, AC-01.5, AC-01.6 pass.
- [x] AC-01.2: email thuộc employees → 401 generic (không lộ tồn tại).
> 8 tests. Sai pass/email không tồn tại/email employee → 401 generic; INACTIVE/LOCKED → 403; validation 422.
> Kiến trúc phân lớp: `LoginController` → `Services\Shop\CustomerAuthService` → `Repositories\Contracts\CustomerRepositoryInterface` (bind → `Eloquent\CustomerRepository`) → `Customer`. FormRequest `Shop\Auth\LoginRequest`. Exception domain `AccountNotActiveException` (403). **Các task sau theo cùng pattern.**
**Verification:** `php artisan test --filter=ShopLoginTest`.
**Dependencies:** Task 3
**Files:** `app/Http/Controllers/Shop/Auth/LoginController.php`, `routes/api.php`, `tests/Feature/Auth/ShopLoginTest.php`
**Scope:** M

#### Task 5: FR-02 CRM Login (+ audit log + role trong response) ✅ DONE
**Description:** `POST /api/crm/auth/login` qua guard employee, trả `{access_token,type:"employee",role}`, ghi audit_logs cho mọi lần (success/fail).
**Acceptance criteria (map AC-02.*):**
- [x] AC-02.1, AC-02.2 (admin → role="admin"), AC-02.3, AC-02.4, AC-02.5, AC-02.8 pass.
- [x] AC-02.7: audit_logs có bản ghi cho cả fail và success.
> 7 tests. Pattern: Controller → `Crm\EmployeeAuthService` → `EmployeeRepository` + `AuditLogger`/`AuditLog`. role = `getRoleNames()->first()`.
**Verification:** `php artisan test --filter=CrmLoginTest`.
**Dependencies:** Task 3
**Files:** `app/Http/Controllers/Crm/Auth/LoginController.php`, `app/Services/AuditLogger.php`, `routes/api.php`, `tests/Feature/Auth/CrmLoginTest.php`
**Scope:** M

#### Task 6: §7 Rate-limit + Lockout + TTL (cross-cutting cho login) ✅ DONE
**Description:** Áp rate-limit (5 fail/60s/IP+email) → 429; lockout sau 10 fail liên tiếp → status=LOCKED; token TTL trượt 30'.
**Acceptance criteria (map AC-07.*):**
- [x] AC-07.1 (429), AC-07.4 (TTL → 401), AC-07.5 (10 fail → LOCKED, 403 dù pass đúng).
> Tham số trong `config/auth_security.php` + `sanctum.inactivity_timeout` (override qua env) — ⚠️ **giá trị đề xuất, CẦN BA CHỐT trước merge** (Open Question §9.1). `LoginThrottle` (RateLimiter, đếm fail) + `AccountLockout` (cột `failed_login_attempts`) áp cho cả Shop & CRM. TTL trượt = Sanctum `authenticateAccessTokensUsing` dựa `last_used_at`.
**Verification:** `php artisan test --filter=AuthHardeningTest`.
**Dependencies:** Task 4, Task 5
**Files:** `app/Http/Middleware/LoginRateLimit.php`, `config/sanctum.php`(expiration), `app/Services/LockoutService.php`, `tests/Feature/Auth/AuthHardeningTest.php`
**Scope:** M
> Tham số là *đề xuất* — chốt với BA (Open Question §9) trước khi merge.

### ✅ Checkpoint: Login (sau T4–T6) — ĐẠT
- [x] Cả 2 phân hệ login được, cross-access bị chặn, rate-limit/lockout/TTL hoạt động. Full suite 37 passed.
> ⚠️ Chờ BA chốt tham số §7 trước khi merge (Open Question §9.1).

---

### Phase 3 — Account flows

#### Task 7: FR-03 Register Customer ✅ DONE
**Description:** `POST /api/shop/auth/register`, tạo customer ACTIVE, trả token; cấm tự đăng ký employee/admin.
**Acceptance criteria:** AC-03.1–AC-03.5 pass.
> 7 tests. `RegisterRequest` (unique:customers, Password::min(8)->letters->numbers, confirmed). `CustomerAuthService::register` + repo `create`. Email verification = KHÔNG (spec §9.4). 201 + token.
**Verification:** `php artisan test --filter=RegisterTest`.
**Dependencies:** Task 4
**Files:** `app/Http/Controllers/Shop/Auth/RegisterController.php`, `app/Http/Requests/RegisterRequest.php`, `routes/api.php`, `tests/Feature/Auth/RegisterTest.php`
**Scope:** M

#### Task 8: FR-04 Forgot/Reset Password (Shop & CRM) ✅ DONE
**Description:** forgot-password (gửi token, response generic) + reset-password (đổi pass, revoke token cũ), tách biệt 2 phân hệ.
**Acceptance criteria:** AC-04.1–AC-04.5 pass.
> 7 tests. Password broker riêng (`customers`/`employees`) + bảng reset token riêng → isolation. `PasswordResetService` (broker param) revoke `tokens()->delete()` sau reset. Forgot luôn 200 generic. Test dùng `Notification::fake()`. Email driver thật chốt sau (Open Question §9.2).
**Verification:** `php artisan test --filter=PasswordResetTest` (dùng `Mail::fake()`).
**Dependencies:** Task 4, Task 5
**Files:** `app/Http/Controllers/{Shop,Crm}/Auth/PasswordController.php`, `routes/api.php`, `tests/Feature/Auth/PasswordResetTest.php`
**Scope:** M
> Open Question §9: email driver thật — test dùng `Mail::fake()`, cấu hình SMTP chốt sau.

#### Task 9: FR-05 Logout / Logout-all ✅ DONE
**Description:** logout (revoke token hiện tại) cho cả 2; logout-all (CRM) revoke toàn bộ token user.
**Acceptance criteria:** AC-05.1, AC-05.2 pass.
> 4 tests. logout = `currentAccessToken()->delete()`; logout-all (CRM) = `tokens()->delete()`. Routes bảo vệ bằng `ensure_guard`.
**Verification:** `php artisan test --filter=LogoutTest`.
**Dependencies:** Task 4, Task 5
**Files:** `app/Http/Controllers/Crm/Auth/LogoutController.php`, `app/Http/Controllers/Shop/Auth/LogoutController.php`, `routes/api.php`, `tests/Feature/Auth/LogoutTest.php`
**Scope:** S

### ✅ Checkpoint: Account flows (sau T7–T9) — ĐẠT
- [x] Register/reset/logout end-to-end pass; reset revoke token cũ. Full suite 55 passed.

---

### Phase 4 — Authorization

#### Task 10: RBAC enforcement + policies cho CRM endpoints ✅ DONE
**Description:** Middleware `permission:` (spatie) + policies; bảo vệ nhóm route CRM theo permission.
**Acceptance criteria:** AC-06.1–AC-06.4 pass (employee không chạm manage_employee/system_config; customer token → 401/403).
> 6 tests. Đăng ký spatie middleware alias (`permission`/`role`/`role_or_permission`). Endpoint skeleton products/orders/customers/employees/system-config guard theo permission, sau `ensure_guard:employee`. Customer token → 401 (ensure_guard); employee thiếu quyền → 403 (spatie). CRUD thật ở T11.
**Verification:** `php artisan test --filter=RbacTest`.
**Dependencies:** Task 5
**Files:** `routes/api.php`(middleware groups), `app/Policies/*`, `tests/Feature/Auth/RbacTest.php`
**Scope:** M

#### Task 11: FR-06 Admin quản lý Employee (CRUD tối thiểu) ✅ DONE
**Description:** `/api/crm/employees` CRUD do admin, gán role khi tạo; employee mới login được CRM.
**Acceptance criteria:** AC-06.5 pass; employee thường gọi → 403.
> 6 tests. `EmployeeController` (index/store) → `EmployeeManagementService` → `EmployeeRepository`. `StoreEmployeeRequest` (unique:employees, role in employee|admin). Guard `permission:manage_employee`. Lưu ý: set `status` tường minh khi create (DB default chưa nạp vào model in-memory).
**Verification:** `php artisan test --filter=EmployeeAdminTest`.
**Dependencies:** Task 10
**Files:** `app/Http/Controllers/Crm/EmployeeController.php`, `app/Http/Requests/EmployeeRequest.php`, `routes/api.php`, `tests/Feature/Auth/EmployeeAdminTest.php`
**Scope:** M
> Các endpoint Product/Order/Customer khác: chỉ skeleton + RBAC guard (CRUD nghiệp vụ ngoài scope auth).

### ✅ Checkpoint: Authz (sau T10–T11) — ĐẠT
- [x] Ma trận quyền spec §6 đúng; admin tạo employee chạy được, employee mới login OK. Full suite 67 passed.

---

### Phase 5 — Hardening & wrap-up

#### Task 12: Hoàn thiện bảo mật + coverage + tài liệu
**Description:** Rà cookie/CORS/headers, kiểm generic message toàn cục, nâng coverage ≥80%, cập nhật spec với tham số đã chốt.
**Acceptance criteria:**
- [ ] Coverage feature test auth ≥ 80%.
- [ ] Không endpoint nào lộ user-enumeration.
- [ ] `docs/spec-auth.md` §9 Open Questions đã cập nhật kết luận.
**Verification:** `php artisan test --coverage`; `./vendor/bin/pint --test`.
**Dependencies:** Task 4–11
**Files:** `config/cors.php`, `tests/*`, `docs/spec-auth.md`
**Scope:** M

### ✅ Checkpoint: Complete
- [ ] Toàn bộ AC-01..AC-07 pass; Success Criteria spec §10 đạt; sẵn sàng review/merge.

---

## Risks and Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| 2 guard cấu hình sai → cross-access lọt | High | Test cross-access (AC-01.6/02.8) ngay từ T3 EnsureGuard |
| Sanctum TTL không "trượt" tự nhiên | Med | Dùng `expiration` + last_used; nếu cần, custom check trong middleware |
| spatie gắn nhầm vào Customer | Med | Chỉ Employee dùng HasRoles; test RBAC riêng |
| Tham số §7 chưa BA duyệt → rework | Med | Đưa vào config, không hard-code; chốt trước T6 merge |
| Email driver môi trường chưa sẵn | Low | `Mail::fake()` trong test; cấu hình thật ở deploy |

## Parallelization

- **Tuần tự (bắt buộc):** T1→T2→T3 (foundation), rồi T4/T5 trước các slice phụ thuộc.
- **Song song được sau T3:** T4 (Shop login) ⟂ T5 (CRM login) — độc lập controller/test.
- **Song song được sau T4/T5:** T7 (register), T8 (reset), T9 (logout) khá độc lập.
- **Cần T5 trước:** T10 (RBAC), rồi T11.

## Open Questions (chặn merge, không chặn bắt đầu code)
1. BA duyệt tham số §7 (rate-limit, TTL, lockout, password policy) — chốt trước T6.
2. Email driver thật cho reset — chốt trước deploy T8.
3. CORS/cookie domain 2 subdomain — hạ tầng, trước T12.
4. Email verification cho customer mới — hiện giả định KHÔNG; xác nhận trước T7.
