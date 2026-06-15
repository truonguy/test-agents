# Technical Spec: Authentication & Authorization (Shop + CRM)

> Nguồn: `srs-auth.md` (SRS từ BA). Technical spec dành cho dev, sinh qua spec-driven-development.
> Trạng thái: **APPROVED (Phase 1)** — 4 quyết định kiến trúc đã chốt (xem §0).

---

## 0. Quyết định kiến trúc (đã chốt với stakeholder)

| # | Vấn đề (mâu thuẫn SRS) | Quyết định |
|---|---|---|
| 1 | Single-table vs Multi-table | **Hai bảng** `customers` + `employees`, 2 Eloquent model & 2 provider Sanctum riêng. |
| 2 | `type` trả về cho ADMIN | CRM luôn trả `type:"employee"`; phân biệt admin/employee qua **role** (RBAC). |
| 3 | Role vs Type | **RBAC riêng** (`spatie/laravel-permission`): bảng roles + permissions. |
| 4 | Token vs Session | **Sanctum token (Bearer), stateless.** Redirect là việc của client. |

**Scope chốt:** Login (FR-01/02) + Authz RBAC + **Register/Reset password** + **Admin endpoints**.

---

## 1. Objective

Hệ thống xác thực & phân quyền cho Laravel E-commerce + CRM, hai phân hệ login độc lập:

- **Shop** (`shop.example.com`) — `customers` (role: customer).
- **CRM** (`crm.example.com`) — `employees` (role: employee | admin).

Token/guard Shop và CRM tách biệt hoàn toàn; cross-access bị chặn.

---

## 2. Tech Stack

| Thành phần | Lựa chọn |
|---|---|
| Framework | Laravel 11.x |
| Ngôn ngữ | PHP 8.2+ |
| Auth | Laravel Sanctum (token, 2 guard) |
| RBAC | spatie/laravel-permission (teams=false) |
| DB | MySQL 8 / PostgreSQL 15 |
| Test | Pest hoặc PHPUnit |
| Hash | bcrypt (mặc định Laravel) |

> **Ask first** trước khi thêm `spatie/laravel-permission` (đây là dependency mới).

---

## 3. Commands

```
Install:   composer install
Migrate:   php artisan migrate
Seed:      php artisan db:seed
Test:      php artisan test
Test 1:    php artisan test --filter=Auth
Lint:      ./vendor/bin/pint
Dev:       php artisan serve
```

---

## 4. Project Structure

```
app/Models/Customer.php                               → guard customer
app/Models/Employee.php                               → guard employee (gắn RBAC)
app/Http/Controllers/Shop/Auth/{Login,Register,Password}Controller.php
app/Http/Controllers/Crm/Auth/{Login,Password,LogoutController}.php
app/Http/Controllers/Crm/{Product,Order,Customer,Employee}Controller.php
app/Http/Middleware/EnsureGuard.php                   → khoá đúng phân hệ
app/Policies/                                         → policy cho admin endpoints
database/migrations/                                  → customers, employees, roles*, audit_logs
routes/api.php                                        → /api/shop/*, /api/crm/*
tests/Feature/Auth/                                   → feature tests
docs/spec-auth.md
```

---

## 5. Data Model

### Bảng `customers`
`id, name, email(unique), password, status(ACTIVE|INACTIVE|LOCKED), timestamps`

### Bảng `employees`
`id, name, email(unique), password, status(ACTIVE|INACTIVE|LOCKED), timestamps`
→ gắn `HasRoles` (spatie). Roles: `employee`, `admin`.

### RBAC (spatie)
`roles`, `permissions`, `model_has_roles`, `role_has_permissions`.
Permissions: `manage_product`, `manage_order`, `manage_customer`, `manage_employee`, `system_config`.

| Role | Permissions |
|---|---|
| employee | manage_product, manage_order, manage_customer |
| admin | tất cả + manage_employee + system_config |

### Bảng `audit_logs`
`id, guard, email, ip, user_agent, action, result(SUCCESS|FAIL), created_at`

> `personal_access_tokens` (Sanctum) hỗ trợ token theo từng model nhờ morph — 2 guard dùng chung bảng này.

---

## 6. Functional Requirements & Acceptance Criteria

### FR-01 — Customer Login · `POST /api/shop/auth/login`
Req `{email,password}` → 200 `{access_token, type:"customer"}`

```
AC-01.1  customer ACTIVE, pass đúng → 200, token hợp lệ, type="customer"
AC-01.2  email thuộc employees (sai phân hệ) → 401 thông báo chung (không lộ tồn tại)
AC-01.3  email/pass sai → 401 thông báo chung
AC-01.4  status != ACTIVE → 403 (INACTIVE/LOCKED, xem AC-07.5 cho LOCKED)
AC-01.5  thiếu field / email sai định dạng → 422 theo field
AC-01.6  token Shop gọi /api/crm/* → 401/403
```

### FR-02 — CRM Login · `POST /api/crm/auth/login`
Req `{email,password}` → 200 `{access_token, type:"employee", role:"employee"|"admin"}`

```
AC-02.1  employee ACTIVE pass đúng → 200, type="employee", role="employee"
AC-02.2  admin ACTIVE pass đúng → 200, type="employee", role="admin"
AC-02.3  email thuộc customers → 401 thông báo chung
AC-02.4  email/pass sai → 401 thông báo chung
AC-02.5  status != ACTIVE → 403
AC-02.6  vượt rate limit → 429
AC-02.7  mọi lần login CRM (success & fail) → ghi audit_logs
AC-02.8  token CRM gọi /api/shop/* → 401/403
```

### FR-03 — Register Customer · `POST /api/shop/auth/register`
Req `{name,email,password,password_confirmation}` → 201 `{access_token,type:"customer"}`

```
AC-03.1  email chưa tồn tại, password hợp lệ → tạo customer status=ACTIVE, 201
AC-03.2  email đã tồn tại → 422
AC-03.3  password < min (đề xuất 8, có chữ+số) → 422
AC-03.4  password != confirmation → 422
AC-03.5  KHÔNG cho self-register employee/admin (chỉ admin tạo employee — FR-06)
```

### FR-04 — Forgot/Reset Password (Shop & CRM)
`POST /api/{shop|crm}/auth/forgot-password` → gửi token reset qua email
`POST /api/{shop|crm}/auth/reset-password` → đặt lại mật khẩu

```
AC-04.1  email tồn tại → gửi reset link, response 200 generic (không lộ tồn tại)
AC-04.2  email không tồn tại → vẫn 200 generic (chống user enumeration)
AC-04.3  reset token hợp lệ + password mới hợp lệ → đổi pass, revoke toàn bộ token cũ
AC-04.4  reset token hết hạn/sai → 422
AC-04.5  reset của Shop không dùng được cho CRM và ngược lại
```

### FR-05 — Logout
`POST /api/{shop|crm}/auth/logout` (token hiện tại) · `POST /api/crm/auth/logout-all` (mọi device)

```
AC-05.1  logout → revoke token hiện tại, token đó sau đó trả 401
AC-05.2  logout-all (CRM) → xoá toàn bộ token user → mọi token cũ 401
```

### FR-06 — Admin/Employee endpoints (RBAC enforced)
Ví dụ: `GET/POST /api/crm/products`, `/api/crm/orders`, `/api/crm/customers`, `/api/crm/employees`

```
AC-06.1  employee gọi /api/crm/products (manage_product) → cho phép
AC-06.2  employee gọi /api/crm/employees (manage_employee) → 403
AC-06.3  admin gọi /api/crm/employees → cho phép (CRUD employee)
AC-06.4  customer token gọi bất kỳ /api/crm/* → 401/403
AC-06.5  admin tạo employee với role chỉ định → 201, employee login được CRM
```

---

## 7. Security Requirements (§9) — tham số đề xuất (cần chốt)

| Yêu cầu | Spec đề xuất |
|---|---|
| Session tách biệt | 2 guard Sanctum (`customer`/`employee`), middleware `EnsureGuard` |
| Rate limit login CRM | **5 fail / 60s / (IP+email)** → 429; áp cho cả Shop login |
| Logout all device | FR-05 logout-all |
| Audit log CRM | bảng `audit_logs`, ghi success & fail |
| Auto logout inactivity | **token TTL trượt 30 phút** (Sanctum `expiration` + last_used) |
| Password policy | **min 8 ký tự, có chữ + số** |
| LOCKED | tự động sau **10 fail liên tiếp**, admin mở khoá thủ công |

```
AC-07.1  5 fail trong 60s → 429
AC-07.2  logout-all xoá toàn bộ token → 401
AC-07.3  audit_logs có cả fail & success ở CRM
AC-07.4  token quá hạn inactivity → 401
AC-07.5  10 fail liên tiếp → status=LOCKED, login tiếp → 403 dù pass đúng
```

> ⚠️ Các số ở §7 là **đề xuất của dev**, SRS không quy định. Cần BA xác nhận trước khi code.

---

## 8. Boundaries

- **Always:** validate input; generic message cho mọi auth fail (chống enumeration); audit log CRM;
  `php artisan test` trước commit; bcrypt; kiểm tra guard ở middleware.
- **Ask first:** thêm package (spatie); đổi tham số §7; thêm endpoint ngoài FR; đổi schema.
- **Never:** commit secret/.env; message lộ "email không tồn tại"; bỏ check guard; pass plaintext;
  cho self-register employee/admin.

---

## 9. Open Questions còn lại (không chặn Plan, cần chốt trước Implement)

1. Tham số §7 (rate-limit, TTL, lockout, password policy) — BA duyệt con số.
2. Email driver thật (SMTP/Mailgun/…) cho reset password — môi trường nào?
3. CORS/cookie domain cho 2 subdomain — hạ tầng cung cấp.
4. i18n thông báo lỗi (VI/EN)?
5. Có cần email verification cho customer mới đăng ký không? (hiện đang giả định: KHÔNG bắt buộc)

---

## 10. Success Criteria

- [ ] Toàn bộ AC-01..AC-07 pass bằng feature test.
- [ ] Cross-access Shop⇄CRM đều 401/403.
- [ ] RBAC: employee không chạm manage_employee/system_config.
- [ ] Security: rate-limit, audit, logout-all, lockout, TTL verify được.
- [ ] Coverage feature test auth ≥ 80%.
- [ ] Open Questions §9 được chốt và spec cập nhật trước khi Implement.
