<?php

use App\Http\Controllers\Shop\Auth\LoginController as ShopLoginController;
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
    Route::post('/auth/login', ShopLoginController::class);

    Route::middleware('ensure_guard:customer')->get('/ping', function (Request $request) {
        return ['type' => 'customer', 'id' => $request->user()->id];
    });
});

// CRM — phân hệ employee
Route::prefix('crm')->group(function () {
    Route::middleware('ensure_guard:employee')->get('/ping', function (Request $request) {
        return ['type' => 'employee', 'id' => $request->user()->id];
    });
});
