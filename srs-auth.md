# SRS - Authentication & Authorization

## Project: Laravel E-commerce + CRM

---

# 1. Introduction

## Purpose

Thiết kế hệ thống xác thực và phân quyền cho nền tảng thương mại điện tử bao gồm:

1. Website bán hàng (Shop)
2. Hệ thống quản trị nội bộ (CRM)

Hai hệ thống sử dụng cơ chế đăng nhập độc lập.

---

# 2. User Types

## Customer

Mục đích:

* Mua hàng
* Quản lý đơn hàng
* Quản lý tài khoản

Truy cập:

```text
shop.example.com
```

---

## Employee

Mục đích:

* Quản lý vận hành

Truy cập:

```text
crm.example.com
```

---

## Admin

Mục đích:

* Quản trị toàn bộ CRM
* Quản lý Employee

Truy cập:

```text
crm.example.com
```

---

# 3. Authentication Architecture

## Separation Principle

Shop và CRM phải độc lập:

| Component  | Shop          | CRM           |
| ---------- | ------------- | ------------- |
| Login Page | Separate      | Separate      |
| Session    | Separate      | Separate      |
| Guard      | customer      | employee      |
| Middleware | auth:customer | auth:employee |
| Redirect   | /shop         | /crm          |
| Token      | Independent   | Independent   |

Không cho phép:

* Customer login CRM
* Employee login Shop

---

# 4. Roles

## SHOP

```text
CUSTOMER
```

---

## CRM

```text
EMPLOYEE
ADMIN
```

---

# 5. Authentication Flow

## FR-01 Customer Login

Endpoint

```http
POST /api/shop/auth/login
```

Input

```json id="eqbh4w"
{
    "email":"",
    "password":""
}
```

Process

1. Validate email/password
2. Tìm user loại CUSTOMER
3. Verify password
4. Tạo customer session
5. Redirect shop

Output

```json id="3d6b5h"
{
    "access_token":"",
    "type":"customer"
}
```

Rules

* Chỉ role CUSTOMER được login
* EMPLOYEE/ADMIN → reject

Error

```json id="a7vnmc"
{
    "message":"Access denied"
}
```

---

## FR-02 CRM Login

Endpoint

```http
POST /api/crm/auth/login
```

Input

```json id="zy98m3"
{
    "email":"",
    "password":""
}
```

Process

1. Validate account
2. Chỉ kiểm tra role:

   * EMPLOYEE
   * ADMIN
3. Tạo CRM session

Output

```json id="u3xvpk"
{
    "access_token":"",
    "type":"employee"
}
```

Rules

* CUSTOMER không login CRM

---

# 6. Authorization

## Customer Permissions

| Action         | Allow |
| -------------- | ----- |
| View Product   | YES   |
| Checkout       | YES   |
| View Orders    | YES   |
| Manage Product | NO    |
| CRM Access     | NO    |

---

## Employee Permissions

| Action          | Allow |
| --------------- | ----- |
| Manage Product  | YES   |
| Manage Order    | YES   |
| Manage Customer | YES   |
| Manage Employee | NO    |

---

## Admin Permissions

| Action          | Allow |
| --------------- | ----- |
| Manage Product  | YES   |
| Manage Order    | YES   |
| Manage Customer | YES   |
| Manage Employee | YES   |
| System Config   | YES   |

---

# 7. Database Design

## users

```text
id
name
email
password
type
status
created_at
updated_at
```

type:

```text
CUSTOMER
EMPLOYEE
ADMIN
```

status:

```text
ACTIVE
INACTIVE
LOCKED
```

---

# 8. Laravel Design

## Guards

```php id="gjdohp"
'guards' => [

'customer' => [
'driver' => 'sanctum',
'provider' => 'customers'
],

'employee' => [
'driver' => 'sanctum',
'provider' => 'employees'
]

]
```

---

## Providers

```php id="aqn4x0"
'providers' => [

'customers'=>[
'driver'=>'eloquent',
'model'=>Customer::class
],

'employees'=>[
'driver'=>'eloquent',
'model'=>Employee::class
]

]
```

---

## Middleware

```php id="0g0mb6"
auth:customer
auth:employee
role:admin
role:employee
```

---

# 9. Security Requirements

* Session Shop và CRM không chia sẻ.
* CRM bắt buộc rate limit login.
* Logout toàn bộ device (CRM).
* Audit log login CRM.
* Auto logout CRM sau inactivity.

---

# 10. Future Enhancement

* SSO nội bộ CRM
* MFA cho Admin
* Device Tracking
* Login Notification
