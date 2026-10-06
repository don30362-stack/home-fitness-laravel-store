<?php

namespace Tests\Feature;

use App\Http\Resources\DashboardResource;
use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\DashboardService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        $this->seed(PermissionSeeder::class);
    }

    private function admin(array $codes = []): Admin
    {
        $admin = Admin::factory()->create();
        $admin->permissions()->attach(Permission::whereIn('code', $codes)->pluck('id'));
        return $admin;
    }

    private function order(array $attributes = [], ?User $user = null): Order
    {
        return Order::query()->forceCreate(array_merge([
            'order_no' => 'HF-'.Str::ulid(), 'user_id' => ($user ?? User::factory()->create())->id,
            'purchaser_name' => 'PRIVATE_PURCHASER', 'purchaser_phone' => 'PRIVATE_PURCHASER_PHONE',
            'purchaser_email' => 'private-purchaser@example.test', 'recipient_name' => 'PRIVATE_RECIPIENT',
            'recipient_phone' => 'PRIVATE_PHONE', 'postal_code' => '100', 'city' => '臺北市',
            'district' => '中正區', 'address' => 'PRIVATE_ADDRESS', 'shipping_method' => 'home_delivery',
            'shipping_fee' => '100.00', 'subtotal' => '900.00', 'total_amount' => '1000.00',
            'payment_method' => 'mock_credit_card', 'payment_status' => 'unpaid', 'order_status' => 'pending',
        ], $attributes));
    }

    private function product(array $attributes = []): Product
    {
        $parent = Category::create(['name' => '停用主分類', 'status' => 'inactive']);
        $child = Category::create(['name' => '停用子分類', 'parent_id' => $parent->id, 'status' => 'inactive']);
        return Product::factory()->create(array_merge(['category_id' => $child->id], $attributes));
    }

    private function recorded(callable $work): array
    {
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $result = $work();
            return [$result, $connection->getQueryLog()];
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function businessQueries(array $queries): array
    {
        return array_values(array_filter($queries, fn ($q) =>
            preg_match('/\bfrom\s+["`]?\b(products|users|orders)\b/i', $q['query'])));
    }

    public function test_guest_and_member_cannot_access_dashboard(): void
    {
        $this->get('/api/admin/dashboard')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->actingAs(User::factory()->create(), 'web')->getJson('/api/admin/dashboard')->assertUnauthorized();
    }

    public function test_disabled_admin_is_rejected_without_revoking_member(): void
    {
        $member = User::factory()->create();
        $this->actingAs($member, 'web')->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->getJson('/api/admin/dashboard')->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($member, 'web');
    }

    public function test_active_admin_does_not_trigger_disabled_member_c06_or_clear_cart(): void
    {
        $member = User::factory()->create(['status' => 'disabled']);
        $cart = $member->cart()->create();
        $product = $this->product();
        $item = $cart->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $admin = $this->admin(['member_manage']);
        $this->actingAs($member, 'web')->actingAs($admin, 'admin')->withSession(['cart_marker' => 'keep']);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.members.total', 1)
            ->assertSessionHas('cart_marker', 'keep');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 1]);
    }

    public static function grants(): array
    {
        return [
            'empty' => [[]], 'category' => [['category_manage']], 'inventory' => [['inventory_manage']],
            'home' => [['home_content_manage']], 'admin' => [['admin_manage']],
            'product' => [['product_manage']], 'member' => [['member_manage']], 'order' => [['order_manage']],
            'product-member' => [['product_manage', 'member_manage']],
            'product-order' => [['product_manage', 'order_manage']],
            'member-order' => [['member_manage', 'order_manage']],
            'three' => [['product_manage', 'member_manage', 'order_manage']],
            'owner' => [array_keys(Permission::CATALOG)],
        ];
    }

    #[DataProvider('grants')]
    public function test_exact_visibility_and_unauthorized_query_suppression(array $codes): void
    {
        $this->product();
        $this->order();
        $this->actingAs($this->admin($codes), 'admin');
        [$response, $queries] = $this->recorded(fn () => $this->getJson('/api/admin/dashboard'));
        $response->assertOk();
        $this->assertSame(['data'], array_keys($response->json()));
        $this->assertSame(['products', 'members', 'orders'], array_keys($response->json('data')));
        foreach (['products' => 'product_manage', 'members' => 'member_manage', 'orders' => 'order_manage'] as $section => $code) {
            $allowed = in_array($code, $codes, true);
            $data = $response->json('data.'.$section);
            if ($allowed) {
                $this->assertIsArray($data);
                $this->assertIsInt($data['total']);
                $this->assertSame(1, $data['total']);
                $this->assertSame($section === 'orders'
                    ? ['total', 'pending', 'awaiting_shipment', 'completed_order_amount', 'recent_orders'] : ['total'], array_keys($data));
            } else {
                $this->assertNull($data);
            }
            $table = $section === 'members' ? 'users' : $section;
            $matching = array_filter($this->businessQueries($queries), fn ($q) => str_contains($q['query'], 'from "'.$table.'"'));
            $this->assertCount($allowed ? ($section === 'orders' ? 2 : 1) : 0, $matching);
        }
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|for update)\b/i', $query['query']);
        }
    }

    public function test_zero_data_returns_objects_not_null_and_amount_is_string(): void
    {
        $this->actingAs($this->admin(array_keys(Permission::CATALOG)), 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertExactJson(['data' => [
            'products' => ['total' => 0], 'members' => ['total' => 0], 'orders' => [
                'total' => 0, 'pending' => 0, 'awaiting_shipment' => 0,
                'completed_order_amount' => '0.00', 'recent_orders' => [],
            ],
        ]]);
    }

    public function test_product_counts_all_rows_independent_of_categories_status_and_variants(): void
    {
        foreach (['active', 'inactive', 'disabled'] as $status) $this->product(['status' => $status]);
        $product = $this->product(['stock' => null]);
        foreach (['黑色', '白色', '灰色'] as $value) $product->variants()->create([
            'option_name' => '顏色', 'option_value' => $value, 'stock' => 3, 'status' => 'active',
        ]);
        $this->actingAs($this->admin(['product_manage']), 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.products.total', 4);
    }

    public function test_members_include_disabled_and_legacy_inactive(): void
    {
        foreach (['active', 'disabled', 'inactive'] as $status) User::factory()->create(['status' => $status]);
        $this->actingAs($this->admin(['member_manage']), 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.members.total', 3);
    }

    public function test_order_matrix_counts_cancelled_unknown_and_completed_unpaid_with_shipping(): void
    {
        foreach (['pending', 'processing', 'shipped', 'legacy_unknown'] as $status) $this->order(['order_status' => $status]);
        $this->order(['order_status' => 'completed', 'payment_status' => 'paid']);
        $this->order(['order_status' => 'completed', 'payment_status' => 'unpaid', 'subtotal' => '20.10', 'total_amount' => '120.10']);
        $this->order(['order_status' => 'cancelled', 'payment_status' => 'paid', 'total_amount' => '99000.00']);
        $this->actingAs($this->admin(['order_manage']), 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.orders.total', 7)
            ->assertJsonPath('data.orders.pending', 1)->assertJsonPath('data.orders.awaiting_shipment', 1)
            ->assertJsonPath('data.orders.completed_order_amount', '1120.10');
    }

    public function test_sqlite_decimal_sum_is_normalized_to_two_places_over_all_time(): void
    {
        $this->order(['order_status' => 'completed', 'total_amount' => '0.10', 'created_at' => '2000-01-01 00:00:00']);
        $this->order(['order_status' => 'completed', 'total_amount' => '0.20', 'created_at' => '2026-10-06 00:00:00']);
        $this->actingAs($this->admin(['order_manage']), 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.orders.completed_order_amount', '0.30');
        $this->assertSame('sqlite', DB::connection()->getDriverName());
    }

    public function test_recent_five_use_created_time_then_id_and_expose_only_six_fields(): void
    {
        $user = User::factory()->create(['name' => 'PRIVATE_USER', 'email' => 'private-user@example.test']);
        $product = $this->product();
        $orders = [];
        foreach (range(1, 7) as $i) {
            $order = $this->order(['created_at' => $i < 3 ? '2020-01-01 00:00:00' : '2026-10-06 00:00:00',
                'order_status' => $i === 7 ? 'cancelled' : 'completed', 'total_amount' => '123.45'], $user);
            $orders[] = $order;
        }
        $orders[6]->items()->create(['product_id' => $product->id, 'product_code_snapshot' => 'PRIVATE_ITEM_CODE',
            'product_name_snapshot' => 'PRIVATE_ITEM_NAME', 'variant_snapshot' => null,
            'unit_price' => '23.45', 'quantity' => 1, 'subtotal' => '23.45']);
        $this->actingAs($this->admin(['order_manage']), 'admin');
        $response = $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonCount(5, 'data.orders.recent_orders');
        $this->assertSame(collect($orders)->slice(2)->reverse()->pluck('id')->all(), array_column($response->json('data.orders.recent_orders'), 'id'));
        foreach ($response->json('data.orders.recent_orders') as $row) {
            $this->assertSame(['id', 'order_no', 'created_at', 'total_amount', 'order_status', 'payment_status'], array_keys($row));
            $this->assertSame('123.45', $row['total_amount']);
            $this->assertSame('2026-10-06T00:00:00.000000Z', $row['created_at']);
        }
        $this->assertSame('cancelled', $response->json('data.orders.recent_orders.0.order_status'));
        foreach (['PRIVATE_', 'private-user@example.test', 'private-purchaser@example.test', 'mock_credit_card'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
    }

    public function test_same_session_sees_database_grant_and_revoke_without_login(): void
    {
        $admin = $this->admin();
        $admin->load('permissions');
        $this->actingAs($admin, 'admin');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.products', null);
        $id = Permission::where('code', 'product_manage')->value('id');
        $admin->permissions()->attach($id);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.products.total', 0);
        $this->assertSame(0, $admin->permissions->count()); // deliberately stale loaded relation
        $admin->permissions()->detach($id);
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.products', null);
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_real_login_session_sees_grants_without_relogin(): void
    {
        $admin = $this->admin();
        $admin->password = 'dashboard-test-only';
        $admin->save();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'dashboard-test-only'])->assertOk();
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.orders', null);
        $id = Permission::where('code', 'order_manage')->value('id');
        $admin->permissions()->attach($id);
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.orders.total', 0);
        $admin->permissions()->detach($id);
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->getJson('/api/admin/dashboard')->assertOk()->assertJsonPath('data.orders', null);
    }

    public function test_unknown_permission_is_not_an_implicit_dashboard_grant(): void
    {
        $admin = $this->admin();
        $permission = Permission::create(['code' => 'future_module', 'name' => 'Future']);
        $admin->permissions()->attach($permission->id);
        $this->actingAs($admin, 'admin')->getJson('/api/admin/dashboard')->assertOk()
            ->assertExactJson(['data' => ['products' => null, 'members' => null, 'orders' => null]]);
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $admin->id, 'permission_id' => $permission->id]);
    }

    public function test_service_queries_stay_fixed_and_resource_serialization_has_zero_queries(): void
    {
        $admin = $this->admin(['product_manage', 'member_manage', 'order_manage']);
        $service = app(DashboardService::class);
        $transactionLevel = DB::connection()->transactionLevel(); // RefreshDatabase owns the outer test transaction.
        [, $small] = $this->recorded(fn () => $service->summary($admin));
        $user = User::factory()->create();
        $product = $this->product();
        Product::factory()->count(20)->create(['category_id' => $product->category_id]);
        foreach (range(1, 25) as $i) $this->order([], $user);
        [$data, $large] = $this->recorded(fn () => $service->summary($admin));
        $this->assertCount(5, $small);
        $this->assertCount(count($small), $large);
        $this->assertCount(4, $this->businessQueries($large));
        $this->assertCount(5, $data['orders']['recent_orders']);
        foreach ($data['orders']['recent_orders'] as $order) {
            $this->assertSame([], $order->getRelations());
            $this->assertSame(['id', 'order_no', 'created_at', 'total_amount', 'order_status', 'payment_status'], array_keys($order->getAttributes()));
        }
        [$json, $queries] = $this->recorded(fn () => (new DashboardResource($data))->resolve(Request::create('/api/admin/dashboard')));
        $this->assertSame([], $queries);
        $this->assertSame(25, $json['orders']['total']);
        $this->assertSame($transactionLevel, DB::connection()->transactionLevel());
        foreach ($large as $query) $this->assertStringNotContainsString('for update', strtolower($query['query']));
    }

    public function test_read_does_not_change_business_rows_or_timestamps(): void
    {
        $product = $this->product();
        $this->order();
        $this->actingAs($this->admin(['product_manage', 'member_manage', 'order_manage']), 'admin');
        $before = [];
        foreach (['products', 'users', 'orders'] as $table) $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        $this->getJson('/api/admin/dashboard')->assertOk();
        foreach ($before as $table => $rows) $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->toArray());
        $this->assertSame($product->updated_at->toISOString(), $product->fresh()->updated_at->toISOString());
    }

    public function test_internal_query_failure_is_not_swallowed_as_zero_or_null(): void
    {
        $admin = $this->admin(['order_manage']);
        $armed = true;
        DB::listen(function ($query) use (&$armed): void {
            if ($armed && str_contains($query->sql, 'from "orders"')) {
                $armed = false;
                throw new RuntimeException('Dashboard query failure fixture');
            }
        });
        $this->withoutExceptionHandling()->actingAs($admin, 'admin');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Dashboard query failure fixture');
        $this->getJson('/api/admin/dashboard');
    }
}
