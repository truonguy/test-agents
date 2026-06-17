<?php

use App\Http\Controllers\Crm\Auth\LoginController as CrmLoginController;
use App\Http\Controllers\Crm\Auth\LogoutController as CrmLogoutController;
use App\Http\Controllers\Crm\Auth\PasswordController as CrmPasswordController;
use App\Http\Controllers\Crm\CategoryController;
use App\Http\Controllers\Crm\EmployeeController;
use App\Http\Controllers\Crm\ProductController;
use App\Http\Controllers\Crm\VariantController;
use App\Http\Controllers\Shop\Auth\LoginController as ShopLoginController;
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
        });
        Route::middleware('permission:manage_order')->get('/orders', fn () => ['data' => []]);
        Route::middleware('permission:manage_customer')->get('/customers', fn () => ['data' => []]);

        Route::middleware('permission:manage_employee')->group(function () {
            Route::get('/employees', [EmployeeController::class, 'index']);
            Route::post('/employees', [EmployeeController::class, 'store']);
        });
        Route::middleware('permission:system_config')->get('/system-config', fn () => ['data' => []]);
    });
});
