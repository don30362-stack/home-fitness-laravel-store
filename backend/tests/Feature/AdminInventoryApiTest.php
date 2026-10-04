<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AdminInventoryService;
use App\Services\CheckoutService;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminInventoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function product(array $data = []): Product
    {
        $parent = Category::firstOrCreate(['name' => '庫存主分類'], ['status' => 'active']);
        $child = Category::firstOrCreate(['name' => '庫存子分類'], ['parent_id' => $parent->id, 'status' => 'active']);

        return Product::factory()->create(array_merge(['category_id' => $child->id, 'stock' => 10, 'low_stock_threshold' => 5], $data));
    }

    private function variant(Product $p, array $data = []): ProductVariant
    {
        return $p->variants()->create(array_merge(['option_name' => '顏色', 'option_value' => '黑色', 'stock' => 10, 'status' => 'active'], $data));
    }

    private function login(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    public static function endpoints(): array
    {
        return [['list'], ['product'], ['variant']];
    }

    #[DataProvider('endpoints')]
    public function test_identity_boundaries_and_disabled_admin(string $type): void
    {
        $p = $this->product();
        $v = $this->variant($this->product(['stock' => null]));
        $url = '/api/admin/inventory'.($type === 'list' ? '' : ($type === 'product' ? '/'.$p->id : '/variants/'.$v->id));
        $request = fn () => $type === 'list' ? $this->getJson($url) : $this->patchJson($url, ['adjustment' => 1]);
        $request()->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $request()->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_admin_preserves_member_identity(string $type): void
    {
        $p = $this->product();
        $v = $this->variant($this->product(['stock' => null]));
        $url = '/api/admin/inventory'.($type === 'list' ? '' : ($type === 'product' ? '/'.$p->id : '/variants/'.$v->id));
        $request = fn () => $type === 'list' ? $this->getJson($url) : $this->patchJson($url, ['adjustment' => 1]);
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $request()->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public function test_admin_inventory_bypasses_member_c06_with_actual_dual_session(): void
    {
        $user = User::factory()->create(['password' => 'test-member']);
        $admin = Admin::factory()->create(['password' => 'test-admin']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin'])->assertOk();
        $this->nextRequest();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        $p = $this->product();
        $this->getJson('/api/admin/inventory')->assertOk();
        $this->nextRequest();
        $this->patchJson('/api/admin/inventory/'.$p->id, ['adjustment' => 1])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAuthenticated('admin');
    }

    private function nextRequest(): void
    {
        $session = app('session')->driver();
        $session->save();
        $this->withCookie($session->getName(), $session->getId());
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    public function test_list_is_one_row_per_owner_including_all_sale_statuses_and_contract(): void
    {
        $this->login();
        foreach (['active', 'inactive', 'disabled'] as $status) {
            $this->product(['status' => $status]);
            $p = $this->product(['stock' => null, 'status' => $status]);
            $this->variant($p);
            $this->variant($p, ['option_value' => '白色', 'status' => 'inactive']);
        }
        $r = $this->getJson('/api/admin/inventory')->assertOk()->assertJsonCount(9, 'data')
            ->assertJsonStructure(['data', 'links', 'meta'])->assertJsonPath('meta.per_page', 10);
        $this->assertCount(3, array_filter($r->json('data'), fn ($row) => $row['stock_owner_type'] === 'product'));
        $this->assertCount(6, array_filter($r->json('data'), fn ($row) => $row['stock_owner_type'] === 'variant'));
        $this->assertEqualsCanonicalizing(['stock_owner_type', 'stock_owner_id', 'product_id', 'product_code', 'product_name', 'product_status', 'category', 'variant', 'stock', 'low_stock_threshold', 'inventory_status'], array_keys($r->json('data.0')));
        $r->assertJsonMissingPath('success')->assertJsonMissingPath('data.0.description');
        $this->assertCount(3, array_filter($r->json('data'), fn ($row) => $row['variant'] && $row['variant']['status'] === 'inactive'));
        $this->assertSame('庫存子分類', $r->json('data.0.category.name'));
    }

    public static function thresholds(): array
    {
        return [[0, 5, 'out_of_stock'], [1, 5, 'low_stock'], [5, 5, 'low_stock'], [6, 5, 'normal'], [0, 0, 'out_of_stock'], [1, 0, 'normal']];
    }

    #[DataProvider('thresholds')]
    public function test_threshold_boundaries_for_both_owners(int $stock, int $threshold, string $expected): void
    {
        $this->login();
        $this->product(['stock' => $stock, 'low_stock_threshold' => $threshold, 'status' => 'inactive']);
        $p = $this->product(['stock' => null, 'low_stock_threshold' => $threshold, 'status' => 'disabled']);
        $this->variant($p, ['stock' => $stock, 'status' => 'inactive']);
        $r = $this->getJson('/api/admin/inventory')->assertOk()->assertJsonCount(2, 'data');
        foreach ($r->json('data') as $row) {
            $this->assertSame($expected, $row['inventory_status']);
            $this->assertSame($threshold, $row['low_stock_threshold']);
        }
        $this->getJson('/api/admin/inventory?inventory_status='.$expected)->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_search_category_status_combination_and_empty(): void
    {
        $this->login();
        $wanted = $this->product(['name' => '搜尋專用啞鈴', 'product_code' => 'PRD-SEARCH', 'stock' => 2]);
        $otherCategory = Category::create(['name' => '其他子分類', 'parent_id' => $wanted->category->parent_id]);
        $this->product(['name' => '搜尋專用啞鈴', 'category_id' => $otherCategory->id, 'stock' => 0]);
        $this->product(['stock' => 20]);
        foreach (['搜尋專用', 'PRD-SEARCH'] as $search) {
            $this->getJson('/api/admin/inventory?'.http_build_query(['search' => $search, 'category_id' => $wanted->category_id, 'inventory_status' => 'low_stock']))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_id', $wanted->id);
        }
        $this->getJson('/api/admin/inventory?inventory_status=normal')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/inventory?inventory_status=out_of_stock')->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/inventory?search=not-present')->assertJsonCount(0, 'data');
    }

    public function test_sql_pagination_and_deterministic_product_variant_order_without_n_plus_one(): void
    {
        $this->login();
        $expected = [];
        $old = $this->product(['created_at' => '2025-01-01']);
        $plain = $this->product(['created_at' => '2026-01-01']);
        $parent = $this->product(['stock' => null, 'created_at' => '2026-01-01']);
        for ($i = 0; $i < 11; $i++) {
            $expected[] = ['variant', $this->variant($parent, ['option_value' => (string) $i])->id];
        }
        $expected[] = ['product', $plain->id];
        $expected[] = ['product', $old->id];
        $connection = DB::connection();
        $connection->enableQueryLog();
        try {
            $first = $this->getJson('/api/admin/inventory?per_page=99')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 13);
            $q1 = count($connection->getQueryLog());
            $connection->flushQueryLog();
            $second = $this->getJson('/api/admin/inventory?page=2')->assertOk()->assertJsonCount(3, 'data');
            $this->assertSame($q1, count($connection->getQueryLog()));
        } finally {
            $connection->disableQueryLog();
        }
        $actual = array_map(fn ($row) => [$row['stock_owner_type'], $row['stock_owner_id']], array_merge($first->json('data'), $second->json('data')));
        $this->assertSame($expected, $actual);
    }

    public static function invalidQueries(): array
    {
        return [['inventory_status=invalid'], ['inventory_status[]=normal'], ['category_id=99999'], ['category_id=parent'], ['search[]=x'], ['page=0'], ['page[]=1']];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_is_422(string $query): void
    {
        $this->login();
        $p = $this->product();
        if ($query === 'category_id=parent') {
            $query = 'category_id='.$p->category->parent_id;
        }
        $this->getJson('/api/admin/inventory?'.$query)->assertUnprocessable();
    }

    public static function adjustments(): array
    {
        return [[5, 15], [-3, 7], [-10, 0]];
    }

    #[DataProvider('adjustments')]
    public function test_adjustment_on_plain_and_variant_preserves_other_state(int $delta, int $expected): void
    {
        $this->login();
        $p = $this->product(['status' => 'disabled']);
        $parent = $this->product(['stock' => null, 'status' => 'inactive']);
        $v = $this->variant($parent, ['status' => 'inactive']);
        $other = $this->variant($parent, ['option_value' => '白色']);
        foreach ([['product', $p, '/'.$p->id], ['variant', $v, '/variants/'.$v->id]] as [$type, $owner, $suffix]) {
            $this->patchJson('/api/admin/inventory'.$suffix, ['adjustment' => $delta])->assertOk()
                ->assertJsonPath('data.stock', $expected)->assertJsonPath('data.stock_owner_type', $type)
                ->assertJsonPath('data.inventory_status', $expected === 0 ? 'out_of_stock' : 'normal')->assertJsonMissingPath('success');
            $this->assertSame($expected, (int) $owner->fresh()->stock);
        }
        $this->assertSame('disabled', $p->fresh()->status);
        $this->assertSame(5, (int) $p->fresh()->low_stock_threshold);
        $this->assertNull($parent->fresh()->stock);
        $this->assertSame('inactive', $parent->fresh()->status);
        $this->assertSame('inactive', $v->fresh()->status);
        $this->assertSame(10, (int) $other->fresh()->stock);
    }

    public static function invalidAdjustments(): array
    {
        return [[[]], [['adjustment' => 0]], [['adjustment' => 1.5]], [['adjustment' => 'bad']], [['adjustment' => [1]]], [['adjustment' => -11]], [['adjustment' => 4294967295]], [['adjustment' => 4294967296]], [['adjustment' => 1, 'stock' => 0]], [['new_stock' => 20]], [['quantity' => 1]]];
    }

    #[DataProvider('invalidAdjustments')]
    public function test_invalid_adjustment_does_not_write_either_owner(array $payload): void
    {
        $this->login();
        $p = $this->product();
        $v = $this->variant($this->product(['stock' => null]));
        foreach ([$p, $v] as $owner) {
            $url = '/api/admin/inventory'.($owner instanceof ProductVariant ? '/variants/' : '/').$owner->id;
            $this->patchJson($url, $payload)->assertUnprocessable();
            $this->assertSame(10, (int) $owner->fresh()->stock);
        }
    }

    public function test_uint_max_and_integer_form_string(): void
    {
        $this->login();
        $p = $this->product(['stock' => 0]);
        $this->patchJson('/api/admin/inventory/'.$p->id, ['adjustment' => '4294967295'])->assertOk()->assertJsonPath('data.stock', 4294967295);
        $this->patchJson('/api/admin/inventory/'.$p->id, ['adjustment' => 1])->assertUnprocessable();
    }

    public function test_missing_numeric_constraints_and_invalid_modes(): void
    {
        $this->login();
        foreach (['/99999', '/variants/99999', '/bad', '/variants/bad'] as $suffix) {
            $this->patchJson('/api/admin/inventory'.$suffix, ['adjustment' => 1])->assertNotFound();
        }
        $parent = $this->product(['stock' => null]);
        $v = $this->variant($parent);
        $this->patchJson('/api/admin/inventory/'.$parent->id, ['adjustment' => 1])->assertUnprocessable()->assertJsonValidationErrors('adjustment');
        $invalid = $this->product(['stock' => null]);
        $this->patchJson('/api/admin/inventory/'.$invalid->id, ['adjustment' => 1])->assertUnprocessable();
        $parent->update(['stock' => 10]); // isolated legacy-invalid fixture, never convert production data
        $this->patchJson('/api/admin/inventory/variants/'.$v->id, ['adjustment' => 1])->assertUnprocessable();
    }

    public static function ownerTypes(): array
    {
        return [['product'], ['variant']];
    }

    #[DataProvider('ownerTypes')]
    public function test_failure_after_stock_save_rolls_back(string $type): void
    {
        $this->login();
        $p = $this->product(['stock' => $type === 'product' ? 10 : null]);
        $owner = $type === 'product' ? $p : $this->variant($p);
        $class = $owner::class;
        $class::updated(fn () => throw new RuntimeException('isolated after-save failure'));
        try {
            $this->patchJson('/api/admin/inventory'.($type === 'product' ? '/' : '/variants/').$owner->id, ['adjustment' => 5])->assertStatus(500);
            $this->assertSame(10, (int) $owner->fresh()->stock);
        } finally {
            $class::flushEventListeners();
        }
    }

    public function test_variant_service_lock_query_order_is_parent_then_variant(): void
    {
        $p = $this->product(['stock' => null]);
        $v = $this->variant($p);
        $connection = DB::connection();
        $connection->enableQueryLog();
        try {
            app(AdminInventoryService::class)->adjustVariant($v->id, 1);
            $queries = array_column($connection->getQueryLog(), 'query');
            $parent = array_search(true, array_map(fn ($sql) => str_starts_with($sql, 'select * from "products"'), $queries));
            $variants = array_keys(array_filter($queries, fn ($sql) => str_starts_with($sql, 'select * from "product_variants"')));
            $this->assertNotFalse($parent);
            $this->assertCount(2, $variants);
            $this->assertLessThan($variants[1], $parent); // first variant query only resolves parent ID before transaction
        } finally {
            $connection->disableQueryLog();
        }
    }

    public function test_inventory_adjust_checkout_cancel_integration(): void
    {
        $p = $this->product();
        $parent = $this->product(['stock' => null]);
        $v = $this->variant($parent);
        $service = app(AdminInventoryService::class);
        $service->adjustProduct($p->id, 5);
        $service->adjustVariant($v->id, -3);
        $user = User::factory()->create();
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $p->id, 'quantity' => 2]);
        $cart->items()->create(['product_id' => $parent->id, 'product_variant_id' => $v->id, 'quantity' => 3]);
        $district = City::create(['name' => '臺北市'])->districts()->create(['name' => '中正區', 'postal_code' => '100']);
        $order = app(CheckoutService::class)->checkout($user, [
            'purchaser' => ['name' => '測試', 'phone' => '0912345678', 'email' => 'fixture@example.test'],
            'recipient' => ['name' => '測試', 'phone' => '0912345678', 'district_id' => $district->id, 'address' => '隔離地址'],
            'shipping_method' => 'home_delivery', 'payment_method' => 'cod',
        ]);
        $this->assertSame(13, (int) $p->fresh()->stock);
        $this->assertSame(4, (int) $v->fresh()->stock);
        app(OrderCancellationService::class)->cancel($order->id);
        $this->assertSame(15, (int) $p->fresh()->stock);
        $this->assertSame(7, (int) $v->fresh()->stock);
        $this->assertNull($parent->fresh()->stock);
    }
}
