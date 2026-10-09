<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ProductionOwnerInitializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Database remains phpunit's SQLite :memory:, never the daily database.
        $this->app->detectEnvironment(fn () => 'production');
    }

    private function initialize(array $changes = []): PendingCommand
    {
        $data = array_replace(['name' => 'Production Fixture', 'email' => 'owner@example.test',
            'password' => 'isolated-secret', 'confirmation' => 'isolated-secret'], $changes);

        return $this->artisan('admin:initialize-production-owner')
            ->doesntExpectOutputToContain($data['password'])
            ->expectsQuestion('管理員姓名', $data['name'])
            ->expectsQuestion('管理員 Email', $data['email'])
            ->expectsQuestion('管理員密碼', $data['password'])
            ->expectsQuestion('再次輸入密碼', $data['confirmation']);
    }

    public function test_production_initialization_creates_active_hashed_owner_with_exact_catalog(): void
    {
        (new PermissionSeeder)->run();
        Permission::create(['code' => 'future_module', 'name' => 'Future']);
        $this->initialize()->expectsOutput('正式初始管理員已建立並授予七項權限。')->assertSuccessful();
        $owner = Admin::sole();
        $this->assertSame('active', $owner->status);
        $this->assertTrue(Hash::check('isolated-secret', $owner->password));
        $this->assertNotSame('isolated-secret', $owner->password);
        $this->assertEqualsCanonicalizing(array_keys(Permission::CATALOG), $owner->permissions->pluck('code')->all());
        $this->assertDatabaseCount('admin_permission', 7);
        $before = $owner->getAttributes();
        $this->artisan('admin:initialize-production-owner')->assertFailed();
        $this->assertSame($before, $owner->fresh()->getAttributes());
        $this->assertDatabaseCount('admins', 1);
        $this->assertDatabaseCount('admin_permission', 7);
    }

    public function test_any_existing_admin_even_disabled_or_demo_prevents_initialization(): void
    {
        (new PermissionSeeder)->run();
        $admin = Admin::factory()->disabled()->create(['email' => \App\Services\AdminDemoService::EMAIL]);
        $before = $admin->fresh()->getAttributes();
        $this->artisan('admin:initialize-production-owner')
            ->expectsOutput('已有管理員帳號，拒絕初始化或修改既有帳號。')->assertFailed();
        $this->assertSame($before, $admin->fresh()->getAttributes());
        $this->assertDatabaseCount('admins', 1);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_missing_catalog_fails_closed_without_creating_or_seeding(): void
    {
        $this->initialize()->expectsOutput('Permission catalog 不完整，拒絕初始化。')->assertFailed();
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('admin_permission', 0);
        (new PermissionSeeder)->run();
        Permission::where('code', 'admin_manage')->delete();
        $this->initialize()->assertFailed();
        $this->assertDatabaseCount('admins', 0);
    }

    public function test_late_pivot_failure_rolls_back_admin_and_already_inserted_permissions(): void
    {
        (new PermissionSeeder)->run();
        DB::unprepared("CREATE TRIGGER fail_production_attach BEFORE INSERT ON admin_permission WHEN NEW.permission_id = 2 BEGIN SELECT RAISE(ABORT, 'late attach failure'); END");
        $this->initialize()->expectsOutput('無法安全完成初始化，未提交帳號或權限變更。')->assertFailed();
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertDatabaseCount('permissions', 7);
    }

    public function test_cancelled_create_fails_closed(): void
    {
        (new PermissionSeeder)->run();
        Admin::creating(fn () => false);
        try {
            $this->initialize()->expectsOutput('無法安全完成初始化，未提交帳號或權限變更。')->assertFailed();
        } finally {
            Admin::flushEventListeners();
        }
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_invalid_inputs_never_create_an_owner(): void
    {
        (new PermissionSeeder)->run();
        foreach ([['name' => ' '], ['email' => 'invalid'], ['password' => 'short'], ['confirmation' => 'different']] as $data) {
            $this->initialize($data)->assertFailed();
        }
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_nonproduction_and_noninteractive_execution_are_rejected(): void
    {
        $this->artisan('admin:initialize-production-owner', ['--no-interaction' => true])->assertFailed();
        foreach (['local', 'testing', 'staging'] as $env) {
            $this->app->detectEnvironment(fn () => $env);
            $this->artisan('admin:initialize-production-owner')->assertFailed();
        }
        $this->assertDatabaseCount('admins', 0);
    }

    public function test_command_has_no_credential_arguments_or_options(): void
    {
        $command = new \App\Console\Commands\InitializeProductionOwner;
        $this->assertSame([], $command->getDefinition()->getArguments());
        foreach (['name', 'email', 'password', 'password_confirmation', 'force'] as $option) {
            $this->assertFalse($command->getDefinition()->hasOption($option));
        }
    }

    public function test_old_local_commands_still_refuse_production(): void
    {
        $this->artisan('admin:bootstrap')->assertFailed();
        $this->artisan('admin:provision-owner', ['email' => 'owner@example.test'])->assertFailed();
        $this->assertDatabaseCount('admins', 0);
    }
}
