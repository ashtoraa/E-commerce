<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WalletController  ;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Product;
use App\Http\Controllers\CartController;
use App\Http\Controllers\OrderController;

/*
|--------------------------------------------------------------------------
| AUTH ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('auth')->group(function () {

    // Register
    Route::post('/register', [AuthController::class, 'register']);

    // Login
    Route::post('/login', [AuthController::class, 'login']);

    // Logout (Protected)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});


/*
|--------------------------------------------------------------------------
| PRODUCT ROUTES
|--------------------------------------------------------------------------
*/

Route::prefix('products')->group(function () {



    // Protected (user actions)
    Route::middleware('auth:sanctum')->group(function () {

        Route::get('/MyProduct', [Product::class, 'MyProducts']);
        Route::put('/my/{id}/quantity', [Product::class, 'updateQuantity']);

        Route::post('/add', [Product::class, 'store']);

        Route::delete('/my/{id}', [Product::class, 'destroy']);
    });

        // Public
    Route::get('/', [Product::class, 'allProducts']);
    Route::get('/{id}', [Product::class, 'showAny']);

});


/*
|--------------------------------------------------------------------------
| CART ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->prefix('cart')->group(function () {

    Route::post('/', [CartController::class, 'add']);
    Route::get('/', [CartController::class, 'show']);
    Route::delete('/', [CartController::class, 'remove']);
});


/*
|--------------------------------------------------------------------------
| ORDER / CHECKOUT ROUTES
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {

    Route::post('/checkout', [OrderController::class, 'checkout']);
        Route::delete('/orders/{id}', [OrderController::class, 'deleteOrder']);
        Route::post('/orders/process-daily-sales', [OrderController::class, 'processDailySales']);


});


Route::middleware('auth:sanctum')->group(function () {

    // 💰 عرض الرصيد
    Route::get('/wallet', [WalletController::class, 'show']);

    // ➕ إضافة رصيد
    Route::post('/wallet/add', [WalletController::class, 'add']);

});
