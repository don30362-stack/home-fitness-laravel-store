<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\AdminDemoService;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminDemoApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function demo(): Admin
    {
        $admin = Admin::factory()->create(['email' => AdminDemoService::EMAIL, 'status' => 'active']);
        config(['demo.enabled' => true, 'demo.admin_id' => $admin->id]);
        return $admin;
    }

    public function test_entry_and_me_have_zero_grants_and_rotate_session(): void
    {
        $demo = $this->demo();
        $this->get('/sanctum/csrf-cookie');
        $id = app('session.store')->getId();
        $this->postJson('/api/admin/demo')->assertOk()->assertJsonPath('data.permissions', [])
            ->assertJsonPath('data.is_demo', true)->assertJsonPath('data.id', $demo->id);
        $this->assertNotSame($id, app('session.store')->getId());
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.permissions', [])->assertJsonPath('data.is_demo', true);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_eight_reads_and_every_other_registered_admin_route_fail_closed_even_with_all_grants(): void
    {
        $demo = $this->demo();
        foreach (Permission::CATALOG as $code => $name) {
            $demo->permissions()->attach(Permission::create(compact('code', 'name')));
        }
        $product = Product::factory()->create(['category_id' => \App\Models\Category::create(['name' => '展示分類', 'status' => 'active', 'sort_order' => 0])->id]);
        $this->actingAs($demo, 'admin');
        $reads = ['me', 'dashboard', 'products', 'products/'.$product->id, 'categories', 'inventory', 'banners', 'recommended-products'];
        foreach ($reads as $path) $this->getJson('/api/admin/'.$path)->assertOk();
        $this->getJson('/api/admin/me')->assertJsonPath('data.permissions', []);
        $demo->load('permissions');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $identity = (new \App\Http\Resources\AdminResource($demo))->resolve();
        $this->assertSame([], $identity['permissions']);
        $this->assertTrue($identity['is_demo']);
        $this->assertCount(0, DB::getQueryLog());
        $before = $product->fresh()->toArray();
        DB::flushQueryLog();
        foreach (app('router')->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/admin/')) continue;
            $uri = preg_replace('/\{[^}]+\}/', (string) $product->id, $route->uri());
            foreach ($route->methods() as $method) {
                if (($method === 'GET' && in_array(substr($uri, 10), $reads, true))
                    || ($method === 'POST' && in_array($uri, ['api/admin/demo', 'api/admin/logout'], true))) continue;
                $response = $this->json($method, '/'.$uri)->assertForbidden();
                if ($method !== 'HEAD') $response->assertJsonPath('code', 'DEMO_READ_ONLY');
            }
        }
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|drop|truncate)\b/i', $query['query']);
        }
        DB::disableQueryLog();
        $this->assertSame($before, $product->fresh()->toArray());
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('admin_permission', 7);
    }

    public function test_dashboard_does_not_query_sensitive_tables(): void
    {
        $demo = $this->demo();
        $this->actingAs($demo, 'admin');
        DB::enableQueryLog();
        $this->getJson('/api/admin/dashboard')->assertOk()->assertExactJson([
            'data' => ['products' => ['total' => 0], 'members' => null, 'orders' => null],
        ]);
        foreach (DB::getQueryLog() as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(users|orders|order_items)\b/i', $query['query']);
        }
        DB::disableQueryLog();
    }

    public function test_bad_configuration_and_inactive_identity_do_not_log_in(): void
    {
        // Configuration matrix is independent of the separately verified 5/min limiter.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        $demo = $this->demo();
        $owner = Admin::factory()->create();
        config(['demo.enabled' => false]);
        $this->postJson('/api/admin/demo')->assertStatus(503);
        $this->assertGuest('admin');
        config(['demo.enabled' => true]);
        foreach ([null, 99999, $owner->id, 'bad'] as $id) {
            config(['demo.admin_id' => $id]);
            $this->postJson('/api/admin/demo')->assertStatus(503);
            $this->assertGuest('admin');
        }
        config(['demo.admin_id' => $demo->id]);
        $demo->update(['status' => 'disabled']);
        $this->postJson('/api/admin/demo')->assertStatus(503);
        $this->assertGuest('admin');
        $this->assertEquals($owner->toArray(), $owner->fresh()->toArray());
    }

    public function test_extra_payload_rejected_and_entry_is_limited_to_five_per_ip(): void
    {
        $this->demo();
        $this->postJson('/api/admin/demo', ['admin_id' => 1])->assertUnprocessable();
        for ($i = 0; $i < 4; $i++) $this->postJson('/api/admin/demo')->assertOk();
        $this->postJson('/api/admin/demo')->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_member_session_survives_demo_entry_and_logout(): void
    {
        $this->demo();
        $member = User::factory()->create();
        $this->actingAs($member, 'web');
        $this->postJson('/api/admin/demo')->assertOk();
        $this->assertAuthenticatedAs($member, 'web');
        $this->postJson('/api/admin/logout')->assertOk();
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertGuest('admin');
    }

    public function test_owner_is_not_replaced_and_normal_zero_permission_admin_is_not_demo(): void
    {
        $this->demo();
        $owner = Admin::factory()->create(['status' => 'active']);
        $this->actingAs($owner, 'admin');
        $this->postJson('/api/admin/demo')->assertStatus(409);
        $this->assertAuthenticatedAs($owner, 'admin');
        $this->getJson('/api/admin/products')->assertForbidden()->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $p = Permission::create(['code' => 'product_manage', 'name' => '商品管理']);
        $owner->permissions()->attach($p);
        $this->postJson('/api/admin/products', [])->assertUnprocessable();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonMissing(['is_demo' => true]);
    }

    public function test_provision_creates_only_new_zero_permission_account_and_never_converts_existing(): void
    {
        $owner = Admin::factory()->create();
        $before = $owner->toArray();
        $this->artisan('admin:provision-demo')->assertSuccessful();
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertEquals($before, $owner->fresh()->toArray());
        $demo = Admin::where('email', AdminDemoService::EMAIL)->firstOrFail();
        $before = $demo->toArray();
        $this->artisan('admin:provision-demo')->assertFailed();
        $this->assertEquals($before, $demo->fresh()->toArray());
        $this->assertDatabaseCount('admins', 2);
        $demo->update(['password' => 'isolated-password']);
        $this->postJson('/api/admin/login', ['email' => AdminDemoService::EMAIL, 'password' => 'isolated-password'])->assertUnauthorized();
        $this->assertGuest('admin');
    }

    public function test_real_csrf_rejects_entry_without_token(): void
    {
        $this->demo();
        $csrf = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery {
            protected function runningUnitTests() { return false; }
        };
        $this->app->instance(PreventRequestForgery::class, $csrf);
        config(['sanctum.middleware.validate_csrf_token' => PreventRequestForgery::class]);
        $this->postJson('/api/admin/demo')->assertStatus(419);
        $this->assertGuest('admin');
    }
}
