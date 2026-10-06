<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminPermissionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_has_exact_columns_no_seed_data_and_composite_primary_key(): void
    {
        $this->assertSame(['id', 'code', 'name'], Schema::getColumnListing('permissions'));
        $this->assertSame(['admin_id', 'permission_id'], Schema::getColumnListing('admin_permission'));
        $this->assertDatabaseCount('permissions', 0);
        $indexes = Schema::getIndexes('admin_permission');
        $primary = collect($indexes)->firstWhere('primary', true);
        $this->assertSame(['admin_id', 'permission_id'], $primary['columns']);
        $this->assertFalse((new Permission)->usesTimestamps());
        foreach (Schema::getForeignKeys('admin_permission') as $foreign) {
            $this->assertSame('cascade', strtolower($foreign['on_delete']));
        }
        $this->assertCount(2, Schema::getForeignKeys('admin_permission'));
    }

    public function test_mysql_grammar_compiles_actual_migrations_with_confirmed_length_and_unsigned_intent(): void
    {
        // SQL compilation only, using the test connection's pretend mode. No MySQL server.
        $connection = DB::connection();
        $connection->getSchemaBuilder();
        $original = $connection->getSchemaGrammar();
        $connection->setSchemaGrammar(new MySqlGrammar($connection));
        Schema::swap($connection->getSchemaBuilder());
        try {
            $queries = $connection->pretend(function (): void {
                (require database_path('migrations/2026_10_06_000002_create_permissions_table.php'))->up();
                (require database_path('migrations/2026_10_06_000003_create_admin_permission_table.php'))->up();
            });
            $sql = implode("\n", array_column($queries, 'query'));
            $this->assertStringContainsString('`code` varchar(50) not null', $sql);
            $this->assertStringContainsString('`name` varchar(50) not null', $sql);
            $this->assertStringContainsString('`id` bigint unsigned not null auto_increment primary key', $sql);
            $this->assertStringContainsString('primary key (`admin_id`, `permission_id`)', $sql);
            $this->assertSame(2, substr_count($sql, 'on delete cascade'));
            $this->assertStringNotContainsString('created_at', $sql);
            $this->assertStringNotContainsString('updated_at', $sql);
        } finally {
            $connection->setSchemaGrammar($original);
            Schema::swap($connection->getSchemaBuilder());
        }
    }

    public function test_migrations_can_roll_back_pivot_before_catalog_and_recreate_empty_schema(): void
    {
        $pivot = require database_path('migrations/2026_10_06_000003_create_admin_permission_table.php');
        $permissions = require database_path('migrations/2026_10_06_000002_create_permissions_table.php');
        $pivot->down();
        $permissions->down();
        $this->assertFalse(Schema::hasTable('permissions'));
        $this->assertFalse(Schema::hasTable('admin_permission'));
        $permissions->up();
        $pivot->up();
        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_catalog_is_exact_idempotent_updates_names_and_never_grants_or_deletes_unknown(): void
    {
        Admin::factory()->create();
        $this->seed(PermissionSeeder::class);
        $before = Permission::query()->orderBy('code')->pluck('id', 'code')->all();
        $this->assertEqualsCanonicalizing([
            'product_manage', 'category_manage', 'inventory_manage', 'order_manage',
            'member_manage', 'home_content_manage', 'admin_manage',
        ], array_keys($before));
        $this->assertDatabaseCount('permissions', 7);
        Permission::query()->where('code', 'product_manage')->update(['name' => '舊名稱']);
        $unknown = Permission::query()->create(['code' => 'future_module', 'name' => 'Future']);
        $this->seed(PermissionSeeder::class);
        $this->assertSame($before, Permission::query()->whereIn('code', array_keys(Permission::CATALOG))->orderBy('code')->pluck('id', 'code')->all());
        foreach (Permission::CATALOG as $code => $name) {
            $this->assertDatabaseHas('permissions', compact('code', 'name'));
        }
        $this->assertDatabaseHas('permissions', ['id' => $unknown->id]);
        $this->assertDatabaseCount('permissions', 8);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_database_seeder_wires_catalog_without_creating_or_granting_admins(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->assertDatabaseCount('permissions', 7);
        $this->assertDatabaseCount('admins', 0);
        $this->assertDatabaseCount('admin_permission', 0);
    }

    public function test_relations_work_both_ways_without_pivot_timestamps_and_admin_delete_cascades(): void
    {
        $admin = Admin::factory()->create();
        $permission = Permission::query()->create(['code' => 'product_manage', 'name' => '商品管理']);
        $admin->permissions()->attach($permission);
        $this->assertTrue($permission->is($admin->permissions()->sole()));
        $this->assertTrue($admin->is($permission->admins()->sole()));
        $admin->delete();
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertDatabaseHas('permissions', ['id' => $permission->id]);
    }

    public function test_permission_delete_cascades_pivot_but_preserves_admin(): void
    {
        $admin = Admin::factory()->create();
        $permission = Permission::query()->create(['code' => 'product_manage', 'name' => '商品管理']);
        $admin->permissions()->attach($permission);
        $permission->delete();
        $this->assertDatabaseCount('admin_permission', 0);
        $this->assertDatabaseHas('admins', ['id' => $admin->id]);
    }

    public function test_database_rejects_duplicate_code(): void
    {
        Permission::query()->create(['code' => 'product_manage', 'name' => '商品']);
        $this->expectException(QueryException::class);
        Permission::query()->create(['code' => 'product_manage', 'name' => '重複']);
    }

    public function test_database_rejects_duplicate_composite_key(): void
    {
        $admin = Admin::factory()->create();
        $permission = Permission::query()->create(['code' => 'product_manage', 'name' => '商品']);
        $admin->permissions()->attach($permission);
        $this->expectException(QueryException::class);
        $admin->permissions()->attach($permission);
    }

    public function test_database_rejects_missing_foreign_reference(): void
    {
        $admin = Admin::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('admin_permission')->insert(['admin_id' => $admin->id, 'permission_id' => 999]);
    }
}
