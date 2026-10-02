<?php

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');



use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CityController;
use App\Models\City;

Route::get('/test', function () {
    return response()->json([
        'message' => 'Laravel API connection successful',
    ]);
});

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}/related', [ProductController::class, 'related']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::get('/categories', [CategoryController::class, 'index']);

Route::get('/cities', [CityController::class, 'index']);
Route::get('/cities/{cityId}/districts', [CityController::class, 'districts'])
    ->whereNumber('cityId');

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::middleware('member.active')->group(function () {
        Route::get('/orders', [OrderController::class, 'index']);
        Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id');
        Route::post('/orders/{id}/cancel', [OrderController::class, 'cancel'])->whereNumber('id');
        Route::get('/me', [ProfileController::class, 'show']);
        Route::patch('/me', [ProfileController::class, 'update']);
        Route::patch('/me/password', [ProfileController::class, 'updatePassword']);

        Route::get('/addresses', [AddressController::class, 'index']);
        Route::post('/addresses', [AddressController::class, 'store']);
        Route::patch('/addresses/{id}', [AddressController::class, 'update'])->whereNumber('id');
        Route::delete('/addresses/{id}', [AddressController::class, 'destroy'])->whereNumber('id');
        Route::patch('/addresses/{id}/default', [AddressController::class, 'setDefault'])->whereNumber('id');

        Route::get('/cart', [CartController::class, 'show']);
        Route::post('/cart/items', [CartController::class, 'store']);
        Route::post('/cart/merge', [CartController::class, 'merge']);
        Route::patch('/cart/items/{id}', [CartController::class, 'update'])
            ->whereNumber('id');
        Route::delete('/cart/items/{id}', [CartController::class, 'destroy'])
            ->whereNumber('id');
        Route::delete('/cart', [CartController::class, 'clear']);

        Route::post('/checkout', CheckoutController::class);
    });
});
