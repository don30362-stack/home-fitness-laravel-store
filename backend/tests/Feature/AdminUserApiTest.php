<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    private function permissionAdmin(array $attributes = []): \App\Models\Admin
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = \App\Models\Admin::factory()->create($attributes);
        $admin->permissions()->attach(\App\Models\Permission::query()->where('code', 'member_manage')->value('id'));
        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function order(User $user, array $attributes = []): \App\Models\Order
    {
        $order = $user->orders()->create(array_merge([
            'order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '歷史姓名', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'snapshot@example.test', 'recipient_name' => '收件人', 'recipient_phone' => '0912345678',
            'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '歷史地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => 100, 'subtotal' => 1200, 'total_amount' => 1300,
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'pending',
        ], $attributes));
        if (isset($attributes['created_at'])) $order->forceFill(['created_at' => $attributes['created_at']])->save();
        return $order;
    }

    public static function reads(): array { return [['/api/admin/users'], ['/api/admin/users/1']]; }

    #[DataProvider('reads')]
    public function test_auth_boundaries(string $url): void
    {
        $member = User::factory()->create();
        $cart = $member->cart()->create();
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs($member, 'web')->getJson($url)->assertUnauthorized();
        Auth::forgetGuards();
        $this->actingAs($member, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        Auth::shouldUse('web');
        $this->getJson($url)
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        Auth::forgetGuards();
        $this->actingAs($member, 'web')->actingAs($this->permissionAdmin(), 'admin');
        Auth::shouldUse('web');
        $this->getJson($url)->assertOk();
    }

    public function test_empty_and_explicit_resource_privacy(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $this->getJson('/api/admin/users')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.per_page', 10);
        $user = User::factory()->create();
        $user->cart()->create();
        $this->order($user);
        $keys = ['id', 'name', 'email', 'phone', 'status', 'created_at', 'updated_at'];
        $list = $this->getJson('/api/admin/users')->assertOk()->assertJsonStructure(['data', 'links', 'meta'])->json('data.0');
        $this->assertSame($keys, array_keys($list));
        $detail = $this->getJson('/api/admin/users/'.$user->id)->assertOk()->assertJsonMissingPath('success')
            ->assertJsonStructure(['data' => ['user', 'orders' => ['data', 'links', 'meta']]])->json('data');
        $this->assertSame($keys, array_keys($detail['user']));
        $this->assertSame(['id', 'order_no', 'created_at', 'total_amount', 'payment_method', 'payment_status', 'order_status'], array_keys($detail['orders']['data'][0]));
        $this->assertStringNotContainsString($user->password, json_encode($detail));
        $this->assertStringNotContainsString($user->remember_token, json_encode($detail));
    }

    public function test_grouped_search_status_filters_include_legacy(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $active = User::factory()->create(['name' => 'Needle active', 'email' => 'active@example.test']);
        $disabled = User::factory()->create(['name' => 'Needle disabled', 'email' => 'needle-disabled@example.test', 'status' => 'disabled']);
        $legacy = User::factory()->create(['name' => 'legacy', 'status' => 'inactive']);
        foreach (['active' => $active, 'disabled' => $disabled, 'inactive' => $legacy] as $state => $target) {
            $this->getJson('/api/admin/users?status='.$state)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $target->id);
            $this->getJson('/api/admin/users/'.$target->id)->assertOk()->assertJsonPath('data.user.status', $state);
        }
        $this->getJson('/api/admin/users?search=Needle&status=disabled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $disabled->id);
        $this->getJson('/api/admin/users?search=needle-disabled@example')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/users?search=does-not-exist')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_fixed_pagination_and_stable_creation_order(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $users = User::factory()->count(11)->create(['created_at' => '2026-01-01']);
        $old = User::factory()->create(['created_at' => '2025-01-01']);
        $expected = array_reverse($users->modelKeys());
        $page1 = $this->getJson('/api/admin/users?per_page=50')->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)->json('data');
        $page2 = $this->getJson('/api/admin/users?page=2')->assertOk()->assertJsonCount(2, 'data')->json('data');
        $this->assertSame([...$expected, $old->id], array_column([...$page1, ...$page2], 'id'));
    }

    public function test_history_owner_stable_order_and_real_order_page(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $user = User::factory()->create();
        $ids = [];
        for ($i = 0; $i < 11; $i++) $ids[] = $this->order($user, ['created_at' => '2026-01-01'])->id;
        $old = $this->order($user, ['created_at' => '2025-01-01']);
        $other = User::factory()->create();
        $this->order($other);
        $first = $this->getJson('/api/admin/users/'.$user->id.'?order_page=1&page=9')->assertOk()
            ->assertJsonCount(10, 'data.orders.data')->assertJsonPath('data.orders.meta.total', 12)->json('data.orders');
        $second = $this->getJson('/api/admin/users/'.$user->id.'?order_page=2')->assertOk()->assertJsonCount(2, 'data.orders.data')->json('data.orders');
        $this->assertSame([...array_reverse($ids), $old->id], array_column([...$first['data'], ...$second['data']], 'id'));
        $this->assertStringContainsString('order_page=2', $first['links']['next']);
        $this->getJson('/api/admin/users/'.User::factory()->create()->id)->assertOk()->assertJsonCount(0, 'data.orders.data');
    }

    public static function invalidQueries(): array
    {
        return [['search[]=bad', 'search'], ['status=other', 'status'], ['status[]=active', 'status'], ['page=0', 'page'], ['page[]=1', 'page'], ['page=1.5', 'page'], ['search='.str_repeat('a', 101), 'search']];
    }

    #[DataProvider('invalidQueries')]
    public function test_list_validation(string $query, string $field): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin')->getJson('/api/admin/users?'.$query)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_detail_validation_and_numeric_404(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $id = User::factory()->create()->id;
        foreach (['0', 'no', '1.5', '-1', ''] as $page) {
            if ($page === '') continue; // omitted/nullable follows existing Laravel query style.
            $this->getJson('/api/admin/users/'.$id.'?order_page='.$page)->assertUnprocessable()->assertJsonValidationErrors('order_page');
        }
        $this->getJson('/api/admin/users/'.$id.'?order_page[]=1')->assertUnprocessable();
        $this->getJson('/api/admin/users/999999')->assertNotFound();
        $this->getJson('/api/admin/users/no')->assertNotFound();
    }

    public function test_query_counts_are_constant_without_catalog_or_business_relations(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $user = User::factory()->create();
        $this->order($user);
        $connection = DB::connection(); $connection->enableQueryLog();
        $listCounts = []; $detailCounts = [];
        foreach ([1, 10] as $size) {
            if ($size === 10) {
                User::factory()->count(9)->create();
                for ($i = 0; $i < 9; $i++) $this->order($user);
            }
            foreach (['/api/admin/users' => &$listCounts, '/api/admin/users/'.$user->id => &$detailCounts] as $url => &$counts) {
                $connection->flushQueryLog();
                $this->getJson($url)->assertOk();
                $queries = array_column($connection->getQueryLog(), 'query'); $counts[] = count($queries);
                foreach ($queries as $sql) foreach (['order_items', 'products', 'product_variants', 'categories', 'user_addresses', 'carts'] as $table) $this->assertStringNotContainsString('from "'.$table.'"', $sql);
            }
            unset($counts);
        }
        $connection->disableQueryLog();
        $this->assertSame($listCounts[0], $listCounts[1]);
        $this->assertSame($detailCounts[0], $detailCounts[1]);
        $this->assertSame(4, $listCounts[0]); // admin.active + permission existence + count + user page.
        $this->assertSame(5, $detailCounts[0]); // admin.active + permission existence + user + order count + order page.
    }

    public function test_users_routes_are_exactly_three_protected_numeric_endpoints(): void
    {
        $paths = [];
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/users')) continue;
            $paths[$route->uri()] = $route->methods();
            $this->assertSame(['api', 'auth:admin', 'admin.active', 'admin.permission:member_manage'], $route->gatherMiddleware());
            if (str_contains($route->uri(), '{id}')) $this->assertSame('[0-9]+', $route->wheres['id']);
        }
        $this->assertSame(['api/admin/users' => ['GET', 'HEAD'], 'api/admin/users/{id}' => ['GET', 'HEAD'], 'api/admin/users/{id}/status' => ['PATCH']], $paths);
    }
}
