<?php

namespace Tests\Feature;

use App\Models\{Admin, Permission, User};
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Route};
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPermissionRouteMappingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        $this->seed(PermissionSeeder::class);
    }

    // Inventory of the 43 real business routes, not inferred from middleware under test.
    public static function mapping(): array
    {
        $groups = [
            'admin_manage' => ['GET admins', 'POST admins', 'GET admins/{id}', 'PATCH admins/{id}', 'PATCH admins/{id}/status', 'GET permissions', 'PUT admins/{id}/permissions'],
            'product_manage' => ['GET products/{id}', 'POST products', 'PATCH products/{id}', 'PATCH products/{id}/status', 'DELETE products/{id}', 'POST products/{productId}/images', 'PATCH product-images/{imageId}', 'DELETE product-images/{imageId}'],
            'product_manage,home_content_manage' => ['GET products'],
            'category_manage' => ['GET categories', 'POST categories', 'PATCH categories/{id}', 'PATCH categories/{id}/status', 'DELETE categories/{id}'],
            'inventory_manage' => ['GET inventory', 'PATCH inventory/{productId}', 'PATCH inventory/variants/{variantId}'],
            'order_manage' => ['GET orders', 'GET orders/{id}', 'PATCH orders/{id}/status', 'PATCH orders/{id}/payment-status', 'PATCH orders/{id}/shipment', 'POST orders/{id}/cancel'],
            'member_manage' => ['GET users', 'GET users/{id}', 'PATCH users/{id}/status'],
            'home_content_manage' => ['GET banners', 'POST banners', 'PATCH banners/order', 'PATCH banners/{id}', 'PATCH banners/{id}/status', 'DELETE banners/{id}', 'GET recommended-products', 'POST recommended-products', 'PATCH recommended-products/order', 'DELETE recommended-products/{id}'],
        ];
        $result = [];
        foreach ($groups as $codes => $routes) foreach ($routes as $route) $result[$route] = [$route, $codes];
        return $result;
    }

    public function test_exact_mapping_order_catalog_and_auth_exclusions(): void
    {
        $actual = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/')) continue;
            $path = substr($route->uri(), 10);
            $middleware = $route->gatherMiddleware();
            $permissions = array_values(array_filter($middleware, fn ($m) => str_starts_with($m, 'admin.permission:')));
            if ($path === 'dashboard') {
                $this->assertSame(['GET', 'HEAD'], $route->methods());
                $this->assertSame(['api', 'admin.demo-access', 'auth:admin', 'admin.active'], $middleware);
                $this->assertSame([], $permissions);
                continue;
            }
            if (in_array($path, ['login', 'demo', 'logout', 'me'])) {
                $this->assertSame([], $permissions);
                if ($path === 'logout') $this->assertNotContains('admin.active', $middleware);
                continue;
            }
            $key = $route->methods()[0].' '.$path;
            $actual[$key] = [$key, substr($permissions[0] ?? '', 17)];
            $this->assertCount(1, $permissions);
            $this->assertSame(['api', 'admin.demo-access', 'auth:admin', 'admin.active', $permissions[0]], $middleware);
            foreach (explode(',', substr($permissions[0], 17)) as $code) $this->assertArrayHasKey($code, Permission::CATALOG);
        }
        $expected = self::mapping(); ksort($actual); ksort($expected);
        $this->assertSame($expected, $actual);
        $this->assertCount(43, $actual);
        $this->assertCount(83, Route::getRoutes());
    }

    #[DataProvider('mapping')]
    public function test_every_real_route_denies_missing_permission_before_business_work(string $route, string $codes): void
    {
        [$method, $path] = explode(' ', $route, 2);
        $path = preg_replace('/\{[^}]+\}/', '99999', $path);
        $member = User::factory()->create(['status' => 'disabled']);
        $cart = $member->cart()->create();
        $admin = Admin::factory()->create();
        $admin->permissions()->attach(Permission::where('code', $codes === 'admin_manage' ? 'product_manage' : 'admin_manage')->value('id'));
        $before = DB::table('admin_permission')->get()->toArray();
        $this->actingAs($member, 'web')->actingAs($admin, 'admin')->withSession(['cart_marker' => 'keep'])
            ->json($method, '/api/admin/'.$path, [])->assertForbidden()
            ->assertExactJson(['message' => '您沒有此功能的操作權限。', 'code' => 'ADMIN_PERMISSION_DENIED'])
            ->assertSessionHas('cart_marker', 'keep');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('products', 0);
        $this->assertEquals($before, DB::table('admin_permission')->get()->toArray());
    }

    public static function modules(): array
    {
        return [['products', 'product_manage'], ['categories', 'category_manage'], ['inventory', 'inventory_manage'], ['orders', 'order_manage'], ['users', 'member_manage'], ['banners', 'home_content_manage'], ['recommended-products', 'home_content_manage']];
    }

    #[DataProvider('modules')]
    public function test_grant_and_revoke_use_latest_database_without_relogin(string $path, string $code): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $this->getJson('/api/admin/'.$path)->assertForbidden()->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $admin->permissions()->attach(Permission::where('code', $code)->value('id'));
        $this->getJson('/api/admin/'.$path)->assertOk();
        $admin->permissions()->detach();
        $this->getJson('/api/admin/'.$path)->assertForbidden();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.permissions', []);
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public static function selector(): array
    {
        return [[[], 403], [['product_manage'], 200], [['home_content_manage'], 200], [['product_manage', 'home_content_manage'], 200], [['order_manage'], 403]];
    }

    #[DataProvider('selector')]
    public function test_product_selector_any_permission(array $codes, int $status): void
    {
        $admin = Admin::factory()->create();
        $admin->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        $this->actingAs($admin, 'admin')->getJson('/api/admin/products')->assertStatus($status);
    }

    public function test_home_only_cannot_use_product_details_mutations_images_or_inventory(): void
    {
        $admin = Admin::factory()->create();
        $admin->permissions()->attach(Permission::where('code', 'home_content_manage')->value('id'));
        $this->actingAs($admin, 'admin');
        foreach (self::mapping() as [$route, $codes]) {
            if (! in_array($codes, ['product_manage', 'inventory_manage'])) continue;
            [$method, $path] = explode(' ', $route, 2);
            $this->json($method, '/api/admin/'.preg_replace('/\{[^}]+\}/', '99999', $path))->assertForbidden()->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        }
    }

    public static function deniedMutations(): array
    {
        return [['PATCH', 'products', '/status', ['status' => 'disabled']], ['PATCH', 'inventory', '', ['adjustment' => 5]], ['DELETE', 'product-images', '', []], ['DELETE', 'products', '', []]];
    }

    #[DataProvider('deniedMutations')]
    public function test_denied_valid_mutations_leave_existing_product_stock_cart_and_images_intact(string $method, string $path, string $suffix, array $payload): void
    {
        $root = \App\Models\Category::create(['name' => '主分類', 'status' => 'active']);
        $child = \App\Models\Category::create(['name' => '子分類', 'status' => 'active', 'parent_id' => $root->id]);
        $product = \App\Models\Product::factory()->create(['category_id' => $child->id, 'stock' => 10, 'status' => 'active']);
        $image = $product->images()->create(['image_path' => 'products/legacy.jpg', 'image_type' => 'gallery', 'is_primary' => true, 'sort_order' => 0]);
        $member = User::factory()->create();
        $cart = $member->cart()->create();
        $item = $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        $admin = Admin::factory()->create();
        $this->actingAs($member, 'web')->actingAs($admin, 'admin');
        $id = $path === 'product-images' ? $image->id : $product->id;
        $this->json($method, '/api/admin/'.$path.'/'.$id.$suffix, $payload)->assertForbidden()->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'stock' => 10, 'status' => 'active']);
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 2]);
        $this->assertDatabaseHas('product_images', ['id' => $image->id]);
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    #[DataProvider('modules')]
    public function test_guest_member_and_disabled_precede_permission_check(string $path, string $code): void
    {
        $this->getJson('/api/admin/'.$path)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/admin/'.$path)->assertUnauthorized();
    }

    #[DataProvider('modules')]
    public function test_disabled_precedes_permission_check(string $path, string $code): void
    {
        $admin = Admin::factory()->disabled()->create();
        $admin->permissions()->attach(Permission::where('code', $code)->value('id'));
        $this->actingAs($admin, 'admin')->getJson('/api/admin/'.$path)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
    }
}
