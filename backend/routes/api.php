<?php

// use Illuminate\Http\Request;
// use Illuminate\Support\Facades\Route;

// Route::get('/user', function (Request $request) {
//     return $request->user();
// })->middleware('auth:sanctum');



use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\AddressController;
use App\Http\Controllers\Api\AdminAuthController;
use App\Http\Controllers\Api\AdminBannerController;
use App\Http\Controllers\Api\AdminRecommendedProductController;
use App\Http\Controllers\Api\AdminCategoryController;
use App\Http\Controllers\Api\AdminInventoryController;
use App\Http\Controllers\Api\AdminOrderController;
use App\Http\Controllers\Api\AdminUserController;
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
use App\Http\Controllers\Api\BannerController;
use App\Http\Controllers\Api\RecommendedProductController;
use App\Models\City;
use App\Http\Controllers\Api\AdminManagementController;

Route::get('/test', function () {
    return response()->json([
        'message' => 'Laravel API connection successful',
    ]);
});

Route::get('/products', [ProductController::class, 'index']);
Route::get('/products/{id}/related', [ProductController::class, 'related']);
Route::get('/products/{id}', [ProductController::class, 'show']);

Route::get('/categories', [CategoryController::class, 'index']);
Route::get('/banners', [BannerController::class, 'index']);
Route::get('/recommended-products', [RecommendedProductController::class, 'index']);

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
            Route::get('/dashboard', [DashboardController::class, 'index']);
            Route::middleware('admin.permission:admin_manage')->group(function () {
                Route::get('/admins', [AdminManagementController::class, 'index']);
                Route::post('/admins', [AdminManagementController::class, 'store']);
                Route::get('/permissions', [AdminManagementController::class, 'catalog']);
                Route::get('/admins/{id}', [AdminManagementController::class, 'show'])->whereNumber('id');
                Route::patch('/admins/{id}', [AdminManagementController::class, 'update'])->whereNumber('id');
                Route::patch('/admins/{id}/status', [AdminManagementController::class, 'status'])->whereNumber('id');
                Route::put('/admins/{id}/permissions', [AdminManagementController::class, 'permissions'])->whereNumber('id');
            });
            Route::get('/recommended-products', [AdminRecommendedProductController::class, 'index'])->middleware('admin.permission:home_content_manage');
            Route::post('/recommended-products', [AdminRecommendedProductController::class, 'store'])->middleware('admin.permission:home_content_manage');
            Route::patch('/recommended-products/order', [AdminRecommendedProductController::class, 'order'])->middleware('admin.permission:home_content_manage');
            Route::delete('/recommended-products/{id}', [AdminRecommendedProductController::class, 'destroy'])->whereNumber('id')->middleware('admin.permission:home_content_manage');
            Route::get('/banners', [AdminBannerController::class, 'index'])->middleware('admin.permission:home_content_manage');
            Route::post('/banners', [AdminBannerController::class, 'store'])->middleware('admin.permission:home_content_manage');
            Route::patch('/banners/order', [AdminBannerController::class, 'order'])->middleware('admin.permission:home_content_manage');
            Route::patch('/banners/{id}/status', [AdminBannerController::class, 'status'])->whereNumber('id')->middleware('admin.permission:home_content_manage');
            Route::patch('/banners/{id}', [AdminBannerController::class, 'update'])->whereNumber('id')->middleware('admin.permission:home_content_manage');
            Route::delete('/banners/{id}', [AdminBannerController::class, 'destroy'])->whereNumber('id')->middleware('admin.permission:home_content_manage');
            Route::get('/users', [AdminUserController::class, 'index'])->middleware('admin.permission:member_manage');
            Route::get('/users/{id}', [AdminUserController::class, 'show'])->whereNumber('id')->middleware('admin.permission:member_manage');
            Route::patch('/users/{id}/status', [AdminUserController::class, 'updateStatus'])->whereNumber('id')->middleware('admin.permission:member_manage');
            Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('admin.permission:order_manage');
            Route::get('/orders/{id}', [AdminOrderController::class, 'show'])->whereNumber('id')->middleware('admin.permission:order_manage');
            Route::patch('/orders/{id}/status', [AdminOrderController::class, 'updateStatus'])->whereNumber('id')->middleware('admin.permission:order_manage');
            Route::patch('/orders/{id}/payment-status', [AdminOrderController::class, 'updatePaymentStatus'])->whereNumber('id')->middleware('admin.permission:order_manage');
            Route::patch('/orders/{id}/shipment', [AdminOrderController::class, 'updateShipment'])->whereNumber('id')->middleware('admin.permission:order_manage');
            Route::post('/orders/{id}/cancel', [AdminOrderController::class, 'cancel'])->whereNumber('id')->middleware('admin.permission:order_manage');
            Route::get('/categories', [AdminCategoryController::class, 'index'])->middleware('admin.permission:category_manage');
            Route::post('/categories', [AdminCategoryController::class, 'store'])->middleware('admin.permission:category_manage');
            Route::patch('/categories/{id}/status', [AdminCategoryController::class, 'status'])->whereNumber('id')->middleware('admin.permission:category_manage');
            Route::patch('/categories/{id}', [AdminCategoryController::class, 'update'])->whereNumber('id')->middleware('admin.permission:category_manage');
            Route::delete('/categories/{id}', [AdminCategoryController::class, 'destroy'])->whereNumber('id')->middleware('admin.permission:category_manage');
            Route::get('/inventory', [AdminInventoryController::class, 'index'])->middleware('admin.permission:inventory_manage');
            Route::patch('/inventory/variants/{variantId}', [AdminInventoryController::class, 'adjustVariant'])->whereNumber('variantId')->middleware('admin.permission:inventory_manage');
            Route::patch('/inventory/{productId}', [AdminInventoryController::class, 'adjustProduct'])->whereNumber('productId')->middleware('admin.permission:inventory_manage');
            Route::get('/products', [AdminProductController::class, 'index'])->middleware('admin.permission:product_manage,home_content_manage');
            Route::get('/products/{id}', [AdminProductController::class, 'show'])->whereNumber('id')->middleware('admin.permission:product_manage');
            Route::post('/products', [AdminProductController::class, 'store'])->middleware('admin.permission:product_manage');
            Route::patch('/products/{id}', [AdminProductController::class, 'update'])->whereNumber('id')->middleware('admin.permission:product_manage');
            Route::patch('/products/{id}/status', [AdminProductController::class, 'updateStatus'])->whereNumber('id')->middleware('admin.permission:product_manage');
            Route::delete('/products/{id}', [AdminProductController::class, 'destroy'])->whereNumber('id')->middleware('admin.permission:product_manage');
            Route::post('/products/{productId}/images', [AdminProductImageController::class, 'store'])->whereNumber('productId')->middleware('admin.permission:product_manage');
            Route::patch('/product-images/{imageId}', [AdminProductImageController::class, 'update'])->whereNumber('imageId')->middleware('admin.permission:product_manage');
            Route::delete('/product-images/{imageId}', [AdminProductImageController::class, 'destroy'])->whereNumber('imageId')->middleware('admin.permission:product_manage');
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
