<?php

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');



use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminProductController;
use App\Http\Controllers\Api\AdminProductImageController;
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

Route::prefix('admin')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login']);

    Route::middleware('auth:admin')->group(function () {
        Route::post('/logout', [AdminAuthController::class, 'logout']);
        Route::get('/me', [AdminAuthController::class, 'me'])->middleware('admin.active');
        Route::middleware('admin.active')->group(function () {
            Route::get('/categories', [AdminCategoryController::class, 'index']);
            Route::post('/categories', [AdminCategoryController::class, 'store']);
            Route::patch('/categories/{id}', [AdminCategoryController::class, 'update'])->whereNumber('id');
            Route::get('/inventory', [AdminInventoryController::class, 'index']);
            Route::patch('/inventory/variants/{variantId}', [AdminInventoryController::class, 'adjustVariant'])->whereNumber('variantId');
            Route::patch('/inventory/{productId}', [AdminInventoryController::class, 'adjustProduct'])->whereNumber('productId');
            Route::get('/products', [AdminProductController::class, 'index']);
            Route::get('/products/{id}', [AdminProductController::class, 'show'])->whereNumber('id');
            Route::post('/products', [AdminProductController::class, 'store']);
            Route::patch('/products/{id}', [AdminProductController::class, 'update'])->whereNumber('id');
            Route::patch('/products/{id}/status', [AdminProductController::class, 'updateStatus'])->whereNumber('id');
            Route::delete('/products/{id}', [AdminProductController::class, 'destroy'])->whereNumber('id');
            Route::post('/products/{productId}/images', [AdminProductImageController::class, 'store'])->whereNumber('productId');
            Route::patch('/product-images/{imageId}', [AdminProductImageController::class, 'update'])->whereNumber('imageId');
            Route::delete('/product-images/{imageId}', [AdminProductImageController::class, 'destroy'])->whereNumber('imageId');
        });
    });
});

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
