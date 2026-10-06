<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminUserStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private function permissionAdmin(array $attributes = []): Admin
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = Admin::factory()->create($attributes);
        $admin->permissions()->attach(\App\Models\Permission::where('code', 'member_manage')->value('id'));
        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function fixture(string $state = 'active'): User
    {
        $user = User::factory()->create(['status' => $state, 'password' => 'test-member-secret', 'updated_at' => '2026-01-01']);
        $city = City::query()->create(['name' => '臺北市']);
        $district = $city->districts()->create(['name' => '中正區', 'postal_code' => '100']);
        $user->userAddresses()->create(['district_id' => $district->id, 'label' => '家', 'recipient_name' => '收件人', 'recipient_phone' => '0912345678', 'address' => '驗證地址', 'is_default' => true]);
        $root = Category::query()->create(['name' => '主分類']);
        $child = $root->children()->create(['name' => '子分類']);
        $product = Product::factory()->create(['category_id' => $child->id, 'stock' => 10]);
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'quantity' => 2]);
        $order = $user->orders()->create([
            'order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '歷史訂購人', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'snapshot@example.test', 'recipient_name' => '歷史收件人', 'recipient_phone' => '0912345678',
            'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '歷史地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => 100, 'payment_method' => 'cod',
            'payment_status' => 'paid', 'order_status' => 'cancelled', 'subtotal' => 1200, 'total_amount' => 1300,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_name_snapshot' => '歷史商品', 'product_code_snapshot' => 'SNAP-001', 'unit_price' => 600, 'quantity' => 2, 'subtotal' => 1200]);
        return $user;
    }

    private function snapshot(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), ['user_addresses', 'carts', 'cart_items', 'orders', 'order_items', 'products', 'product_variants']);
    }

    public function test_auth_rejections_do_not_mutate_business_data_or_member_state(): void
    {
        $user = $this->fixture(); $before = $this->snapshot();
        $url = '/api/admin/users/'.$user->id.'/status';
        $this->patchJson($url, ['status' => 'disabled'])->assertUnauthorized();
        $this->actingAs($user, 'web')->patchJson($url, ['status' => 'disabled'])->assertUnauthorized();
        Auth::forgetGuards();
        $this->actingAs($user, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        Auth::shouldUse('web');
        $this->patchJson($url, ['status' => 'disabled'])->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertEquals($before, $this->snapshot());
        $this->assertSame('active', $user->fresh()->status);
    }

    public static function transitions(): array
    {
        return [['active', 'disabled', 200], ['disabled', 'active', 200], ['inactive', 'active', 200], ['active', 'active', 200], ['disabled', 'disabled', 200], ['inactive', 'disabled', 422]];
    }

    #[DataProvider('transitions')]
    public function test_transition_matrix_and_complete_data_preservation(string $from, string $to, int $http): void
    {
        $user = $this->fixture($from); $before = $this->snapshot(); $attributes = $user->getAttributes();
        $this->actingAs($this->permissionAdmin(), 'admin');
        $response = $this->patchJson('/api/admin/users/'.$user->id.'/status', ['status' => $to])->assertStatus($http)->assertJsonMissingPath('success');
        if ($http === 422) $response->assertJsonValidationErrors('status');
        else $response->assertJsonPath('data.status', $to)->assertJsonPath('message', '會員狀態更新成功。')->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
        $this->assertEquals($before, $this->snapshot());
        $after = $user->fresh()->getAttributes();
        $this->assertSame($http === 200 ? $to : $from, $after['status']);
        foreach ($attributes as $key => $value) {
            if (! in_array($key, ['status', 'updated_at'], true)) $this->assertSame($value, $after[$key], $key);
        }
        if ($from === $to || $http === 422) $this->assertSame($attributes['updated_at'], $after['updated_at']);
    }

    public static function invalidPayloads(): array
    {
        return [[[]], [['status' => null]], [['status' => []]], [['status' => 'inactive']], [['status' => 'other']], [['status' => 'disabled', 'name' => 'changed']], [['status' => 'active', 'password' => null]], [['status' => 'active', 'session_id' => 'bad']]];
    }

    #[DataProvider('invalidPayloads')]
    public function test_strict_validation(array $payload): void
    {
        $user = $this->fixture(); $before = $user->fresh()->getAttributes();
        $this->actingAs($this->permissionAdmin(), 'admin')->patchJson('/api/admin/users/'.$user->id.'/status', $payload)->assertUnprocessable();
        $this->assertSame($before, $user->fresh()->getAttributes());
    }

    public function test_numeric_and_missing_routes(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        $this->patchJson('/api/admin/users/99999/status', ['status' => 'active'])->assertNotFound();
        $this->patchJson('/api/admin/users/no/status', ['status' => 'active'])->assertNotFound();
    }

    public function test_real_sql_update_is_rolled_back_after_late_event_failure(): void
    {
        $user = $this->fixture(); $before = $user->fresh()->getAttributes(); $business = $this->snapshot(); $observed = null;
        User::updated(function ($updated) use ($user, &$observed) {
            if ($updated->id === $user->id) {
                $observed = DB::table('users')->where('id', $user->id)->value('status');
                throw new RuntimeException('late failure');
            }
        });
        try {
            app(AdminUserService::class)->updateStatus($user->id, 'disabled');
            $this->fail('Expected post-SQL failure');
        } catch (RuntimeException $error) { $this->assertSame('late failure', $error->getMessage()); }
        finally { User::flushEventListeners(); }
        $this->assertSame('disabled', $observed);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertEquals($business, $this->snapshot());
    }

    public function test_unknown_stored_state_is_not_silently_normalized(): void
    {
        // Synthetic malformed fixture only; no daily DB is changed or scanned.
        $user = User::factory()->create(['status' => 'unknown']);
        $this->actingAs($this->permissionAdmin(), 'admin')->patchJson('/api/admin/users/'.$user->id.'/status', ['status' => 'active'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('unknown', $user->fresh()->status);
    }

    private function resetGuards(): void { Auth::forgetGuards(); Auth::shouldUse('web'); }

    public function test_dual_session_disable_waits_for_member_request_then_restore_requires_relogin(): void
    {
        $user = $this->fixture(); $before = $this->snapshot();
        $admin = $this->permissionAdmin(['password' => 'test-admin-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        $this->resetGuards();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        $oldSession = app('session.store')->getId();
        $this->withCookie(config('session.cookie'), $oldSession)->withCredentials();
        $this->resetGuards();
        $this->patchJson('/api/admin/users/'.$user->id.'/status', ['status' => 'disabled'])->assertOk();
        $this->assertSame($oldSession, app('session.store')->getId());
        $this->assertAuthenticated('web'); $this->assertAuthenticated('admin');
        $this->resetGuards();
        $this->getJson('/api/admin/users/'.$user->id)->assertOk()->assertJsonPath('data.user.status', 'disabled');
        $this->assertEquals($before, $this->snapshot());
        $this->resetGuards();
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');
        $this->assertGuest('web');
        $this->resetGuards();
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->resetGuards();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        $this->resetGuards();
        $this->patchJson('/api/admin/users/'.$user->id.'/status', ['status' => 'active'])->assertOk();
        $this->resetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
        $this->resetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        $this->resetGuards();
        $this->getJson('/api/me')->assertOk();
        $this->assertEquals($before, $this->snapshot());
    }

    public function test_legacy_inactive_session_is_rejected_and_revoked_by_unchanged_c06(): void
    {
        $user = User::factory()->create(['password' => 'test-member-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        DB::table('users')->where('id', $user->id)->update(['status' => 'inactive']);
        $this->resetGuards();
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');
        $this->assertGuest('web');
        $this->resetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }
}
