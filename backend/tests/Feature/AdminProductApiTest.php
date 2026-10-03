<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_guest_and_member_only_cannot_read_admin_products(): void
    {
        $product = $this->product();
        foreach (['/api/admin/products', '/api/admin/products/'.$product->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['/api/admin/products', '/api/admin/products/'.$product->id] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
    }

    public static function readEndpoints(): array
    {
        return ['list' => [false], 'detail' => [true]];
    }

    #[DataProvider('readEndpoints')]
    public function test_disabled_admin_is_rejected_and_member_identity_is_preserved(bool $detail): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $url = '/api/admin/products'.($detail ? '/'.$this->product()->id : '');
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_list_contains_all_statuses_and_only_summary_fields(): void
    {
        $this->login();
        foreach (['active', 'inactive', 'disabled'] as $status) {
            $this->product(['status' => $status]);
        }
        $response = $this->getJson('/api/admin/products')->assertOk()
            ->assertJsonStructure(['data', 'links', 'meta'])->assertJsonPath('meta.per_page', 10);
        $this->assertEqualsCanonicalizing(['active', 'inactive', 'disabled'], array_column($response->json('data'), 'status'));
        $this->assertEqualsCanonicalizing([
            'id', 'product_code', 'name', 'category', 'price', 'stock', 'has_variants',
            'low_stock_threshold', 'status', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $response->assertJsonMissingPath('success')->assertJsonMissingPath('data.0.description')
            ->assertJsonMissingPath('data.0.images')->assertJsonMissingPath('data.0.specifications')
            ->assertJsonMissingPath('data.0.variants');
    }

    public function test_admin_read_does_not_run_member_c06_or_change_public_product_contract(): void
    {
        $user = User::factory()->create(['password' => 'test-member-secret']);
        $admin = Admin::factory()->create(['password' => 'test-admin-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        $this->nextRequest();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        $active = $this->product();
        $inactive = $this->product(['status' => 'inactive']);
        $list = $this->getJson('/api/admin/products');
        $this->assertSame(200, $list->status(), 'Admin list must bypass member C06');
        $list->assertJsonCount(2, 'data');
        $this->nextRequest();
        $detail = $this->getJson('/api/admin/products/'.$inactive->id);
        $this->assertSame(200, $detail->status(), 'Admin detail must bypass member C06');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAuthenticated('admin');
        $this->nextRequest();
        $public = $this->getJson('/api/products');
        $this->assertSame(200, $public->status(), 'Public product API stays public');
        $public->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('meta.per_page', 8)
            ->assertJsonMissingPath('data.0.has_variants')->assertJsonMissingPath('data.0.low_stock_threshold');
        $this->getJson('/api/products/'.$inactive->id)->assertNotFound();
    }

    public function test_search_category_status_and_combined_filters_are_grouped(): void
    {
        $this->login();
        $target = $this->product(['name' => '目標啞鈴', 'product_code' => 'PRD-LOOKUP', 'status' => 'inactive']);
        $this->product(['name' => '其他啞鈴', 'status' => 'active']);
        $this->product(['name' => '其他商品', 'status' => 'inactive']);
        foreach ([
            ['search' => '目標'], ['search' => 'LOOKUP'], ['category_id' => $target->category_id],
            ['search' => '啞鈴', 'status' => 'inactive'],
            ['search' => 'LOOKUP', 'category_id' => $target->category_id, 'status' => 'inactive'],
        ] as $query) {
            $this->getJson('/api/admin/products?'.http_build_query($query))->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
        }
        $this->getJson('/api/admin/products?status=inactive')->assertJsonCount(2, 'data');
        $this->getJson('/api/admin/products?search=does-not-exist')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_pagination_uses_ten_rows_and_created_at_then_id_descending(): void
    {
        $this->login();
        $category = $this->category();
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = $this->product(['category_id' => $category->id, 'created_at' => '2026-01-01 12:00:00'])->id;
        }
        $newer = $this->product(['category_id' => $category->id, 'created_at' => '2026-02-01 12:00:00']);
        $expected = array_merge([$newer->id], array_reverse($ids));
        $first = $this->getJson('/api/admin/products?per_page=99')->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13)->assertJsonPath('meta.per_page', 10);
        $this->assertSame(array_slice($expected, 0, 10), array_column($first->json('data'), 'id'));
        $second = $this->getJson('/api/admin/products?page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(array_slice($expected, 10), array_column($second->json('data'), 'id'));
    }

    public static function invalidQueries(): array
    {
        return [
            'status' => [['status' => 'unknown'], 'status'],
            'status array' => [['status' => ['active']], 'status'],
            'search array' => [['search' => ['x']], 'search'],
            'search too long' => [['search' => str_repeat('x', 101)], 'search'],
            'category array' => [['category_id' => [1]], 'category_id'],
            'category missing' => [['category_id' => 9999], 'category_id'],
            'category string' => [['category_id' => 'bad'], 'category_id'],
            'page zero' => [['page' => 0], 'page'],
            'page array' => [['page' => [1]], 'page'],
            'page decimal' => [['page' => '1.2'], 'page'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_queries_use_laravel_validation(array $query, string $field): void
    {
        $this->login();
        $this->getJson('/api/admin/products?'.http_build_query($query))->assertUnprocessable()
            ->assertJsonValidationErrors($field)->assertJsonMissingPath('success');
    }

    public function test_category_filter_rejects_parent_but_does_not_add_active_category_policy(): void
    {
        $this->login();
        $child = $this->category('inactive');
        $product = $this->product(['category_id' => $child->id]);
        $this->getJson('/api/admin/products?category_id='.$child->parent_id)->assertUnprocessable();
        $this->getJson('/api/admin/products?category_id='.$child->id)->assertOk()->assertJsonPath('data.0.id', $product->id);
    }

    public function test_detail_all_statuses_missing_and_non_numeric_id(): void
    {
        $this->login();
        foreach (['active', 'inactive', 'disabled'] as $status) {
            $product = $this->product(['status' => $status]);
            $this->getJson('/api/admin/products/'.$product->id)->assertOk()->assertJsonPath('data.status', $status);
        }
        $this->getJson('/api/admin/products/99999')->assertNotFound();
        $this->getJson('/api/admin/products/not-a-number')->assertNotFound();
    }

    public function test_detail_preserves_inactive_category_and_has_complete_sorted_relations(): void
    {
        $this->login();
        $category = $this->category('inactive');
        $product = $this->product(['category_id' => $category->id, 'stock' => null, 'price' => '1234.50', 'low_stock_threshold' => 7]);
        foreach ([2, 1, 1] as $sort) {
            $product->images()->create(['image_path' => 'products/test.jpg', 'image_type' => 'detail', 'sort_order' => $sort, 'is_primary' => false]);
            $product->specifications()->create(['spec_name' => '材質', 'spec_value' => '鋼', 'sort_order' => $sort]);
        }
        $variant = $product->variants()->create(['option_name' => '顏色', 'option_value' => '黑', 'stock' => 3, 'status' => 'inactive']);
        $response = $this->getJson('/api/admin/products/'.$product->id)->assertOk()
            ->assertJsonPath('data.category.id', $category->id)->assertJsonPath('data.category.status', 'inactive')
            ->assertJsonPath('data.category.parent.id', $category->parent_id)->assertJsonPath('data.category.parent.status', 'active')
            ->assertJsonPath('data.price', '1234.50')->assertJsonPath('data.stock', null)
            ->assertJsonPath('data.has_variants', true)->assertJsonPath('data.low_stock_threshold', 7)
            ->assertJsonPath('data.variants.0.id', $variant->id)->assertJsonPath('data.variants.0.status', 'inactive')
            ->assertJsonPath('data.variants.0.stock', 3)->assertJsonMissingPath('success')
            ->assertJsonStructure(['data' => ['id', 'product_code', 'name', 'price', 'short_description', 'description',
                'stock', 'low_stock_threshold', 'status', 'created_at', 'updated_at',
                'category' => ['id', 'name', 'status', 'parent' => ['id', 'name', 'status']],
                'images' => [['id', 'image_path', 'image_url', 'image_type', 'is_primary', 'sort_order']],
                'specifications' => [['id', 'spec_name', 'spec_value', 'sort_order']],
                'variants' => [['id', 'option_name', 'option_value', 'stock', 'status']],
            ]]);
        foreach (['images', 'specifications'] as $relation) {
            $this->assertSame($product->$relation()->orderBy('sort_order')->orderBy('id')->pluck('id')->all(), array_column($response->json('data.'.$relation), 'id'));
        }
        // 読取不修正舊資料的主圖，也不做寫入。
        $this->assertSame(0, $product->images()->where('is_primary', true)->count());
    }

    public function test_relation_queries_do_not_grow_with_list_size_and_detail_is_eager_loaded(): void
    {
        $this->login();
        $product = $this->product();
        DB::connection()->enableQueryLog();
        $this->getJson('/api/admin/products')->assertOk();
        $small = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        for ($i = 0; $i < 8; $i++) {
            $this->product();
        }
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $this->getJson('/api/admin/products')->assertOk();
        $this->assertSame($small, count(DB::connection()->getQueryLog()));
        DB::connection()->flushQueryLog();
        $this->getJson('/api/admin/products/'.$product->id)->assertOk();
        $this->assertLessThanOrEqual(8, count(DB::connection()->getQueryLog()));
        DB::connection()->disableQueryLog();
    }

    private function login(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    private function nextRequest(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        app('session.store')->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    private function category(string $status = 'active'): Category
    {
        $parent = Category::create(['name' => '主分類', 'status' => 'active']);
        return Category::create(['parent_id' => $parent->id, 'name' => '子分類', 'status' => $status]);
    }

    private function product(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge(['category_id' => $attributes['category_id'] ?? $this->category()->id], $attributes));
    }
}
