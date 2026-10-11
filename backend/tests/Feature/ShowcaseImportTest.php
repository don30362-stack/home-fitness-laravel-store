<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShowcaseExportService;
use App\Services\ShowcaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowcaseImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureStorage;
    private string $package;
    private array $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->fixtureStorage = storage_path('framework/testing/showcase-import-'.Str::uuid());
        File::ensureDirectoryExists($this->fixtureStorage);
        $this->app->useStoragePath($this->fixtureStorage);
        Storage::fake('public');
        $this->fixtures();
        $this->package = app(ShowcaseExportService::class)->export();
        foreach (ShowcaseExportService::COLUMNS as $table => $columns) {
            $this->original[$table] = json_decode(File::get($this->package.'/data/'.$table.'.json'), true);
        }
        foreach (array_reverse(array_keys(ShowcaseExportService::COLUMNS)) as $table) {
            if ($table === 'categories') DB::table($table)->whereNotNull('parent_id')->delete();
            DB::table($table)->delete();
        }
        File::deleteDirectory(Storage::disk('public')->path(''));
        File::ensureDirectoryExists(Storage::disk('public')->path(''));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixtureStorage);
        parent::tearDown();
    }

    private function command(bool $checkOnly = false)
    {
        return $this->artisan('showcase:import', ['package' => $this->package, '--database' => ':memory:', '--check-only' => $checkOnly]);
    }

    private function pristine(): void
    {
        foreach (array_keys(ShowcaseExportService::COLUMNS) as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], glob(storage_path('app/private/showcase-import-*')) ?: []);
    }

    private function manifest(): array
    {
        return json_decode(File::get($this->package.'/manifest.json'), true);
    }

    private function saveManifest(array $manifest): void
    {
        $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        File::put($this->package.'/manifest.json', $json);
        File::put($this->package.'/manifest.sha256', hash('sha256', $json)."  manifest.json\n");
    }

    private function changeRows(string $table, callable $change): void
    {
        $rows = $this->original[$table];
        $change($rows);
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        File::put($this->package.'/data/'.$table.'.json', $json);
        $manifest = $this->manifest();
        $manifest['tables'][$table]['sha256'] = hash('sha256', $json);
        $this->saveManifest($manifest);
    }

    private function fixtures(): void
    {
        $timestamp = ['created_at' => '2026-10-01 01:02:03', 'updated_at' => '2026-10-02 03:04:05'];
        for ($id = 7; $id <= 18; $id++) {
            DB::table('categories')->insert(['id' => $id, 'parent_id' => $id < 11 ? null : 7 + (($id - 11) % 4),
                'name' => 'Category '.$id, 'status' => 'active', 'sort_order' => 19 - $id] + $timestamp);
        }
        for ($id = 25; $id <= 40; $id++) {
            DB::table('products')->insert(['id' => $id, 'category_id' => 11 + (($id - 25) % 8),
                'product_code' => 'LEGACY-'.$id, 'name' => 'Product '.$id, 'price' => '1299.90',
                'short_description' => '中文介紹', 'description' => '完整說明', 'stock' => $id === 25 ? null : $id,
                'low_stock_threshold' => 5, 'status' => $id === 40 ? 'disabled' : 'active'] + $timestamp);
            for ($n = 1; $n <= 3; $n++) {
                $path = 'products/'.$id.'/image-'.$n.'.png';
                Storage::disk('public')->put($path, $this->png());
                DB::table('product_images')->insert(['product_id' => $id, 'image_path' => $path,
                    'image_type' => $n === 3 ? 'detail' : 'gallery', 'is_primary' => $n === 2,
                    'sort_order' => 3 - $n] + $timestamp);
            }
            for ($n = 1; $n <= 8; $n++) {
                DB::table('product_specifications')->insert(['product_id' => $id, 'spec_name' => '規格'.$n,
                    'spec_value' => '內容'.$n, 'sort_order' => 8 - $n] + $timestamp);
            }
        }
        for ($n = 1; $n <= 3; $n++) {
            DB::table('product_variants')->insert(['product_id' => 25, 'option_name' => '顏色',
                'option_value' => '選項'.$n, 'stock' => $n, 'status' => $n === 3 ? 'inactive' : 'active'] + $timestamp);
            Storage::disk('public')->put('banners/banner-'.$n.'.png', $this->png());
            DB::table('banners')->insert(['id' => $n, 'title' => 'Banner '.$n, 'subtitle' => '副標題',
                'image_path' => 'banners/banner-'.$n.'.png', 'button_text' => '查看',
                'link_url' => '/products', 'sort_order' => 4 - $n, 'status' => 'active'] + $timestamp);
        }
        foreach ([25, 26, 27, 28] as $id) {
            DB::table('recommended_products')->insert(['product_id' => $id, 'sort_order' => 40 - $id] + $timestamp);
        }
        for ($id = 1; $id <= 22; $id++) DB::table('cities')->insert(['id' => $id, 'name' => 'City '.$id]);
        for ($id = 1; $id <= 368; $id++) {
            DB::table('districts')->insert(['id' => $id, 'city_id' => 1 + (($id - 1) % 22), 'name' => 'District '.$id, 'postal_code' => '001']);
        }
        foreach (Permission::CATALOG as $code => $name) DB::table('permissions')->insert(compact('code', 'name'));
    }

    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9e8AAAAASUVORK5CYII=');
    }


    public function test_round_trip_preserves_all_611_rows_51_images_and_original_values(): void
    {
        $this->command()->expectsOutputToContain('611筆／51張圖片')->assertSuccessful();
        $total = 0;
        foreach ($this->original as $table => $rows) {
            $actual = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            if ($table === 'products') foreach ($actual as &$row) $row['price'] = number_format((float) $row['price'], 2, '.', '');
            $this->assertSame($rows, $actual, $table);
            $total += count($actual);
        }
        $this->assertSame(611, $total);
        $this->assertCount(51, Storage::disk('public')->allFiles());
        foreach ($this->manifest()['files'] as $asset) {
            $this->assertSame($asset['sha256'], hash_file('sha256', Storage::disk('public')->path($asset['source_path'])));
        }
        $this->assertSame(1, DB::selectOne('PRAGMA foreign_keys')->foreign_keys);
        foreach (['users', 'admins', 'orders', 'order_items', 'carts', 'cart_items', 'admin_permission', 'sessions', 'user_addresses', 'personal_access_tokens'] as $table) $this->assertDatabaseCount($table, 0);
        $this->command()->assertFailed();
        $this->assertDatabaseCount('products', 16);
        $this->assertCount(51, Storage::disk('public')->allFiles());
    }

    public function test_check_only_performs_no_writes_and_requires_explicit_matching_database(): void
    {
        DB::enableQueryLog();
        $this->command(true)->assertSuccessful();
        foreach (DB::getQueryLog() as $query) $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|create|drop|alter)/i', $query['query']);
        DB::disableQueryLog();
        $this->assertFalse(is_file(storage_path('app/private/showcase-import.lock')));
        $this->pristine();
        foreach (['', 'another_database'] as $name) {
            $this->artisan('showcase:import', ['package' => $this->package, '--database' => $name])->assertFailed();
            $this->pristine();
        }
    }

    public static function invalidPackages(): array
    {
        return array_map(fn ($case) => [$case], ['json-hash', 'manifest-hash', 'image-hash', 'extra-table', 'extra-file',
            'extra-directory', 'extra-column', 'missing-column', 'traversal', 'bad-fk', 'bad-category-role', 'empty-city',
            'bad-version', 'id-range', 'duplicate-id', 'unknown-permission', 'inactive-banner', 'missing-file',
            'mime', 'size', 'image-reference', 'bad-price', 'bad-count', 'bad-stock-mode']);
    }

    #[DataProvider('invalidPackages')]
    public function test_invalid_packages_fail_closed_even_with_recomputed_checksums(string $case): void
    {
        $manifest = $this->manifest();
        switch ($case) {
            case 'json-hash': File::append($this->package.'/data/products.json', ' '); break;
            case 'manifest-hash': File::append($this->package.'/manifest.json', ' '); break;
            case 'image-hash': File::put($this->package.'/'.$manifest['files'][0]['path'], 'changed'); break;
            case 'extra-table': $manifest['tables']['users'] = []; $this->saveManifest($manifest); break;
            case 'extra-file': File::put($this->package.'/admins.json', '[]'); break;
            case 'extra-directory': File::makeDirectory($this->package.'/secret'); break;
            case 'extra-column': $this->changeRows('products', function (&$rows) { $rows[0]['password'] = 'not allowed'; }); break;
            case 'missing-column': $this->changeRows('products', function (&$rows) { unset($rows[0]['description']); }); break;
            case 'traversal': $this->changeRows('product_images', function (&$rows) { $rows[0]['image_path'] = 'products/../../secret.png'; }); break;
            case 'bad-fk': $this->changeRows('products', function (&$rows) { $rows[0]['category_id'] = 999; }); break;
            case 'bad-category-role': $this->changeRows('categories', function (&$rows) { $rows[0]['parent_id'] = 8; }); break;
            case 'empty-city': $this->changeRows('districts', function (&$rows) { foreach ($rows as &$row) if ($row['city_id'] === 22) $row['city_id'] = 1; }); break;
            case 'bad-version': $manifest['format_version'] = 2; $this->saveManifest($manifest); break;
            case 'id-range': $this->changeRows('products', function (&$rows) { $rows[15]['id'] = 41; }); $manifest = $this->manifest(); $manifest['tables']['products']['ids'][15] = 41; $this->saveManifest($manifest); break;
            case 'duplicate-id': $this->changeRows('products', function (&$rows) { $rows[1]['id'] = 25; }); break;
            case 'unknown-permission': $this->changeRows('permissions', function (&$rows) { $rows[0]['code'] = 'future_module'; }); break;
            case 'inactive-banner': $this->changeRows('banners', function (&$rows) { $rows[0]['status'] = 'inactive'; }); break;
            case 'missing-file': File::delete($this->package.'/'.$manifest['files'][0]['path']); break;
            case 'mime': $manifest['files'][0]['mime'] = 'image/jpeg'; $this->saveManifest($manifest); break;
            case 'size': $manifest['files'][0]['size']++; $this->saveManifest($manifest); break;
            case 'image-reference': $manifest['files'][0]['row_id'] = 999; $this->saveManifest($manifest); break;
            case 'bad-price': $this->changeRows('products', function (&$rows) { $rows[0]['price'] = '12.345'; }); break;
            case 'bad-count': $manifest['tables']['districts']['count'] = 367; $this->saveManifest($manifest); break;
            case 'bad-stock-mode': $this->changeRows('products', function (&$rows) { $rows[0]['stock'] = 9; }); break;
        }
        $this->command()->assertFailed();
        $this->pristine();
    }

    public function test_nonempty_private_or_whitelist_tables_and_missing_migrations_are_rejected(): void
    {
        $user = User::factory()->create();
        $this->command()->assertFailed();
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $user->delete();
        $admin = Admin::factory()->create();
        $this->command()->assertFailed();
        $this->assertDatabaseHas('admins', ['id' => $admin->id]);
        $admin->delete();
        DB::table('cities')->insert(['id' => 999, 'name' => 'existing']);
        $this->command()->assertFailed();
        $this->assertDatabaseHas('cities', ['id' => 999]);
        DB::table('cities')->delete();
        DB::table('migrations')->where('migration', '2026_10_06_000002_create_permissions_table')->delete();
        $this->command()->assertFailed();
        $this->pristine();
    }

    public function test_existing_image_is_never_overwritten_or_deleted(): void
    {
        Storage::disk('public')->put('products/25/image-1.png', 'existing file');
        $this->command()->assertFailed();
        $this->assertSame('existing file', Storage::disk('public')->get('products/25/image-1.png'));
        $this->assertDatabaseCount('products', 0);
    }

    public static function forbiddenTables(): array
    {
        return [['users'], ['admins'], ['orders'], ['carts'], ['admin_permission']];
    }

    #[DataProvider('forbiddenTables')]
    public function test_private_table_cannot_be_added_to_manifest_even_with_valid_manifest_checksum(string $table): void
    {
        $manifest = $this->manifest();
        $manifest['tables'][$table] = ['path' => 'data/'.$table.'.json', 'count' => 0, 'ids' => [], 'sha256' => hash('sha256', '[]')];
        File::put($this->package.'/data/'.$table.'.json', '[]');
        $this->saveManifest($manifest);
        $this->command()->assertFailed();
        $this->pristine();
    }

    public function test_conflicting_file_created_after_preflight_is_preserved_and_prior_writes_compensated(): void
    {
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into "recommended_products"')) {
                Storage::disk('public')->put('products/25/image-3.png', 'created by another process');
            }
        });
        $this->command()->assertFailed();
        foreach (array_keys(ShowcaseExportService::COLUMNS) as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame(['products/25/image-3.png'], Storage::disk('public')->allFiles());
        $this->assertSame('created by another process', Storage::disk('public')->get('products/25/image-3.png'));
    }

    public function test_existing_session_or_password_reset_blocks_initialization(): void
    {
        DB::table('sessions')->insert(['id' => 'isolated-session', 'payload' => '', 'last_activity' => 0]);
        $this->command()->assertFailed();
        $this->assertDatabaseCount('sessions', 1);
        DB::table('sessions')->delete();
        DB::table('password_reset_tokens')->insert(['email' => 'synthetic@example.test', 'token' => 'fixture-only']);
        $this->command()->assertFailed();
        $this->assertDatabaseCount('password_reset_tokens', 1);
        $this->pristine();
    }

    public function test_public_package_directory_is_rejected(): void
    {
        $publicPackage = Storage::disk('public')->path('package');
        File::copyDirectory($this->package, $publicPackage);
        $this->artisan('showcase:import', ['package' => $publicPackage, '--database' => ':memory:', '--check-only' => true])->assertFailed();
        $this->assertDatabaseCount('products', 0);
    }

    public function test_late_database_insert_failure_rolls_back_all_tables_and_cleans_staging(): void
    {
        $failedAfterInsert = false;
        DB::listen(function ($query) use (&$failedAfterInsert) {
            if (str_starts_with($query->sql, 'insert into "recommended_products"')) {
                $this->assertDatabaseCount('products', 16);
                $this->assertDatabaseCount('recommended_products', 4);
                $failedAfterInsert = true;
                throw new \RuntimeException('late insert fixture failure');
            }
        });
        $this->command()->assertFailed();
        $this->assertTrue($failedAfterInsert);
        $this->pristine();
    }

    public function test_late_image_failure_rolls_back_db_and_removes_only_this_runs_images(): void
    {
        $calls = 0;
        File::partialMock()->shouldReceive('ensureDirectoryExists')->andReturnUsing(function ($path, $mode = 0755) use (&$calls) {
            if (str_contains(str_replace('\\', '/', $path), '/disks/public/products/')) {
                if (++$calls === 3) throw new \RuntimeException('late image fixture failure');
            }
            if (! is_dir($path)) mkdir($path, $mode, true);
        });
        Storage::disk('public')->put('unrelated/keep.txt', 'keep');
        $this->command()->assertFailed();
        foreach (array_keys(ShowcaseExportService::COLUMNS) as $table) $this->assertDatabaseCount($table, 0);
        $this->assertSame(['unrelated/keep.txt'], Storage::disk('public')->allFiles());
        $this->assertSame('keep', Storage::disk('public')->get('unrelated/keep.txt'));
        $this->assertSame([], glob(storage_path('app/private/showcase-import-*')) ?: []);
        $this->assertSame(3, $calls);
    }

    public function test_package_symlink_is_rejected(): void
    {
        $path = $this->package.'/files/products/25/image-1.png';
        File::delete($path);
        $outside = $this->fixtureStorage.'/outside.png';
        File::put($outside, $this->png());
        if (! @symlink($outside, $path)) $this->markTestSkipped('OS does not permit test symlinks.');
        try { $this->command()->assertFailed(); $this->pristine(); } finally { unlink($path); }
    }
}
