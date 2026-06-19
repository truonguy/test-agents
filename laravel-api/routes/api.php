<?php

use App\Http\Controllers\Crm\Auth\LoginController as CrmLoginController;
use App\Http\Controllers\Crm\Auth\LogoutController as CrmLogoutController;
use App\Http\Controllers\Crm\Auth\PasswordController as CrmPasswordController;
use App\Http\Controllers\Crm\CategoryController;
use App\Http\Controllers\Crm\EmployeeController;
use App\Http\Controllers\Crm\InventoryController;
use App\Http\Controllers\Crm\OrderManagementController;
use App\Http\Controllers\Crm\ProductController;
use App\Http\Controllers\Crm\ProductMediaController;
use App\Http\Controllers\Crm\VariantController;
use App\Http\Controllers\Shop\Auth\LoginController as ShopLoginController;
use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CatalogController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\PaymentController;
use App\Http\Controllers\Shop\Auth\LogoutController as ShopLogoutController;
use App\Http\Controllers\Shop\Auth\PasswordController as ShopPasswordController;
use App\Http\Controllers\Shop\Auth\RegisterController as ShopRegisterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes (prefix /api)
|--------------------------------------------------------------------------
| Shop (guard: customer) và CRM (guard: employee) tách biệt hoàn toàn.
| Các route auth (login/register/...) được thêm dần ở các task sau.
*/

// Public catalog (Shop) — không cần token, chỉ PUBLISHED
Route::get('/products', [CatalogController::class, 'index']);
Route::get('/products/{slug}', [CatalogController::class, 'show']);

// Cart / Order (customer) — yêu cầu customer token
Route::middleware('ensure_guard:customer')->group(function () {
    Route::get('/cart', [CartController::class, 'show']);
    Route::post('/cart/items', [CartController::class, 'store']);
    Route::put('/cart/items/{item}', [CartController::class, 'update']);
    Route::delete('/cart/items/{item}', [CartController::class, 'destroy']);

    Route::post('/checkout', [CheckoutController::class, 'store']);

    Route::get('/orders', [OrderController::class, 'index']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::post('/orders/{order}/payment', [PaymentController::class, 'store']);
});

// Shop — phân hệ customer
Route::prefix('shop')->group(function () {
    Route::post('/auth/register', ShopRegisterController::class);
    Route::post('/auth/login', ShopLoginController::class);
    Route::post('/auth/forgot-password', [ShopPasswordController::class, 'forgot']);
    Route::post('/auth/reset-password', [ShopPasswordController::class, 'reset']);

    Route::middleware('ensure_guard:customer')->group(function () {
        Route::post('/auth/logout', ShopLogoutController::class);

        Route::get('/ping', function (Request $request) {
            return ['type' => 'customer', 'id' => $request->user()->id];
        });
    });
});

// CRM — phân hệ employee
Route::prefix('crm')->group(function () {
    Route::post('/auth/login', CrmLoginController::class);
    Route::post('/auth/forgot-password', [CrmPasswordController::class, 'forgot']);
    Route::post('/auth/reset-password', [CrmPasswordController::class, 'reset']);

    Route::middleware('ensure_guard:employee')->group(function () {
        Route::post('/auth/logout', [CrmLogoutController::class, 'logout']);
        Route::post('/auth/logout-all', [CrmLogoutController::class, 'logoutAll']);

        Route::get('/ping', function (Request $request) {
            return ['type' => 'employee', 'id' => $request->user()->id];
        });

        // Endpoint nghiệp vụ CRM — bảo vệ theo permission (RBAC).
        Route::middleware('permission:manage_product')->group(function () {
            Route::get('/categories', [CategoryController::class, 'index']);
            Route::post('/categories', [CategoryController::class, 'store']);
            Route::put('/categories/{category}', [CategoryController::class, 'update']);
            Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

            Route::get('/products', [ProductController::class, 'index']);
            Route::get('/products/{product}', [ProductController::class, 'show']);
            Route::post('/products', [ProductController::class, 'store']);
            Route::put('/products/{product}', [ProductController::class, 'update']);
            Route::delete('/products/{product}', [ProductController::class, 'destroy']);

            Route::get('/products/{product}/variants', [VariantController::class, 'index']);
            Route::post('/products/{product}/variants', [VariantController::class, 'store']);
            Route::put('/variants/{variant}', [VariantController::class, 'update']);
            Route::delete('/variants/{variant}', [VariantController::class, 'destroy']);

            Route::get('/products/{product}/media', [ProductMediaController::class, 'index']);
            Route::post('/products/{product}/media', [ProductMediaController::class, 'store']);
            Route::put('/media/{media}/primary', [ProductMediaController::class, 'setPrimary']);
            Route::delete('/media/{media}', [ProductMediaController::class, 'destroy']);
        });

        // Inventory — quyền riêng manage_inventory
        Route::middleware('permission:manage_inventory')
            ->put('/variants/{variant}/inventory', [InventoryController::class, 'update']);

        // Publish gate — quyền riêng publish_product (employee thường KHÔNG có)
        Route::middleware('permission:publish_product')->group(function () {
            Route::post('/products/{product}/publish', [ProductController::class, 'publish']);
            Route::post('/products/{product}/unpublish', [ProductController::class, 'unpublish']);
        });
        Route::middleware('permission:manage_order')->group(function () {
            Route::get('/orders', [OrderManagementController::class, 'index']);
            Route::get('/orders/{order}', [OrderManagementController::class, 'show']);
            Route::post('/orders/{order}/{action}', [OrderManagementController::class, 'apply'])
                ->whereIn('action', ['confirm', 'pack', 'ship', 'complete', 'cancel']);
        });

        Route::middleware('permission:manage_customer')->get('/customers', fn () => ['data' => []]);

        Route::middleware('permission:manage_employee')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::post('/employees', [EmployeeController::class, 'store']);
        });
        Route::middleware('permission:system_config')->get('/system-config', fn () => ['data' => []]);
    });
});
