<?php

namespace Tests\Feature;

use App\Http\Resources\ManagedAdminDetailResource;
use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use App\Services\AdminManagementService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminManagementApiTest extends TestCase
{
    use RefreshDatabase;

    private Admin $owner;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
        $this->seed(PermissionSeeder::class);
        $this->owner = $this->manager();
    }

    private function manager(): Admin
    {
        $admin = Admin::factory()->create();
        $admin->permissions()->attach($this->permission('admin_manage'));

        return $admin;
    }

    private function permission(string $code): int
    {
        return (int) Permission::where('code', $code)->value('id');
    }

    private function login(): static
    {
        return $this->actingAs($this->owner, 'admin');
    }

    public static function endpoints(): array
    {
        return [['GET', '/admins'], ['POST', '/admins'], ['GET', '/admins/99999'], ['PATCH', '/admins/99999'], ['PATCH', '/admins/99999/status'], ['GET', '/permissions'], ['PUT', '/admins/99999/permissions']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_and_member_cannot_access(string $method, string $path): void
    {
        $this->json($method, '/api/admin'.$path)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->json($method, '/api/admin'.$path)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_and_wrong_module_cannot_access(string $method, string $path): void
    {
        $this->owner->update(['status' => 'disabled']);
        $this->login()->json($method, '/api/admin'.$path)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
    }

    public function test_reads_exact_contract_catalog_unknown_preservation_and_zero_query_serialization(): void
    {
        $target = Admin::factory()->disabled()->create();
        $future = Permission::create(['code' => 'future_module', 'name' => 'Future']);
        $target->permissions()->attach([$future->id, $this->permission('product_manage')]);
        $list = $this->login()->getJson('/api/admin/admins')->assertOk()->json('data');
        $this->assertSame([$this->owner->id, $target->id], array_column($list, 'id'));
        $this->assertSame(['id', 'name', 'email', 'status'], array_keys($list[0]));
        $options = $this->getJson('/api/admin/permissions')->assertOk()->json('data');
        $this->assertCount(7, $options);
        $codes = array_keys(Permission::CATALOG);
        sort($codes);
        $this->assertSame($codes, array_column($options, 'code'));
        $this->assertSame(['id', 'code', 'name'], array_keys($options[0]));
        $data = $this->getJson('/api/admin/admins/'.$target->id)->assertOk()->json('data');
        $this->assertSame(['id', 'name', 'email', 'status', 'created_at', 'updated_at', 'permissions'], array_keys($data));
        $this->assertSame(['product_manage'], array_column($data['permissions'], 'code'));
        $target->load('permissions');
        DB::enableQueryLog();
        DB::flushQueryLog();
        (new ManagedAdminDetailResource($target))->resolve();
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $target->id, 'permission_id' => $future->id]);
    }

    public function test_create_hashed_password_defaults_no_permissions_and_partial_update_noop(): void
    {
        $response = $this->login()->postJson('/api/admin/admins', ['name' => '  New  ', 'email' => 'new@example.test', 'password' => 'test-secret', 'password_confirmation' => 'test-secret'])->assertCreated()->assertJsonPath('data.name', 'New')->assertJsonPath('data.status', 'active')->assertJsonPath('data.permissions', [])->assertJsonMissingPath('data.password');
        $target = Admin::findOrFail($response->json('data.id'));
        $this->assertTrue(Hash::check('test-secret', $target->password));
        $this->patchJson('/api/admin/admins/'.$target->id, ['name' => 'Updated'])->assertOk()->assertJsonPath('data.email', 'new@example.test');
        $before = $target->fresh()->updated_at;
        $this->patchJson('/api/admin/admins/'.$target->id, [])->assertOk();
        $this->assertEquals($before, $target->fresh()->updated_at);
        $this->postJson('/api/admin/admins', ['name' => 'Disabled', 'email' => 'disabled@example.test', 'password' => 'test-secret', 'password_confirmation' => 'test-secret', 'status' => 'disabled'])->assertCreated()->assertJsonPath('data.status', 'disabled');
    }

    public static function invalidCreates(): array
    {
        return [[['name' => ' ']], [['name' => str_repeat('a', 51)]], [['email' => []]], [['email' => 'bad']], [['password' => 'short', 'password_confirmation' => 'short']], [['password_confirmation' => 'different']], [['status' => 'inactive']], [['permission_ids' => []]], [['permissions' => []]], [['role' => 'owner']], [['permission_ids.fake' => []]], [['*.status' => 'active']]];
    }

    #[DataProvider('invalidCreates')]
    public function test_strict_create_validation(array $override): void
    {
        $this->login()->postJson('/api/admin/admins', array_replace(['name' => 'New', 'email' => 'new@example.test', 'password' => 'test-secret', 'password_confirmation' => 'test-secret'], $override))->assertUnprocessable();
        $this->assertDatabaseCount('admins', 1);
    }

    public static function invalidBasic(): array
    {
        return [[['name' => null]], [['name' => ' ']], [['email' => 'bad']], [['status' => 'disabled']], [['password' => 'new-password']], [['permissions' => []]], [['permission_ids' => []]]];
    }

    #[DataProvider('invalidBasic')]
    public function test_basic_patch_rejects_other_contracts(array $payload): void
    {
        $this->login()->patchJson('/api/admin/admins/'.$this->owner->id, $payload)->assertUnprocessable();
    }

    public function test_email_duplicate_and_narrow_database_race_fallback(): void
    {
        $this->login()->postJson('/api/admin/admins', ['name' => 'New', 'email' => $this->owner->email, 'password' => 'test-secret', 'password_confirmation' => 'test-secret'])->assertUnprocessable()->assertJsonValidationErrors('email');
        Admin::creating(function (Admin $admin) {
            DB::table('admins')->insert(['name' => 'Race', 'email' => $admin->email, 'password' => 'hashed', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        });
        try {
            $this->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'race@example.test', 'password' => 'test-secret', 'password_confirmation' => 'test-secret'])->assertUnprocessable()->assertJsonValidationErrors('email');
            $this->assertDatabaseCount('admins', 1);
        } finally {
            Admin::flushEventListeners();
        }
    }

    public static function missingEndpoints(): array
    {
        return [['GET', ''], ['PATCH', ''], ['PATCH', '/status'], ['PUT', '/permissions']];
    }

    #[DataProvider('missingEndpoints')]
    public function test_missing_and_nonnumeric_ids(string $method, string $suffix): void
    {
        $payload = $suffix === '/status' ? ['status' => 'active'] : ($suffix === '/permissions' ? ['permission_ids' => []] : []);
        $this->login()->json($method, '/api/admin/admins/99999'.$suffix, $payload)->assertNotFound();
        $this->json($method, '/api/admin/admins/nope'.$suffix, $payload)->assertNotFound();
    }

    public function test_status_noop_skips_events_and_preserves_relations(): void
    {
        $target = Admin::factory()->create();
        $target->permissions()->attach($this->permission('product_manage'));
        $old = $target->updated_at;
        Admin::updating(fn () => throw new RuntimeException('Must not save no-op'));
        try {
            $this->login()->patchJson('/api/admin/admins/'.$target->id.'/status', ['status' => 'active'])->assertOk();
        } finally {
            Admin::flushEventListeners();
        }
        $this->assertEquals($old, $target->fresh()->updated_at);
        $this->patchJson('/api/admin/admins/'.$target->id.'/status', ['status' => 'disabled'])->assertOk();
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $target->id, 'permission_id' => $this->permission('product_manage')]);
        $this->patchJson('/api/admin/admins/'.$target->id.'/status', ['status' => 'active'])->assertOk();
    }

    public static function invalidStatus(): array
    {
        return [[[]], [['status' => null]], [['status' => []]], [['status' => 'inactive']], [['status' => 'disabled', 'name' => 'Extra']]];
    }

    #[DataProvider('invalidStatus')]
    public function test_status_validation(array $payload): void
    {
        $this->login()->patchJson('/api/admin/admins/'.$this->owner->id.'/status', $payload)->assertUnprocessable();
    }

    public function test_last_manager_status_and_permission_removal_rejected_but_backup_allows_self(): void
    {
        $this->login()->patchJson('/api/admin/admins/'.$this->owner->id.'/status', ['status' => 'disabled'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->putJson('/api/admin/admins/'.$this->owner->id.'/permissions', ['permission_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('permission_ids');
        $backup = $this->manager();
        $this->putJson('/api/admin/admins/'.$this->owner->id.'/permissions', ['permission_ids' => []])->assertOk()->assertJsonPath('data.permissions', []);
        $this->actingAs($backup, 'admin')->patchJson('/api/admin/admins/'.$backup->id.'/status', ['status' => 'disabled'])->assertUnprocessable();
        $this->owner->permissions()->attach($this->permission('admin_manage'));
        $this->patchJson('/api/admin/admins/'.$backup->id.'/status', ['status' => 'disabled'])->assertOk();
    }

    public function test_permissions_replace_only_known_catalog_preserves_future_and_disabled_target(): void
    {
        $target = Admin::factory()->disabled()->create();
        $future = Permission::create(['code' => 'future_module', 'name' => 'Future']);
        $target->permissions()->attach([$future->id, $this->permission('admin_manage'), $this->permission('product_manage')]);
        $this->login()->putJson('/api/admin/admins/'.$target->id.'/permissions', ['permission_ids' => [$this->permission('order_manage')]])->assertOk()->assertJsonPath('data.permissions.0.code', 'order_manage');
        $this->assertEqualsCanonicalizing([$future->id, $this->permission('order_manage')], $target->fresh()->permissions->pluck('id')->all());
        $this->putJson('/api/admin/admins/'.$target->id.'/permissions', ['permission_ids' => []])->assertOk()->assertJsonPath('data.permissions', []);
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $target->id, 'permission_id' => $future->id]);
        $this->putJson('/api/admin/admins/'.$target->id.'/permissions', ['permission_ids' => [$future->id]])->assertUnprocessable();
    }

    public static function invalidPermissions(): array
    {
        return [[[]], [['permission_ids' => null]], [['permission_ids' => '1']], [['permission_ids' => [99999]]], [['permission_ids' => [1, 1]]], [['permission_ids' => [1.5]]], [['permission_ids' => ['a' => 1]]], [['permission_ids' => [], 'name' => 'Extra']]];
    }

    #[DataProvider('invalidPermissions')]
    public function test_permissions_validation(array $payload): void
    {
        $this->login()->putJson('/api/admin/admins/'.$this->owner->id.'/permissions', $payload)->assertUnprocessable();
    }

    public static function cancelledEvents(): array
    {
        return [['creating'], ['updating-basic'], ['updating-status']];
    }

    #[DataProvider('cancelledEvents')]
    public function test_event_false_fails_closed(string $kind): void
    {
        $target = Admin::factory()->create();
        $old = $target->toArray();
        $this->login();
        if ($kind === 'creating') {
            Admin::creating(fn () => false);
        } else {
            Admin::updating(fn () => false);
        }
        try {
            if ($kind === 'creating') {
                $this->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'test-secret', 'password_confirmation' => 'test-secret'])->assertStatus(500);
            } elseif ($kind === 'updating-basic') {
                $this->patchJson('/api/admin/admins/'.$target->id, ['name' => 'Changed'])->assertStatus(500);
            } else {
                $this->patchJson('/api/admin/admins/'.$target->id.'/status', ['status' => 'disabled'])->assertStatus(500);
            }
            $this->assertEquals($old, $target->fresh()->toArray());
            $this->assertDatabaseCount('admins', 2);
        } finally {
            Admin::flushEventListeners();
        }
    }

    public function test_status_late_failure_rolls_back_saved_sql(): void
    {
        $target = Admin::factory()->create();
        Admin::updated(fn () => throw new RuntimeException('Late failure'));
        try {
            $this->login()->patchJson('/api/admin/admins/'.$target->id.'/status', ['status' => 'disabled'])->assertStatus(500);
        } finally {
            Admin::flushEventListeners();
        }
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_permissions_late_pivot_failure_rolls_back_detach_and_preserves_unknown(): void
    {
        $target = Admin::factory()->create();
        $future = Permission::create(['code' => 'future_module', 'name' => 'Future']);
        $target->permissions()->attach([$future->id, $this->permission('product_manage')]);
        $new = $this->permission('order_manage');
        DB::unprepared("CREATE TRIGGER fail_permission_insert BEFORE INSERT ON admin_permission WHEN NEW.permission_id = $new BEGIN SELECT RAISE(ABORT, 'fixture late failure'); END");
        try {
            $this->login()->putJson('/api/admin/admins/'.$target->id.'/permissions', ['permission_ids' => [$new]])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER fail_permission_insert');
        }
        $this->assertEqualsCanonicalizing([$future->id, $this->permission('product_manage')], $target->fresh()->permissions->pluck('id')->all());
    }

    public function test_anchor_first_and_actor_revalidated_after_locks(): void
    {
        $target = Admin::factory()->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(AdminManagementService::class)->status($this->owner->id, $target->id, 'disabled');
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        $this->assertStringContainsString('"permissions"', $queries[0]);
        $this->assertStringContainsString('"admins"', $queries[1]);
        $this->assertStringContainsString('order by "id" asc', $queries[1]);
        $this->owner->permissions()->detach();
        try {
            app(AdminManagementService::class)->status($this->owner->id, $target->id, 'active');
            $this->fail('Expected rejection');
        } catch (HttpResponseException $e) {
            $this->assertSame('ADMIN_PERMISSION_DENIED', json_decode($e->getResponse()->getContent(), true)['code']);
        }
        $this->assertSame('disabled', $target->fresh()->status);
        $this->owner->update(['status' => 'disabled']);
        try {
            app(AdminManagementService::class)->permissions($this->owner->id, $target->id, []);
            $this->fail('Expected rejection');
        } catch (HttpResponseException $e) {
            $this->assertSame('ADMIN_ACCOUNT_DISABLED', json_decode($e->getResponse()->getContent(), true)['code']);
        }
    }

    public function test_missing_anchor_fails_closed_and_does_not_recreate_catalog(): void
    {
        Permission::where('code', 'admin_manage')->delete();
        $this->expectException(\LogicException::class);
        app(AdminManagementService::class)->status($this->owner->id, $this->owner->id, 'disabled');
    }

    public function test_disabled_member_session_and_cart_not_affected_by_admin_mutations(): void
    {
        $member = User::factory()->create(['status' => 'disabled']);
        $cart = $member->cart()->create();
        $target = Admin::factory()->create();
        $this->actingAs($member, 'web')->login()->withSession(['member_marker' => 'keep'])->patchJson('/api/admin/admins/'.$target->id, ['name' => 'Changed'])->assertOk()->assertSessionHas('member_marker', 'keep');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_basic_email_change_keeps_password_permissions_and_unique_validation(): void
    {
        $target = Admin::factory()->create();
        $before = $target->password;
        $this->login()->patchJson('/api/admin/admins/'.$target->id, ['email' => $this->owner->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->patchJson('/api/admin/admins/'.$target->id, ['email' => '  changed@example.test  '])->assertOk()->assertJsonPath('data.email', 'changed@example.test');
        $this->assertSame($before, $target->fresh()->password);
        $this->assertSame('active', $target->fresh()->status);
    }

    public function test_disabled_manager_does_not_count_as_backup_and_unprivileged_target_can_be_disabled(): void
    {
        $backup = $this->manager();
        $backup->update(['status' => 'disabled']);
        $plain = Admin::factory()->create();
        $this->login()->patchJson('/api/admin/admins/'.$plain->id.'/status', ['status' => 'disabled'])->assertOk();
        $this->patchJson('/api/admin/admins/'.$this->owner->id.'/status', ['status' => 'disabled'])->assertUnprocessable();
        $this->putJson('/api/admin/admins/'.$this->owner->id.'/permissions', ['permission_ids' => []])->assertUnprocessable();
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $this->owner->id, 'permission_id' => $this->permission('admin_manage')]);
        $this->assertSame('active', $this->owner->fresh()->status);
    }

    public function test_self_disable_with_active_backup_succeeds_without_removing_permissions(): void
    {
        $this->manager();
        $this->login()->patchJson('/api/admin/admins/'.$this->owner->id.'/status', ['status' => 'disabled'])->assertOk();
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $this->owner->id, 'permission_id' => $this->permission('admin_manage')]);
        $this->getJson('/api/admin/me')->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
    }

    public function test_incomplete_catalog_permissions_fail_closed_without_partial_sync(): void
    {
        Permission::where('code', 'order_manage')->delete();
        $target = Admin::factory()->create();
        $target->permissions()->attach($this->permission('product_manage'));
        $this->login()->putJson('/api/admin/admins/'.$target->id.'/permissions', ['permission_ids' => []])->assertStatus(500);
        $this->assertDatabaseHas('admin_permission', ['admin_id' => $target->id, 'permission_id' => $this->permission('product_manage')]);
        $this->assertDatabaseCount('permissions', 6);
    }

    public function test_permissions_use_same_anchor_before_admin_lock_order(): void
    {
        $target = Admin::factory()->create();
        $permissionId = $this->permission('product_manage');
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(AdminManagementService::class)->permissions($this->owner->id, $target->id, [$permissionId]);
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();
        // Inspect the actual anchor query and the immediately following Admin lock query.
        $anchor = array_values(array_filter($queries, fn ($sql) => str_contains($sql, '"permissions"') && str_contains($sql, 'limit 1')))[0];
        $index = array_search($anchor, $queries, true);
        $this->assertStringContainsString('"admins"', $queries[$index + 1]);
        $this->assertStringContainsString('order by "id" asc', $queries[$index + 1]);
    }

    public function test_unrelated_database_failure_is_not_relabelled_email_validation(): void
    {
        DB::unprepared("CREATE TRIGGER fail_admin_insert BEFORE INSERT ON admins BEGIN SELECT RAISE(ABORT, 'unrelated fixture failure'); END");
        try {
            $this->login()->postJson('/api/admin/admins', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'test-secret', 'password_confirmation' => 'test-secret'])->assertStatus(500);
        } finally {
            DB::unprepared('DROP TRIGGER fail_admin_insert');
        }
        $this->assertDatabaseCount('admins', 1);
    }
}
