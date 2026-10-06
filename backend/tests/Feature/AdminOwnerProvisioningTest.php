<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Services\AdminOwnerProvisioningService;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminOwnerProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public static function allowedEnvironments(): array
    {
        return [['local'], ['testing']];
    }

    #[DataProvider('allowedEnvironments')]
    public function test_existing_owner_is_additive_repeatable_and_preserves_all_profile_attributes(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
        $this->seed(PermissionSeeder::class);
        $admin = Admin::factory()->create();
        $extra = Permission::query()->create(['code' => 'future_module', 'name' => 'Future']);
        $admin->permissions()->attach($extra);
        $before = $admin->fresh()->getAttributes();
        for ($i = 0; $i < 2; $i++) {
            $this->artisan('admin:provision-owner', ['email' => $admin->email])
                ->expectsOutput('既有管理員已補齊七項 owner permissions。')->assertSuccessful();
        }
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertCount(8, $admin->fresh()->permissions);
        $this->assertDatabaseCount('admin_permission', 8);
        $this->assertTrue($admin->permissions()->whereKey($extra->id)->exists());
    }

    public function test_disabled_target_gets_permissions_but_is_not_activated(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = Admin::factory()->disabled()->create();
        $before = $admin->fresh()->getAttributes();
        $this->artisan('admin:provision-owner', ['email' => $admin->email])
            ->expectsOutput('既有管理員已補齊七項 owner permissions。')
            ->expectsOutput('帳號仍為 disabled，目前不是可登入的 active owner。')->assertSuccessful();
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertCount(7, $admin->fresh()->permissions);
    }

    public function test_missing_target_never_creates_an_admin(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->artisan('admin:provision-owner', ['email' => 'missing@example.test'])
            ->expectsOutput('找不到既有管理員，未建立或修改帳號。')->assertFailed();
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public static function incompleteCatalogs(): array
    {
        return [['none'], ['one_missing']];
    }

    #[DataProvider('incompleteCatalogs')]
    public function test_incomplete_catalog_rejects_existing_owner_without_partial_grants(string $kind): void
    {
        $this->incomplete($kind);
        $admin = Admin::factory()->create();
        $this->artisan('admin:provision-owner', ['email' => $admin->email])
            ->expectsOutput('Permission catalog 不完整，請先執行 PermissionSeeder / migrate --seed。')->assertFailed();
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertDatabaseCount('permissions', $kind === 'none' ? 0 : 6);
    }

    #[DataProvider('incompleteCatalogs')]
    public function test_incomplete_catalog_rolls_back_fresh_owner(string $kind): void
    {
        $this->incomplete($kind);
        $this->bootstrap()->expectsOutput('Permission catalog 不完整，請先執行 PermissionSeeder / migrate --seed。')->assertFailed();
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_exception_after_real_attach_rolls_back_fresh_admin_and_pivot(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->app->instance(AdminOwnerProvisioningService::class, new class extends AdminOwnerProvisioningService
        {
            public function grant(Admin $admin): void
            {
                parent::grant($admin);
                throw new RuntimeException('late provisioning failure');
            }
        });
        try {
            $this->bootstrap()->run();
            $this->fail('Expected late provisioning failure.');
        } catch (RuntimeException $error) {
            $this->assertSame('late provisioning failure', $error->getMessage());
        }
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertDatabaseCount('permissions', 7);
    }

    public function test_existing_owner_attach_failure_rolls_back_all_new_relations(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = Admin::factory()->create();
        // Fail after at least one pivot SQL INSERT, not before the transaction starts.
        DB::unprepared("CREATE TRIGGER fail_owner_attach BEFORE INSERT ON admin_permission WHEN NEW.permission_id = 2 BEGIN SELECT RAISE(ABORT, 'late attach failure'); END");
        try {
            app(AdminOwnerProvisioningService::class)->grant($admin);
            $this->fail('Expected attach failure.');
        } catch (\Illuminate\Database\QueryException $error) {
            $this->assertStringContainsString('late attach failure', $error->getMessage());
        }
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertDatabaseHas('admins', ['id' => $admin->id]);
    }

    public function test_cancelled_admin_create_is_not_success_and_grants_nothing(): void
    {
        $this->seed(PermissionSeeder::class);
        Admin::creating(fn () => false);
        try {
            $this->bootstrap()->run();
            $this->fail('Expected cancelled create to fail.');
        } catch (RuntimeException $error) {
            $this->assertSame('無法建立管理員資料。', $error->getMessage());
        } finally {
            Admin::flushEventListeners();
        }
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_existing_provisioning_refuses_production_without_any_write(): void
    {
        $this->seed(PermissionSeeder::class);
        $admin = Admin::factory()->create();
        $this->app->detectEnvironment(fn () => 'production');
        $this->artisan('admin:provision-owner', ['email' => $admin->email])
            ->expectsOutput('此指令僅允許在 local/testing 環境執行。')->assertFailed();
        $this->assertDatabaseCount('admin_permission', 0);
    }

    private function incomplete(string $kind): void
    {
        if ($kind === 'one_missing') {
            $this->seed(PermissionSeeder::class);
            Permission::query()->where('code', 'admin_manage')->delete();
        }
    }

    private function bootstrap(): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan('admin:bootstrap')
            ->expectsQuestion('管理員姓名', 'Owner')
            ->expectsQuestion('管理員 Email', 'owner@example.test')
            ->expectsQuestion('管理員密碼', 'test-secret')
            ->expectsQuestion('再次輸入密碼', 'test-secret');
    }
}
