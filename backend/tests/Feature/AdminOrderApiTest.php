<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminOrderApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function order(?User $user = null, array $attributes = []): Order
    {
        return Order::query()->forceCreate(array_merge([
            'user_id' => ($user ?? User::factory()->create())->id,
            'order_no' => 'HF-'.Str::ulid(),
            'purchaser_name' => '歷史訂購人', 'purchaser_phone' => '0912345678', 'purchaser_email' => 'snapshot@example.test',
            'recipient_name' => '歷史收件人', 'recipient_phone' => '0987654321',
            'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '歷史地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => '100.00',
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'pending',
            'subtotal' => '1200.00', 'total_amount' => '1300.00',
        ], $attributes));
    }

    private function login(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    public static function endpoints(): array
    {
        return [['/api/admin/orders'], ['/api/admin/orders/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_and_member_only_are_rejected_with_json_even_without_accept(string $url): void
    {
        $this->get($url)->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->actingAs(User::factory()->create(), 'web')->getJson($url)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_admin_only_revokes_admin_identity(string $url): void
    {
        $member = User::factory()->create();
        $this->actingAs($member, 'web')->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->getJson($url)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertGuest('admin');
    }

    public function test_real_dual_session_admin_reads_do_not_trigger_disabled_member_c06(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        $member = User::factory()->create(['password' => 'test-member-secret']);
        $admin = Admin::factory()->create(['password' => 'test-admin-secret']);
        $this->postJson('/api/login', ['email' => $member->email, 'password' => 'test-member-secret'])->assertOk();
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        Auth::forgetGuards(); Auth::shouldUse('web');
        DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']);
        $cart = $member->cart()->create();
        $order = $this->order($member);
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(1, 'data');
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('data.user.status', 'disabled');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_empty_and_summary_contract_for_multiple_members(): void
    {
        $this->login();
        $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.per_page', 10)
            ->assertJsonStructure(['data', 'links', 'meta']);
        $user = User::factory()->create(['name' => '目前會員', 'email' => 'current@example.test']);
        $order = $this->order($user);
        $this->order();
        $response = $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount(2, 'data');
        $row = collect($response->json('data'))->firstWhere('id', $order->id);
        $this->assertEqualsCanonicalizing(['id', 'order_no', 'created_at', 'total_amount', 'payment_method', 'payment_status', 'order_status', 'user'], array_keys($row));
        $this->assertSame(['id' => $user->id, 'name' => '目前會員', 'email' => 'current@example.test'], $row['user']);
        foreach (['purchaser', 'recipient', 'items', 'password', 'remember_token'] as $field) {
            $response->assertJsonMissingPath('data.0.'.$field);
        }
        $response->assertJsonMissingPath('success');
    }

    public function test_fixed_pagination_and_stable_created_at_id_order(): void
    {
        $this->login();
        $user = User::factory()->create();
        $ids = [];
        for ($i = 0; $i < 11; $i++) $ids[] = $this->order($user, ['created_at' => '2026-10-01 12:00:00'])->id;
        $old = $this->order($user, ['created_at' => '2026-09-01 12:00:00']);
        Order::findOrFail($ids[0])->forceFill(['created_at' => '2026-10-05 12:00:00'])->save();
        $expected = [$ids[0], ...array_reverse(array_slice($ids, 1)), $old->id];
        $first = $this->getJson('/api/admin/orders?per_page=99&sort=id')->assertOk()->assertJsonCount(10, 'data')
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.total', 12)->assertJsonPath('meta.last_page', 2);
        $this->assertSame(array_slice($expected, 0, 10), array_column($first->json('data'), 'id'));
        $second = $this->getJson('/api/admin/orders?page=2')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(array_slice($expected, 10), array_column($second->json('data'), 'id'));
    }

    public function test_search_uses_current_user_not_purchaser_snapshot_and_groups_or_filters(): void
    {
        $this->login();
        $user = User::factory()->create(['name' => '現在名字', 'email' => 'current@example.test']);
        $match = $this->order($user, ['order_no' => 'HF-SEARCH-001', 'payment_status' => 'paid']);
        $this->order($user, ['order_status' => 'cancelled', 'payment_status' => 'paid']);
        $this->order($user, ['created_at' => '2026-09-01 00:00:00', 'payment_status' => 'paid']);
        foreach (['HF-SEARCH-001', 'SEARCH', '現在', 'current@'] as $search) {
            $response = $this->getJson('/api/admin/orders?'.http_build_query([
                'search' => ' '.$search.' ', 'order_status' => 'pending', 'payment_status' => 'paid', 'date_from' => '2026-10-01',
            ]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $match->id);
            $this->assertStringContainsString('order_status=pending', $response->json('links.first'));
        }
        foreach (['歷史訂購人', 'snapshot@example.test', 'not-found'] as $search) {
            $this->getJson('/api/admin/orders?'.http_build_query(['search' => $search]))->assertOk()->assertJsonCount(0, 'data');
        }
    }

    public function test_all_status_and_payment_filters(): void
    {
        $this->login();
        foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $status) {
            foreach (['unpaid', 'paid'] as $payment) $this->order(null, ['order_status' => $status, 'payment_status' => $payment]);
        }
        foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $status) {
            $this->getJson('/api/admin/orders?order_status='.$status)->assertOk()->assertJsonCount(2, 'data');
            foreach (['unpaid', 'paid'] as $payment) {
                $this->getJson('/api/admin/orders?order_status='.$status.'&payment_status='.$payment)->assertOk()
                    ->assertJsonCount(1, 'data')->assertJsonPath('data.0.order_status', $status)->assertJsonPath('data.0.payment_status', $payment);
            }
        }
        foreach (['unpaid', 'paid'] as $payment) $this->getJson('/api/admin/orders?payment_status='.$payment)->assertOk()->assertJsonCount(5, 'data');
    }

    public function test_taipei_date_filters_use_inclusive_lower_and_exclusive_next_day_utc_boundary(): void
    {
        $this->login();
        $ids = [];
        foreach (['2026-10-04 15:59:59', '2026-10-04 16:00:00', '2026-10-05 04:00:00', '2026-10-05 15:59:59', '2026-10-05 16:00:00'] as $time) {
            $ids[] = $this->order(null, ['created_at' => $time])->id;
        }
        $this->getJson('/api/admin/orders?date_from=2026-10-05&date_to=2026-10-05')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame([$ids[3], $ids[2], $ids[1]], array_column($this->getJson('/api/admin/orders?date_from=2026-10-05&date_to=2026-10-05')->json('data'), 'id'));
        $this->getJson('/api/admin/orders?date_from=2026-10-05')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/admin/orders?date_to=2026-10-05')->assertOk()->assertJsonCount(4, 'data');
    }

    public static function invalidQueries(): array
    {
        return [
            [['search' => ['bad']], 'search'], [['order_status' => 'bad'], 'order_status'],
            [['order_status' => ['pending']], 'order_status'], [['payment_status' => 'refunded'], 'payment_status'],
            [['payment_status' => ['paid']], 'payment_status'], [['date_from' => '2026-02-30'], 'date_from'],
            [['date_to' => '2026/10/05'], 'date_to'], [['date_to' => ['2026-10-05']], 'date_to'],
            [['page' => 0], 'page'], [['page' => ['1']], 'page'],
        ];
    }

    #[DataProvider('invalidQueries')]
    public function test_invalid_query_uses_laravel_validation(array $query, string $field): void
    {
        $this->login();
        $this->getJson('/api/admin/orders?'.http_build_query($query))->assertUnprocessable()->assertJsonValidationErrors($field)->assertJsonMissingPath('success');
    }

    public function test_detail_404_and_numeric_constraint(): void
    {
        $this->login();
        foreach (['999999', 'abc', '-1', '1.5'] as $id) $this->getJson('/api/admin/orders/'.$id)->assertNotFound();
    }

    public function test_detail_reads_historical_snapshots_not_current_catalog_and_has_only_safe_member_identity(): void
    {
        $this->login();
        $user = User::factory()->create(['name' => '現在會員', 'email' => 'now@example.test']);
        $order = $this->order($user);
        $root = Category::query()->create(['name' => '主分類', 'status' => 'active']);
        $child = $root->children()->create(['name' => '子分類', 'status' => 'active']);
        $product = Product::factory()->create(['category_id' => $child->id, 'name' => '新商品名', 'price' => '9999.00']);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'option_name' => '顏色', 'option_value' => '白色', 'stock' => 0, 'status' => 'inactive']);
        foreach ([null, $variant->id] as $variantId) $order->items()->create([
            'product_id' => $product->id, 'product_variant_id' => $variantId,
            'product_code_snapshot' => 'OLD-CODE', 'product_name_snapshot' => '歷史商品名',
            'variant_snapshot' => $variantId ? '顏色：黑色' : null, 'unit_price' => '600.00', 'quantity' => 1, 'subtotal' => '600.00',
        ]);
        $connection = DB::connection(); $connection->enableQueryLog(); $connection->flushQueryLog();
        $result = $this->getJson('/api/admin/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.user.name', '現在會員')->assertJsonPath('data.user.status', 'active')
            ->assertJsonPath('data.purchaser.name', '歷史訂購人')->assertJsonPath('data.recipient.address', '歷史地址')
            ->assertJsonPath('data.items.0.product_name', '歷史商品名')->assertJsonPath('data.items.1.variant', '顏色：黑色')
            ->assertJsonPath('data.items.0.unit_price', '600.00')->assertJsonPath('data.total_amount', '1300.00')
            ->assertJsonPath('data.subtotal', '1200.00')->assertJsonPath('data.shipping_fee', '100.00')
            ->assertJsonPath('data.logistics_company', null)->assertJsonPath('data.tracking_number', null)
            ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.remember_token');
        $queries = array_column($connection->getQueryLog(), 'query'); $connection->disableQueryLog();
        $this->assertEqualsCanonicalizing(['id', 'name', 'email', 'status'], array_keys($result->json('data.user')));
        $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "order_items"')));
        foreach (['products', 'product_variants', 'categories', 'user_addresses'] as $table) {
            foreach ($queries as $sql) $this->assertStringNotContainsString('from "'.$table.'"', $sql);
        }
        $order->update(['logistics_company' => '物流公司', 'tracking_number' => 'TRACK-01']);
        $this->getJson('/api/admin/orders/'.$order->id)->assertOk()->assertJsonPath('data.logistics_company', '物流公司')->assertJsonPath('data.tracking_number', 'TRACK-01');
    }

    public function test_list_query_count_does_not_grow_with_order_count_and_does_not_load_items(): void
    {
        $this->login();
        $this->order();
        $connection = DB::connection(); $connection->enableQueryLog();
        $counts = [];
        foreach ([1, 10] as $size) {
            if ($size === 10) for ($i = 0; $i < 9; $i++) $this->order();
            $connection->flushQueryLog();
            $this->getJson('/api/admin/orders')->assertOk()->assertJsonCount($size, 'data');
            $queries = array_column($connection->getQueryLog(), 'query');
            $counts[] = count($queries);
            $this->assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'from "users"')));
            foreach ($queries as $sql) $this->assertStringNotContainsString('order_items', $sql);
        }
        $connection->disableQueryLog();
        $this->assertSame($counts[0], $counts[1]);
        $this->assertSame(4, $counts[0]); // admin.active + paginator count + orders + eager-loaded users.
    }

    public function test_stage21_routes_only_contain_reads_and_three_lifecycle_patches(): void
    {
        $paths = [];
        foreach (app('router')->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/admin/orders') || str_starts_with($route->uri(), 'api/admin/users')) {
                $paths[$route->uri()] = $route->methods();
            }
        }
        $this->assertSame([
            'api/admin/orders' => ['GET', 'HEAD'], 'api/admin/orders/{id}' => ['GET', 'HEAD'],
            'api/admin/orders/{id}/status' => ['PATCH'], 'api/admin/orders/{id}/payment-status' => ['PATCH'],
            'api/admin/orders/{id}/shipment' => ['PATCH'],
        ], $paths);
    }
}
