<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Permission;
use App\Models\User;
use App\Services\ShowcaseExportService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShowcaseExportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureStorage;

    protected function setUp(): void
    {
        parent::setUp();
        // Only SQLite :memory: and a unique ignored test directory; no daily storage.
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->fixtureStorage = storage_path('framework/testing/showcase-'.Str::uuid());
        File::ensureDirectoryExists($this->fixtureStorage);
        $this->app->useStoragePath($this->fixtureStorage);
        Storage::fake('public');
        $this->fixtures();
    }

    protected function tearDown(): void
    {
        DB::disableQueryLog();
        File::deleteDirectory($this->fixtureStorage);
        parent::tearDown();
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

    private function root(): string
    {
        return storage_path('app/private/showcase-exports');
    }

    private function assertNoPackage(): void
    {
        $this->assertSame([], is_dir($this->root()) ? File::directories($this->root()) : []);
    }

    public function test_export_has_only_exact_whitelist_columns_rows_files_and_valid_checksums_without_db_writes(): void
    {
        // Extra data is deliberately present; it must not be queried or packaged.
        DB::table('categories')->insert(['id' => 99, 'parent_id' => null, 'name' => 'Outside', 'status' => 'active', 'sort_order' => 0]);
        DB::table('products')->insert(['id' => 99, 'category_id' => 99, 'product_code' => 'OUTSIDE', 'name' => 'Outside', 'price' => 1, 'stock' => 1]);
        DB::table('product_images')->insert(['product_id' => 99, 'image_path' => 'products/outside.png']);
        DB::table('product_specifications')->insert(['product_id' => 99, 'spec_name' => 'Outside', 'spec_value' => 'Outside']);
        DB::table('product_variants')->insert(['product_id' => 99, 'option_name' => 'Outside', 'option_value' => 'Outside']);
        DB::table('recommended_products')->insert(['product_id' => 99]);
        DB::table('banners')->insert(['id' => 4, 'title' => 'Outside', 'image_path' => 'banners/outside.png', 'status' => 'active']);
        Permission::create(['code' => 'future_module', 'name' => 'Future']);
        User::factory()->create(['email' => 'private-member@example.test']);
        Admin::factory()->create(['email' => 'private-admin@example.test']);
        Storage::disk('public')->put('products/outside.png', 'never package');
        Storage::disk('public')->put('.env', 'SECRET-SENTINEL');
        DB::enableQueryLog();
        DB::flushQueryLog();
        $path = app(ShowcaseExportService::class)->export();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $expected = ['categories' => 12, 'products' => 16, 'product_images' => 48,
            'product_specifications' => 128, 'product_variants' => 3, 'banners' => 3,
            'recommended_products' => 4, 'cities' => 22, 'districts' => 368, 'permissions' => 7];
        $this->assertCount(10, $queries);
        foreach ($queries as $query) {
            $this->assertMatchesRegularExpression('/^select\b/i', $query['query']);
            $this->assertStringNotContainsString('*', $query['query']);
            preg_match('/from ["`]([^"`]+)["`]/i', $query['query'], $match);
            $this->assertArrayHasKey($match[1], $expected);
        }
        $manifest = json_decode(File::get($path.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(array_keys($expected), array_keys($manifest['tables']));
        foreach ($expected as $table => $count) {
            $entry = $manifest['tables'][$table];
            $bytes = File::get($path.'/'.$entry['path']);
            $rows = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount($count, $rows);
            $this->assertSame($count, $entry['count']);
            $this->assertSame(hash('sha256', $bytes), $entry['sha256']);
            $this->assertSame(array_column($rows, 'id'), $entry['ids']);
            $source = DB::table($table)->whereIn('id', $entry['ids'])->orderBy('id')->get()
                ->map(function ($row) use ($table) {
                    $data = (array) $row;
                    if ($table === 'products') $data['price'] = number_format((float) $data['price'], 2, '.', '');

                    return $data;
                })->all();
            // All current schema columns, IDs, FK values, sort/status/stock and timestamps survive.
            $this->assertSame($source, $rows);
        }
        $products = json_decode(File::get($path.'/data/products.json'), true);
        $this->assertSame(range(25, 40), array_column($products, 'id'));
        $this->assertSame('1299.90', $products[0]['price']);
        $this->assertSame('LEGACY-25', $products[0]['product_code']);
        $this->assertNull($products[0]['stock']);
        $this->assertSame(40, $products[15]['stock']);
        $this->assertSame('disabled', $products[15]['status']);
        $this->assertSame('2026-10-02 03:04:05', $products[0]['updated_at']);
        $this->assertSame(11, $products[0]['category_id']);
        $this->assertCount(51, $manifest['files']);
        foreach ($manifest['files'] as $entry) {
            $this->assertSame($entry['sha256'], hash_file('sha256', $path.'/'.$entry['path']));
            $this->assertSame($entry['size'], filesize($path.'/'.$entry['path']));
            $this->assertSame('image/png', $entry['mime']);
        }
        $this->assertCount(63, File::allFiles($path)); // 10 JSON + 51 images + manifest + checksum.
        $this->assertSame(hash_file('sha256', $path.'/manifest.json').'  manifest.json'."\n", File::get($path.'/manifest.sha256'));
        foreach (File::allFiles($path) as $file) {
            $this->assertStringNotContainsString('SECRET-SENTINEL', File::get($file));
            $this->assertStringNotContainsString('private-member@example.test', File::get($file));
            $this->assertStringNotContainsString('private-admin@example.test', File::get($file));
        }
        $this->assertDatabaseHas('products', ['id' => 99]);
        $this->assertDatabaseCount('admins', 1);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_command_publishes_private_package_and_never_overwrites_previous_exports(): void
    {
        $this->withoutMockingConsoleOutput();
        $code = $this->artisan('showcase:export');
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $first = File::directories($this->root());
        $this->assertCount(1, $first);
        $hash = hash_file('sha256', $first[0].'/manifest.json');
        $code = $this->artisan('showcase:export');
        $this->assertSame(0, $code, \Illuminate\Support\Facades\Artisan::output());
        $this->assertCount(2, File::directories($this->root()));
        $this->assertSame($hash, hash_file('sha256', $first[0].'/manifest.json'));
        $this->assertFalse(str_starts_with($first[0], Storage::disk('public')->path('')));
    }

    public static function badCounts(): array
    {
        return array_map(fn ($table) => [$table], ['categories', 'products', 'product_images',
            'product_specifications', 'product_variants', 'banners', 'recommended_products', 'cities', 'districts', 'permissions']);
    }

    #[DataProvider('badCounts')]
    public function test_wrong_count_for_any_whitelisted_table_fails_without_package(string $table): void
    {
        // An extra city/category avoids FK deletion; all operations are isolated fixture writes.
        if ($table === 'categories') {
            DB::table('products')->where('category_id', 18)->update(['category_id' => 17]);
            DB::table($table)->where('id', 18)->update(['id' => 19]);
        }
        elseif ($table === 'cities') DB::table($table)->insert(['id' => 999, 'name' => 'extra']);
        elseif ($table === 'products') DB::table($table)->where('id', 40)->delete();
        else DB::table($table)->where('id', DB::table($table)->max('id'))->delete();
        $this->artisan('showcase:export')->assertFailed();
        $this->assertNoPackage();
    }

    public static function unsafePaths(): array
    {
        return array_map(fn ($path) => [$path], ['../.env', '/products/25/image.png', 'products/../.env',
            'products/25/../../banners/a.png', 'products\\25\\a.png', 'products/%2e%2e/a.png',
            'products//a.png', 'products/.secret.png', 'banners/banner-1.png', 'products/25/a.png?token=x',
            "products/25/a\0.png", 'products/25/.env', 'https://evil.invalid/a.png']);
    }

    #[DataProvider('unsafePaths')]
    public function test_unsafe_or_wrong_kind_paths_fail_before_output(string $path): void
    {
        DB::table('product_images')->where('id', 1)->update(['image_path' => $path]);
        $this->artisan('showcase:export')->assertFailed();
        $this->assertNoPackage();
    }

    public function test_missing_image_wrong_content_duplicate_path_and_inactive_banner_are_rejected(): void
    {
        $disk = Storage::disk('public');
        $path = 'products/25/image-1.png';
        $disk->delete($path);
        $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
        $disk->put($path, 'not an image');
        $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
        $disk->put($path, $this->png());
        DB::table('product_images')->where('id', 2)->update(['image_path' => $path]);
        $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
        DB::table('product_images')->where('id', 2)->update(['image_path' => 'products/25/image-2.png']);
        DB::table('banners')->where('id', 1)->update(['status' => 'inactive']);
        $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
    }

    public function test_invalid_hierarchy_stock_mode_primary_and_required_fields_fail_closed(): void
    {
        foreach ([['categories', 11, 'parent_id', 12, 7], ['products', 26, 'category_id', 7, 12],
            ['products', 25, 'stock', 9, null], ['product_images', 1, 'is_primary', 1, 0],
            ['product_specifications', 1, 'spec_value', ' ', '內容1'],
            ['product_variants', 1, 'option_name', '不同軸', '顏色']] as [$table, $id, $field, $invalid, $original]) {
            DB::table($table)->where('id', $id)->update([$field => $invalid]);
            $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
            DB::table($table)->where('id', $id)->update([$field => $original]);
        }
    }

    public function test_valid_database_foreign_keys_outside_export_selection_are_rejected(): void
    {
        DB::table('categories')->insert(['id' => 99, 'parent_id' => null, 'name' => 'External root']);
        DB::table('categories')->where('id', 11)->update(['parent_id' => 99]);
        $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
        DB::table('categories')->where('id', 11)->update(['parent_id' => 7]);
        DB::table('categories')->insert(['id' => 100, 'parent_id' => 99, 'name' => 'External child']);
        DB::table('products')->where('id', 25)->update(['category_id' => 100]);
        $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
    }

    public function test_banners_cannot_reference_product_files(): void
    {
        DB::table('banners')->where('id', 1)->update(['image_path' => 'products/25/image-1.png']);
        $this->artisan('showcase:export')->assertFailed();
        $this->assertNoPackage();
    }

    public static function swappedCategoryRoles(): array
    {
        return [[7, 11], [10, 18]];
    }

    #[DataProvider('swappedCategoryRoles')]
    public function test_fixed_category_ids_reject_otherwise_valid_two_level_hierarchy(int $rootId, int $childId): void
    {
        // Exchange roles without breaking counts, two levels, FKs, or product child ownership.
        DB::table('categories')->where('parent_id', $rootId)->update(['parent_id' => 8]);
        DB::table('products')->where('category_id', $childId)->update(['category_id' => 12]);
        DB::table('categories')->where('id', $childId)->update(['parent_id' => null]);
        DB::table('categories')->where('id', $rootId)->update(['parent_id' => 8]);

        $this->assertDatabaseCount('categories', 12);
        $this->assertSame(4, DB::table('categories')->whereNull('parent_id')->count());
        $this->assertSame(8, DB::table('categories')->whereNotNull('parent_id')->count());
        $roots = DB::table('categories')->whereNull('parent_id')->pluck('id')->all();
        $this->assertSame(0, DB::table('categories')->whereNotNull('parent_id')->whereNotIn('parent_id', $roots)->count());
        $children = DB::table('categories')->whereNotNull('parent_id')->pluck('id')->all();
        $this->assertSame(0, DB::table('products')->whereNotIn('category_id', $children)->count());

        $this->artisan('showcase:export')->expectsOutputToContain('分類ID 7～10必須為主分類')->assertFailed();
        $this->assertNoPackage();
    }

    public function test_city_without_districts_rejected_even_when_counts_and_foreign_keys_are_valid(): void
    {
        DB::table('districts')->where('city_id', 22)->update(['city_id' => 1]);
        $this->assertDatabaseCount('cities', 22);
        $this->assertDatabaseCount('districts', 368);
        $this->assertSame(0, DB::table('districts')->where('city_id', 22)->count());
        $this->assertSame(0, DB::table('districts')->whereNotIn('city_id', DB::table('cities')->select('id'))->count());

        $this->artisan('showcase:export')->expectsOutputToContain('每個縣市必須至少有一筆行政區')->assertFailed();
        $this->assertNoPackage();
    }

    public function test_public_disk_symlink_cannot_export_nonwhitelisted_file(): void
    {
        $target = Storage::disk('public')->path('products/25/image-1.png');
        File::delete($target);
        $secret = $this->fixtureStorage.'/outside.png';
        File::put($secret, $this->png());
        if (! @symlink($secret, $target)) $this->markTestSkipped('OS does not permit test symlinks.');
        try {
            $this->artisan('showcase:export')->assertFailed(); $this->assertNoPackage();
        } finally {
            unlink($target);
        }
    }

    public function test_late_image_read_failure_removes_partial_package(): void
    {
        $disk = Storage::disk('public');
        // Actual preflight succeeds; a late stream failure happens after JSON writes.
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('readStream')->andThrow(new \RuntimeException('fixture stream failure'));
        Storage::shouldReceive('disk')->with('public')->andReturn($mock);
        $this->artisan('showcase:export')->assertFailed();
        $this->assertNoPackage();
    }

    public function test_file_changed_after_preflight_never_publishes_package(): void
    {
        $disk = Storage::disk('public');
        $mock = \Mockery::mock($disk)->makePartial();
        $mock->shouldReceive('readStream')->andReturnUsing(function () {
            $stream = fopen('php://temp', 'r+');
            fwrite($stream, 'changed since preflight');
            rewind($stream);

            return $stream;
        });
        Storage::shouldReceive('disk')->with('public')->andReturn($mock);
        $this->artisan('showcase:export')->assertFailed();
        $this->assertNoPackage();
    }

    public function test_json_write_failure_cleans_partial_and_preserves_previous_package(): void
    {
        $existing = app(ShowcaseExportService::class)->export();
        $hash = hash_file('sha256', $existing.'/manifest.json');
        // A filesystem obstacle after preflight and some real writes, not an early validation failure.
        File::partialMock()->shouldReceive('ensureDirectoryExists')->andReturnUsing(function ($path) {
            if (! is_dir($path)) mkdir($path, 0700, true);
            if (str_ends_with($path, '/data')) {
                $obstacle = $path.'/products.json';
                if (! is_dir($obstacle)) mkdir($obstacle);
            }
        });
        $this->artisan('showcase:export')->assertFailed();
        $this->assertSame([str_replace('\\', '/', $existing)], array_map(fn ($p) => str_replace('\\', '/', $p), File::directories($this->root())));
        $this->assertSame($hash, hash_file('sha256', $existing.'/manifest.json'));
    }
}
