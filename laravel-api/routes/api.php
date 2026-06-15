<?php

use App\Http\Controllers\Crm\Auth\LoginController as CrmLoginController;
use App\Http\Controllers\Crm\Auth\PasswordController as CrmPasswordController;
use App\Http\Controllers\Shop\Auth\LoginController as ShopLoginController;
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

    Route::middleware('ensure_guard:customer')->get('/ping', function (Request $request) {
        return ['type' => 'customer', 'id' => $request->user()->id];
    });
});

// CRM — phân hệ employee
Route::prefix('crm')->group(function () {
    Route::post('/auth/login', CrmLoginController::class);
    Route::post('/auth/forgot-password', [CrmPasswordController::class, 'forgot']);
    Route::post('/auth/reset-password', [CrmPasswordController::class, 'reset']);

    Route::middleware('ensure_guard:employee')->get('/ping', function (Request $request) {
        return ['type' => 'employee', 'id' => $request->user()->id];
    });
});
