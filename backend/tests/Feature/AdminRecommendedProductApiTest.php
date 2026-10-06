<?php

namespace Tests\Feature;

use App\Models\{Admin, Category, Product, RecommendedProduct, User};
use App\Services\{AdminProductService, AdminRecommendedProductService};
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PDOException;
use RuntimeException;
use Tests\TestCase;

class AdminRecommendedProductApiTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void
    {
        parent::setUp(); config(['sanctum.stateful' => ['localhost']]); $this->withHeader('Origin', 'http://localhost');
    }
    private function login(): void { $this->actingAs(Admin::factory()->create(), 'admin'); }
    private function product(array $data = []): Product
    {
        $parent = Category::create(['name' => '主分類', 'status' => 'active']);
        $child = Category::create(['name' => '子分類', 'parent_id' => $parent->id, 'status' => 'active']);
        return Product::factory()->create($data + ['category_id' => $child->id, 'status' => 'active', 'stock' => 0]);
    }
    private function relation(?Product $product = null, array $data = []): RecommendedProduct
    {
        return RecommendedProduct::create($data + ['product_id' => ($product ?? $this->product())->id])->fresh();
    }
    public static function endpoints(): array { return [['get', ''], ['post', ''], ['patch', '/order'], ['delete', '/1']]; }
    #[DataProvider('endpoints')]
    public function test_guest_and_member_only_auth(string $method, string $suffix): void
    {
        $this->json($method, '/api/admin/recommended-products'.$suffix)->assertUnauthorized();
        $member = User::factory()->create(); $member->cart()->create(); $this->actingAs($member, 'web');
        $this->json($method, '/api/admin/recommended-products'.$suffix)->assertUnauthorized();
    }
    #[DataProvider('endpoints')]
    public function test_disabled_admin_member_isolation(string $method, string $suffix): void
    {
        $member = User::factory()->create(); $member->cart()->create(); $this->actingAs($member, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->json($method, '/api/admin/recommended-products'.$suffix)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($member, 'web'); $this->assertDatabaseCount('carts', 1);
    }
    public function test_get_empty_exact_shape_nested_summary_identity_and_order(): void
    {
        $this->login(); $this->getJson('/api/admin/recommended-products')->assertExactJson(['data' => []]);
        $this->product(); $a = $this->relation(null, ['sort_order' => 3]); $b = $this->relation(null, ['sort_order' => 1]); $c = $this->relation(null, ['sort_order' => 1]);
        $result = $this->getJson('/api/admin/recommended-products')->assertOk()->assertJsonMissingPath('meta')->assertJsonMissingPath('success');
        $this->assertSame([$b->id, $c->id, $a->id], array_column($result->json('data'), 'id'));
        $this->assertNotSame($b->id, $b->product_id);
        $this->assertEqualsCanonicalizing(['id', 'product_id', 'sort_order', 'is_publicly_visible', 'unavailable_reason', 'product'], array_keys($result->json('data.0')));
        $product = $result->json('data.0.product');
        $this->assertEqualsCanonicalizing(['id', 'product_code', 'name', 'category', 'price', 'stock', 'has_variants', 'low_stock_threshold', 'status', 'created_at', 'updated_at'], array_keys($product));
        $this->assertSame($b->product_id, $product['id']); $this->assertFalse($product['has_variants']);
    }
    public static function visibility(): array
    {
        return [['active', true, null], ['inactive', false, '下架'], ['disabled', false, '停用'], ['child', false, '子分類'],
            ['parent', false, '主分類'], ['root', false, '結構'], ['third', false, '結構'], ['variant-zero', true, null]];
    }
    #[DataProvider('visibility')]
    public function test_create_any_existing_product_and_cross_check_visibility(string $state, bool $visible, ?string $reason): void
    {
        $this->login(); $product = $this->product(); $child = $product->category; $parent = $child->parent;
        if (in_array($state, ['inactive', 'disabled'])) $product->update(['status' => $state]);
        if ($state === 'child') $child->update(['status' => 'inactive']);
        if ($state === 'parent') $parent->update(['status' => 'inactive']);
        if ($state === 'root') $product->update(['category_id' => $parent->id]);
        if ($state === 'third') { $third = Category::create(['name' => '第三層', 'parent_id' => $child->id, 'status' => 'active']); $product->update(['category_id' => $third->id]); }
        if ($state === 'variant-zero') {
            $product->update(['stock' => null]); $product->variants()->create(['option_name' => '顏色', 'option_value' => '黑', 'stock' => 0, 'status' => 'inactive']);
        }
        $before = $product->fresh()->getAttributes();
        $result = $this->postJson('/api/admin/recommended-products', ['product_id' => $product->id])->assertCreated()->assertJsonPath('data.sort_order', 0)
            ->assertJsonPath('data.is_publicly_visible', $visible)->assertJsonMissingPath('success');
        $id = $result->json('data.id');
        if ($reason) $this->assertStringContainsString($reason, $result->json('data.unavailable_reason')); else $result->assertJsonPath('data.unavailable_reason', null);
        $this->assertSame($before, $product->fresh()->getAttributes());
        $admin = $this->getJson('/api/admin/recommended-products')->assertJsonCount(1, 'data');
        $admin->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_publicly_visible', $visible);
        $public = $this->getJson('/api/recommended-products')->assertOk();
        $this->assertSame($visible ? [$product->id] : [], array_column($public->json('data'), 'id'));
        $this->assertSame($visible, Product::sellable()->whereKey($product->id)->exists());
        if (in_array($state, ['inactive', 'disabled', 'child', 'parent'])) {
            $product->update(['status' => 'active']); $child->update(['status' => 'active']); $parent->update(['status' => 'active']);
            $this->getJson('/api/admin/recommended-products')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_publicly_visible', true);
            $this->getJson('/api/recommended-products')->assertJsonPath('data.0.id', $product->id);
            $this->assertDatabaseCount('recommended_products', 1);
        }
    }
    public static function badStore(): array
    {
        return [[[]], [['product_id' => null]], [['product_id' => []]], [['product_id' => 1.5]], [['product_id' => 0]], [['product_id' => 999]],
            [['id' => 1]], [['sort_order' => 1]], [['status' => 'active']], [['is_publicly_visible' => true]], [['unavailable_reason' => null]],
            [['product' => []]], [['extra.path' => 3]], [['product_id.*' => 3]]];
    }
    #[DataProvider('badStore')]
    public function test_store_validation_is_strict(array $bad): void
    {
        $this->login(); $product = $this->product();
        $this->postJson('/api/admin/recommended-products', $bad === [] ? [] : $bad + ['product_id' => $product->id])->assertUnprocessable();
        $this->assertDatabaseCount('recommended_products', 0);
    }
    public function test_duplicate_business_validation_and_unique_db_last_defense(): void
    {
        $this->login(); $row = $this->relation();
        $this->postJson('/api/admin/recommended-products', ['product_id' => $row->product_id])->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->assertDatabaseCount('recommended_products', 1);
        $this->expectException(QueryException::class); RecommendedProduct::create(['product_id' => $row->product_id]);
    }
    public static function sqlFailures(): array
    {
        return [['23000', 1062, "Duplicate entry '1' for key 'recommended_products_product_id_unique'", 422],
            ['23000', 19, 'UNIQUE constraint failed: recommended_products.product_id', 422],
            ['23000', 1062, "Duplicate entry '1' for key 'another_unique'", 500],
            ['23000', 1452, 'Foreign key failed', 500], ['40001', 1213, 'Deadlock found', 500], ['42000', 1064, 'Syntax error', 500]];
    }
    #[DataProvider('sqlFailures')]
    public function test_unique_fallback_is_narrow(string $state, int $code, string $message, int $expected): void
    {
        $this->login(); $product = $this->product();
        $previous = new PDOException($message); $previous->errorInfo = [$state, $code, $message];
        $exception = new QueryException('sqlite', 'insert into recommended_products (product_id) values (?)', [$product->id], $previous);
        RecommendedProduct::creating(fn () => throw $exception);
        try {
            $this->postJson('/api/admin/recommended-products', ['product_id' => $product->id])->assertStatus($expected);
            $this->assertDatabaseCount('recommended_products', 0);
        } finally { RecommendedProduct::flushEventListeners(); }
    }
    public function test_service_rechecks_product_existence_after_validation(): void
    {
        $product = $this->product(); $id = $product->id; $product->delete();
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        app(AdminRecommendedProductService::class)->create($id);
    }
    public function test_remove_uses_relation_id_only_and_preserves_catalog_member_cart(): void
    {
        $member = User::factory()->create(); $this->actingAs($member, 'web'); $this->actingAs(Admin::factory()->create(), 'admin');
        $this->product(); $product = $this->product(); $row = $this->relation($product); $before = $product->fresh()->getAttributes();
        $member->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $this->assertNotSame($product->id, $row->id);
        $this->deleteJson('/api/admin/recommended-products/'.$row->id)->assertExactJson(['message' => '推薦商品已移除。']);
        $this->assertDatabaseMissing('recommended_products', ['id' => $row->id]); $this->assertSame($before, $product->fresh()->getAttributes());
        $this->assertAuthenticatedAs($member, 'web'); $this->assertDatabaseCount('cart_items', 1);
    }
    public function test_remove_does_not_treat_product_id_as_relation_id_and_requires_numeric_id(): void
    {
        $this->login(); $this->product(); $product = $this->product(); $row = $this->relation($product);
        $this->assertNotSame($product->id, $row->id);
        $this->deleteJson('/api/admin/recommended-products/'.$product->id)->assertNotFound();
        $this->assertDatabaseHas('recommended_products', ['id' => $row->id]);
        $this->deleteJson('/api/admin/recommended-products/nonnumeric')->assertNotFound();
    }
    public function test_reorder_complete_relation_ids_and_public_subset_order(): void
    {
        $this->login(); $this->product(); $a = $this->relation(); $b = $this->relation($this->product(['status' => 'inactive'])); $c = $this->relation();
        $ids = [$c->id, $b->id, $a->id];
        $this->patchJson('/api/admin/recommended-products/order', ['ids' => $ids])->assertExactJson(['message' => '推薦商品順序更新成功。']);
        $this->assertSame($ids, array_column($this->getJson('/api/admin/recommended-products')->json('data'), 'id'));
        $this->assertSame([$c->product_id, $a->product_id], array_column($this->getJson('/api/recommended-products')->json('data'), 'id'));
        $this->assertSame([0, 1, 2], RecommendedProduct::orderBy('sort_order')->pluck('sort_order')->all());
        foreach ([[], [$a->id], [$a->id, $a->id, $c->id], [$a->id, $b->id, 999], ['x'], [null], null] as $bad) {
            $this->patchJson('/api/admin/recommended-products/order', ['ids' => $bad])->assertUnprocessable();
        }
        foreach ([[], ['ids' => $ids, 'extra' => 3], ['ids' => $ids, 'ids.*' => 3]] as $bad) $this->patchJson('/api/admin/recommended-products/order', $bad)->assertUnprocessable();
        $this->relation(); $this->patchJson('/api/admin/recommended-products/order', ['ids' => $ids])->assertUnprocessable()->assertJsonValidationErrors('ids');
    }
    public function test_empty_reorder_is_noop(): void
    {
        $this->login(); $this->patchJson('/api/admin/recommended-products/order', ['ids' => []])->assertOk();
    }
    public function test_creating_cancellation_is_internal_failure(): void
    {
        $this->login(); $product = $this->product(); RecommendedProduct::creating(fn () => false);
        try { $this->postJson('/api/admin/recommended-products', ['product_id' => $product->id])->assertStatus(500); $this->assertDatabaseCount('recommended_products', 0); }
        finally { RecommendedProduct::flushEventListeners(); }
    }
    public function test_deleting_cancellation_retains_relation(): void
    {
        $this->login(); $row = $this->relation(); RecommendedProduct::deleting(fn () => false);
        try { $this->deleteJson('/api/admin/recommended-products/'.$row->id)->assertStatus(500); $this->assertDatabaseHas('recommended_products', ['id' => $row->id]); }
        finally { RecommendedProduct::flushEventListeners(); }
    }
    public static function failures(): array { return [[true], [false]]; }
    #[DataProvider('failures')]
    public function test_late_reorder_exception_or_cancellation_rolls_back_prior_sql(bool $cancel): void
    {
        $this->login(); $a = $this->relation(null, ['sort_order' => 5]); $b = $this->relation(null, ['sort_order' => 6]); $observed = false;
        $callback = function ($row) use ($a, $b, &$observed, $cancel) {
            if ($row->id !== $a->id) return;
            $observed = RecommendedProduct::findOrFail($b->id)->sort_order === 0;
            if ($cancel) return false;
            throw new RuntimeException('late write failure');
        };
        if ($cancel) RecommendedProduct::updating($callback); else RecommendedProduct::updated($callback);
        try {
            $this->patchJson('/api/admin/recommended-products/order', ['ids' => [$b->id, $a->id]])->assertStatus(500);
            $this->assertTrue($observed); $this->assertSame(5, $a->fresh()->sort_order); $this->assertSame(6, $b->fresh()->sort_order);
        } finally { RecommendedProduct::flushEventListeners(); }
    }
    public function test_safe_product_delete_cascades_recommendation_and_history_block_keeps_it(): void
    {
        $plain = $this->product(); $row = $this->relation($plain);
        app(AdminProductService::class)->delete($plain->id);
        $this->assertDatabaseMissing('products', ['id' => $plain->id]); $this->assertDatabaseMissing('recommended_products', ['id' => $row->id]);
        $product = $this->product(); $relation = $this->relation($product); $user = User::factory()->create();
        $order = $user->orders()->create(['order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '測試', 'purchaser_phone' => '0912345678', 'purchaser_email' => 'fixture@example.test',
            'recipient_name' => '測試', 'recipient_phone' => '0912345678', 'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => 'fixture',
            'shipping_method' => 'home_delivery', 'shipping_fee' => 100, 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'cancelled', 'subtotal' => 123, 'total_amount' => 223]);
        $item = $order->items()->create(['product_id' => $product->id, 'product_code_snapshot' => $product->product_code, 'product_name_snapshot' => '歷史', 'unit_price' => 123, 'quantity' => 1, 'subtotal' => 123]);
        try { app(AdminProductService::class)->delete($product->id); $this->fail('Expected history block'); }
        catch (ValidationException $exception) { $this->assertArrayHasKey('product', $exception->errors()); }
        $this->assertDatabaseHas('products', ['id' => $product->id]); $this->assertDatabaseHas('order_items', ['id' => $item->id]);
        $this->assertDatabaseHas('recommended_products', ['id' => $relation->id]);
    }
    public function test_admin_and_public_query_counts_are_fixed_with_one_and_ten_relations(): void
    {
        $this->login(); $this->relation();
        $counts = [];
        foreach ([1, 10] as $amount) {
            while (RecommendedProduct::count() < $amount) $this->relation();
            foreach (['admin' => '/api/admin/recommended-products', 'public' => '/api/recommended-products'] as $kind => $url) {
                DB::enableQueryLog(); DB::flushQueryLog(); $this->getJson($url)->assertOk()->assertJsonCount($amount, 'data');
                $queries = DB::getQueryLog(); $counts[$kind][] = count($queries); DB::disableQueryLog();
                $sql = implode(' ', array_column($queries, 'query')); $this->assertStringNotContainsString('specifications', $sql);
                $this->assertStringNotContainsString('order_items', $sql); $this->assertStringNotContainsString('cart_items', $sql);
                if ($kind === 'admin') $this->assertStringContainsString('exists', $sql);
            }
        }
        $this->assertSame($counts['admin'][0], $counts['admin'][1]); $this->assertSame($counts['public'][0], $counts['public'][1]);
        $this->assertSame(5, $counts['admin'][0]); $this->assertSame(3, $counts['public'][0]);
    }
    public function test_only_four_protected_routes_no_selector_status_or_patch_item(): void
    {
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/admin/recommended-products'));
        $this->assertCount(4, $routes);
        foreach ($routes as $route) $this->assertSame(['api', 'auth:admin', 'admin.active'], $route->gatherMiddleware());
        $this->login(); $this->patchJson('/api/admin/recommended-products/1/status', ['status' => 'active'])->assertNotFound();
        $this->getJson('/api/admin/product-selector')->assertNotFound();
    }
}
