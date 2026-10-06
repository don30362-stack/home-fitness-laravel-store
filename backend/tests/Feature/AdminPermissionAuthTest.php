<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use App\Http\Resources\AdminResource;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPermissionAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        $this->seed(PermissionSeeder::class);
        // Test-only integration routes. No production business mapping in Step 1.
        foreach (['single' => 'product_manage', 'or' => 'product_manage,home_content_manage'] as $path => $codes) {
            Route::get('/api/admin/permission-test/'.$path, fn () => response()->json(['message' => 'allowed']))
                ->middleware(['api', 'auth:admin', 'admin.active', 'admin.permission:'.$codes]);
        }
    }

    public function test_login_and_me_have_exact_same_sorted_identity_without_permission_objects(): void
    {
        $admin = Admin::factory()->create(['password' => 'test-secret']);
        $admin->permissions()->attach(Permission::query()->whereIn('code', ['product_manage', 'admin_manage'])->pluck('id'));
        $expected = ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email,
            'status' => 'active', 'permissions' => ['admin_manage', 'product_manage']];
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-secret'])
            ->assertOk()->assertExactJson(['data' => $expected, 'message' => '管理員登入成功']);
        $this->getJson('/api/admin/me')->assertOk()->assertExactJson(['data' => $expected]);
    }

    public function test_login_and_me_exclude_unknown_code_without_removing_database_row_or_relation(): void
    {
        $admin = Admin::factory()->create(['password' => 'test-secret']);
        $future = Permission::query()->create(['code' => 'future_module', 'name' => 'Future']);
        $admin->permissions()->attach(Permission::query()->whereIn('code', ['admin_manage', 'product_manage', 'future_module'])->pluck('id'));
        $expected = ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email,
            'status' => 'active', 'permissions' => ['admin_manage', 'product_manage']];
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-secret'])
            ->assertOk()->assertExactJson(['data' => $expected, 'message' => '管理員登入成功']);
        $this->assertDatabaseHas('permissions', ['id' => $future->id, 'code' => 'future_module']);
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $admin->id, 'permission_id' => $future->id]);
        $this->getJson('/api/admin/me')->assertOk()->assertExactJson(['data' => $expected]);
        $this->assertDatabaseHas('permissions', ['id' => $future->id, 'code' => 'future_module']);
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $admin->id, 'permission_id' => $future->id]);
        $this->assertCount(3, $admin->fresh()->permissions);
    }

    public function test_me_reloads_latest_relation_without_login_or_trusting_loaded_permissions(): void
    {
        $admin = Admin::factory()->create();
        $this->actingAs($admin, 'admin');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.permissions', []);
        $admin->load('permissions');
        $product = Permission::query()->where('code', 'product_manage')->sole();
        $admin->permissions()->attach($product);
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.permissions', ['product_manage']);
        $admin->permissions()->detach();
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_identity_resource_does_not_query_when_serializing_loaded_permissions(): void
    {
        $admin = Admin::factory()->create();
        $admin->permissions()->attach(Permission::query()->where('code', 'product_manage')->sole());
        $admin->permissions()->attach(Permission::query()->where('code', 'admin_manage')->sole());
        $future = Permission::query()->create(['code' => 'future_module', 'name' => 'Future']);
        $admin->permissions()->attach($future);
        // Deliberately load backwards: Resource itself filters/sorts without querying.
        $admin->load(['permissions' => fn ($query) => $query->orderByDesc('code')]);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        try {
            $identity = (new AdminResource($admin))->resolve();
            $this->assertSame(['admin_manage', 'product_manage'], $identity['permissions']);
            $this->assertSame([], DB::connection()->getQueryLog());
        } finally {
            DB::connection()->disableQueryLog();
        }
    }

    public static function cases(): array
    {
        return [
            'single allowed' => ['single', ['product_manage'], 200],
            'single wrong module' => ['single', ['home_content_manage'], 403],
            'single none' => ['single', [], 403],
            'OR first' => ['or', ['product_manage'], 200],
            'OR second' => ['or', ['home_content_manage'], 200],
            'OR both' => ['or', ['product_manage', 'home_content_manage'], 200],
            'OR neither' => ['or', ['order_manage'], 403],
        ];
    }

    #[DataProvider('cases')]
    public function test_permission_foundation_uses_any_match(string $path, array $codes, int $status): void
    {
        $admin = Admin::factory()->create();
        $admin->permissions()->attach(Permission::query()->whereIn('code', $codes)->pluck('id'));
        $response = $this->actingAs($admin, 'admin')->getJson('/api/admin/permission-test/'.$path)->assertStatus($status);
        if ($status === 403) {
            $response->assertExactJson(['message' => '您沒有此功能的操作權限。', 'code' => 'ADMIN_PERMISSION_DENIED']);
        }
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_guest_and_member_only_get_401_before_permission_check(): void
    {
        $this->getJson('/api/admin/permission-test/single')->assertUnauthorized();
        $user = User::factory()->create(['status' => 'disabled']);
        $this->actingAs($user, 'web')->getJson('/api/admin/permission-test/single')->assertUnauthorized();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
    }

    public static function disabledCases(): array
    {
        return [[true], [false]];
    }

    #[DataProvider('disabledCases')]
    public function test_disabled_precedes_permission_even_when_permission_is_present(bool $grant): void
    {
        $admin = Admin::factory()->disabled()->create();
        if ($grant) {
            $admin->permissions()->attach(Permission::query()->where('code', 'product_manage')->sole());
        }
        $this->actingAs($admin, 'admin')->getJson('/api/admin/permission-test/single')
            ->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertGuest('admin');
    }

    public function test_permissions_are_rechecked_from_database_on_each_request_in_same_session(): void
    {
        $admin = Admin::factory()->create();
        $permission = Permission::query()->where('code', 'product_manage')->sole();
        $this->actingAs($admin, 'admin');
        $this->getJson('/api/admin/permission-test/single')->assertForbidden();
        $admin->permissions()->attach($permission);
        $admin->load('permissions');
        $this->getJson('/api/admin/permission-test/single')->assertOk();
        DB::table('admin_permission')->where('admin_id', $admin->id)->delete();
        $this->getJson('/api/admin/permission-test/single')->assertForbidden()->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED');
        $this->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.permissions', []);
    }

    public function test_permission_denied_preserves_member_admin_and_cart_session_marker(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);
        $admin = Admin::factory()->create();
        $this->actingAs($user, 'web')->actingAs($admin, 'admin')->withSession(['cart_marker' => 'preserve'])
            ->getJson('/api/admin/permission-test/single')->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_PERMISSION_DENIED')->assertSessionHas('cart_marker', 'preserve');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'status' => 'disabled']);
    }

    public function test_business_routes_are_mapped_and_factory_has_no_implicit_permissions(): void
    {
        $admin = Admin::factory()->create();
        $this->assertCount(0, $admin->permissions);
        $count = 0;
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (str_starts_with($uri, 'api/admin/') && ! str_contains($uri, 'permission-test') &&
                ! in_array($uri, ['api/admin/login', 'api/admin/logout', 'api/admin/me'], true)) {
                $this->assertContains('auth:admin', $route->gatherMiddleware());
                $this->assertContains('admin.active', $route->gatherMiddleware());
                $this->assertCount(1, array_filter($route->gatherMiddleware(), fn ($middleware) => str_starts_with($middleware, 'admin.permission:')));
                $count++;
            }
        }
        $this->assertSame(36, $count);
    }
}
