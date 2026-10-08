<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Http\Request;

class AdminDemoService
{
    public const EMAIL = 'readonly-demo@home-fit.invalid';

    // Exact route template AND controller action, never a prefix permission.
    private const READS = [
        'api/admin/me' => 'AdminAuthController@me',
        'api/admin/dashboard' => 'DashboardController@index',
        'api/admin/products' => 'AdminProductController@index',
        'api/admin/products/{id}' => 'AdminProductController@show',
        'api/admin/categories' => 'AdminCategoryController@index',
        'api/admin/inventory' => 'AdminInventoryController@index',
        'api/admin/banners' => 'AdminBannerController@index',
        'api/admin/recommended-products' => 'AdminRecommendedProductController@index',
    ];

    public function isDemo(?Admin $admin): bool
    {
        if (! $admin) return false;
        // Keep existing demo sessions restricted even if configuration or email changes.
        return $admin->email === self::EMAIL
            || (request()->hasSession() && request()->session()->get('admin_demo_id') === $admin->id);
    }

    public function allowsRead(Request $request): bool
    {
        $route = $request->route();
        return $request->method() === 'GET' && $route
            && isset(self::READS[$route->uri()])
            && $route->getActionName() === 'App\\Http\\Controllers\\Api\\'.self::READS[$route->uri()];
    }

    public function configuredAdmin(): ?Admin
    {
        $id = config('demo.admin_id');
        if (! config('demo.enabled') || ! is_scalar($id) || ! ctype_digit((string) $id) || (int) $id < 1) return null;
        $admin = Admin::query()->find((int) $id);
        return $admin && $admin->email === self::EMAIL && $admin->status === 'active' ? $admin : null;
    }
}
