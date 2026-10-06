<?php

namespace Tests\Feature;

use App\Models\{Admin, Banner, Category, Product, ProductVariant, RecommendedProduct, User};
use Illuminate\Database\QueryException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HomeContentPublicApiTest extends TestCase
{
    use RefreshDatabase;

    private function product(array $attributes = []): Product
    {
        $root = Category::create(['name' => '主分類', 'status' => 'active']);
        $child = Category::create(['name' => '子分類', 'parent_id' => $root->id, 'status' => 'active']);

        return Product::factory()->create(['category_id' => $child->id, ...$attributes]);
    }

    private function banner(array $attributes = []): Banner
    {
        return Banner::create(['title' => '輪播', 'image_path' => 'banners/fixture.jpg', ...$attributes]);
    }

    public function test_schema_defaults_and_nullable_columns(): void
    {
        $this->assertEqualsCanonicalizing([
            'id', 'title', 'subtitle', 'image_path', 'button_text', 'link_url',
            'sort_order', 'status', 'created_at', 'updated_at',
        ], Schema::getColumnListing('banners'));
        $columns = collect(Schema::getColumns('banners'))->keyBy('name');
        foreach (['subtitle', 'button_text', 'link_url'] as $name) {
            $this->assertTrue($columns[$name]['nullable']);
        }
        foreach (['title', 'image_path', 'sort_order', 'status'] as $name) {
            $this->assertFalse($columns[$name]['nullable']);
        }
        $banner = $this->banner()->fresh();
        $this->assertSame('active', $banner->status);
        $this->assertSame(0, $banner->sort_order);
        $this->assertNull($banner->subtitle);
        $this->assertNull($banner->button_text);
        $this->assertNull($banner->link_url);
        $this->assertEqualsCanonicalizing(['id', 'product_id', 'sort_order', 'created_at', 'updated_at'], Schema::getColumnListing('recommended_products'));
        $relation = RecommendedProduct::create(['product_id' => $this->product()->id])->fresh();
        $this->assertSame(0, $relation->sort_order);
    }

    public function test_mysql_schema_compilation_has_unsigned_types_lengths_and_constraints(): void
    {
        // Compile the actual migration callbacks without connecting to MySQL.
        // This verifies DDL intent, not a real MySQL/InnoDB execution.
        $connection = new MySqlConnection(fn () => throw new \RuntimeException('No real DB connection allowed'), 'schema_only', '', ['version' => '8.0.0']);
        $connection->useDefaultSchemaGrammar();
        $sql = [];
        Schema::partialMock()->shouldReceive('create')->twice()->andReturnUsing(function ($table, $callback) use ($connection, &$sql) {
            $blueprint = new Blueprint($connection, $table);
            $blueprint->create();
            $callback($blueprint);
            $sql[$table] = implode('; ', $blueprint->toSql());
        });
        (require database_path('migrations/2026_10_06_000000_create_banners_table.php'))->up();
        (require database_path('migrations/2026_10_06_000001_create_recommended_products_table.php'))->up();
        foreach ($sql as $ddl) $this->assertStringContainsString('`sort_order` int unsigned not null default', $ddl);
        foreach (['title' => 150, 'subtitle' => 255, 'image_path' => 255, 'button_text' => 50, 'link_url' => 255, 'status' => 20] as $field => $length) {
            $this->assertStringContainsString('`'.$field.'` varchar('.$length.')', $sql['banners']);
        }
        $this->assertMatchesRegularExpression('/add unique `[^`]+`\(`product_id`\)/', $sql['recommended_products']);
        $this->assertStringContainsString('references `products` (`id`) on delete cascade', $sql['recommended_products']);
    }

    public function test_migration_down_drops_only_new_tables_and_can_recreate(): void
    {
        $banners = require database_path('migrations/2026_10_06_000000_create_banners_table.php');
        $recommendations = require database_path('migrations/2026_10_06_000001_create_recommended_products_table.php');
        $recommendations->down();
        $banners->down();
        $this->assertFalse(Schema::hasTable('banners'));
        $this->assertFalse(Schema::hasTable('recommended_products'));
        $this->assertTrue(Schema::hasTable('products'));
        $banners->up();
        $recommendations->up();
        $this->assertTrue(Schema::hasTable('banners'));
        $this->assertTrue(Schema::hasTable('recommended_products'));
    }

    public function test_routes_are_public_get_only_and_no_admin_home_content_routes_exist(): void
    {
        $routes = collect(Route::getRoutes());
        foreach (['api/banners', 'api/recommended-products'] as $uri) {
            $route = $routes->first(fn ($route) => $route->uri() === $uri);
            $this->assertNotNull($route);
            $this->assertSame(['GET', 'HEAD'], $route->methods());
            $this->assertSame(['api'], $route->gatherMiddleware());
        }
        $this->assertFalse($routes->contains(fn ($route) => str_starts_with($route->uri(), 'api/admin/banners') || str_starts_with($route->uri(), 'api/admin/recommended-products')));
    }

    public function test_relation_cardinality_and_cascade_on_unreferenced_product_delete(): void
    {
        $product = $this->product();
        $relation = RecommendedProduct::create(['product_id' => $product->id]);
        $this->assertTrue($relation->product->is($product));
        $this->assertTrue($product->recommendedProduct->is($relation));
        $product->delete();
        $this->assertDatabaseMissing('recommended_products', ['id' => $relation->id]);
    }

    public function test_duplicate_product_id_is_rejected_by_database_unique(): void
    {
        $id = $this->product()->id;
        RecommendedProduct::create(['product_id' => $id]);
        $this->expectException(QueryException::class);
        RecommendedProduct::create(['product_id' => $id]);
    }

    public function test_missing_product_id_is_rejected_by_database_fk(): void
    {
        $this->expectException(QueryException::class);
        RecommendedProduct::create(['product_id' => 999999]);
    }

    public function test_public_empty_collections_are_200(): void
    {
        $this->getJson('/api/banners')->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/recommended-products')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_banners_are_active_only_stably_sorted_with_exact_public_fields(): void
    {
        $a = $this->banner(['sort_order' => 2]);
        $b = $this->banner(['sort_order' => 0, 'subtitle' => '介紹', 'button_text' => '商品', 'link_url' => '/products']);
        $c = $this->banner(['sort_order' => 0]);
        $this->banner(['status' => 'inactive', 'sort_order' => 0]);
        $data = $this->getJson('/api/banners')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame([$b->id, $c->id, $a->id], array_column($data, 'id'));
        $this->assertEqualsCanonicalizing(['id', 'title', 'subtitle', 'image_url', 'button_text', 'link_url', 'sort_order'], array_keys($data[0]));
        $this->assertSame(asset('storage/banners/fixture.jpg'), $data[0]['image_url']);
        $this->assertSame('介紹', $data[0]['subtitle']);
        $this->assertNull($data[1]['button_text']);
    }

    public function test_recommendation_order_uses_relation_id_not_product_id_and_summary_is_compatible(): void
    {
        $a = $this->product();
        $b = $this->product();
        $c = $this->product();
        $b->images()->create(['image_path' => 'products/fixture.png', 'is_primary' => true, 'image_type' => 'gallery', 'sort_order' => 0]);
        $b->specifications()->create(['spec_name' => '材質', 'spec_value' => '鋼', 'sort_order' => 0]);
        RecommendedProduct::create(['product_id' => $b->id, 'sort_order' => 0]);
        RecommendedProduct::create(['product_id' => $a->id, 'sort_order' => 0]);
        RecommendedProduct::create(['product_id' => $c->id, 'sort_order' => 2]);
        $data = $this->getJson('/api/recommended-products')->assertOk()->assertJsonCount(3, 'data')->json('data');
        $this->assertSame([$b->id, $a->id, $c->id], array_column($data, 'id'));
        $this->assertEqualsCanonicalizing(['id', 'product_code', 'name', 'price', 'short_description', 'description', 'stock', 'status', 'category', 'images'], array_keys($data[0]));
        $this->assertSame($b->category_id, $data[0]['category']['id']);
        $this->assertCount(1, $data[0]['images']);
        $this->assertSame(asset('storage/products/fixture.png'), $data[0]['images'][0]['image_url']);
    }

    public static function unavailable(): array
    {
        return ['inactive product' => ['inactive'], 'disabled product' => ['disabled'],
            'inactive child' => ['child'], 'inactive parent' => ['parent'],
            'direct root' => ['root'], 'third layer' => ['third']];
    }

    #[DataProvider('unavailable')]
    public function test_c07_excludes_without_removing_relation_and_restoration_reuses_it(string $state): void
    {
        $product = $this->product();
        $child = $product->category;
        $parent = $child->parent;
        $relation = RecommendedProduct::create(['product_id' => $product->id]);
        if (in_array($state, ['inactive', 'disabled'])) $product->update(['status' => $state]);
        if ($state === 'child') $child->update(['status' => 'inactive']);
        if ($state === 'parent') $parent->update(['status' => 'inactive']);
        if ($state === 'root') $product->update(['category_id' => $parent->id]);
        if ($state === 'third') $parent->update(['parent_id' => Category::create(['name' => '額外層', 'status' => 'active'])->id]);
        $before = [$product->fresh()->getAttributes(), $child->fresh()->getAttributes(), $parent->fresh()->getAttributes()];
        $this->getJson('/api/recommended-products')->assertOk()->assertExactJson(['data' => []]);
        $this->assertDatabaseHas('recommended_products', ['id' => $relation->id, 'product_id' => $product->id]);
        $this->assertEquals($before, [$product->fresh()->getAttributes(), $child->fresh()->getAttributes(), $parent->fresh()->getAttributes()]);
        $product->update(['status' => 'active', 'category_id' => $child->id]);
        $child->update(['status' => 'active']);
        $parent->update(['status' => 'active', 'parent_id' => null]);
        $this->getJson('/api/recommended-products')->assertOk()->assertJsonPath('data.0.id', $product->id);
        $this->assertSame($relation->id, $product->fresh()->recommendedProduct->id);
    }

    public function test_zero_stock_plain_and_variant_products_remain_public(): void
    {
        $plain = $this->product(['stock' => 0]);
        $variant = $this->product(['stock' => null]);
        ProductVariant::create(['product_id' => $variant->id, 'option_name' => '色', 'option_value' => '黑', 'stock' => 0, 'status' => 'inactive']);
        foreach ([$plain, $variant] as $product) RecommendedProduct::create(['product_id' => $product->id]);
        $this->getJson('/api/recommended-products')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.stock', 0)->assertJsonPath('data.1.stock', null)->assertJsonMissingPath('data.1.variants');
    }

    public function test_queries_are_constant_for_one_and_ten_recommendations(): void
    {
        $product = $this->product();
        RecommendedProduct::create(['product_id' => $product->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/recommended-products')->assertOk();
        $one = DB::getQueryLog();
        DB::disableQueryLog();
        for ($i = 0; $i < 9; $i++) RecommendedProduct::create(['product_id' => $this->product()->id]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/recommended-products')->assertOk()->assertJsonCount(10, 'data');
        $ten = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(3, $one);
        $this->assertCount(3, $ten);
        foreach ($ten as $query) {
            $this->assertDoesNotMatchRegularExpression('/product_specifications|product_variants|order_items|cart_items/', $query['query']);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->getJson('/api/banners')->assertOk();
        $this->assertCount(1, DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_member_and_admin_identity_do_not_change_public_results(): void
    {
        $this->banner();
        $product = $this->product();
        RecommendedProduct::create(['product_id' => $product->id]);
        $banners = $this->getJson('/api/banners')->assertOk()->json();
        $products = $this->getJson('/api/recommended-products')->assertOk()->json();
        foreach ([[User::factory()->create(), 'web'], [Admin::factory()->create(), 'admin']] as [$identity, $guard]) {
            $this->actingAs($identity, $guard);
            $this->getJson('/api/banners')->assertOk()->assertExactJson($banners);
            $this->getJson('/api/recommended-products')->assertOk()->assertExactJson($products);
        }
    }
}
