<?php

namespace Tests\Feature;

use App\Models\{Admin, Category, Product, ProductVariant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, Event};
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminCategoryDeleteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function path(int|string $id): string { return '/api/admin/categories/'.$id; }
    private function login(): void { $this->actingAs(Admin::factory()->create(), 'admin'); }
    private function category(array $attributes = []): Category
    {
        return Category::create($attributes + ['name' => 'delete fixture', 'status' => 'active', 'sort_order' => 0]);
    }

    public function test_guest_and_member_cannot_delete(): void
    {
        $root = $this->category();
        $this->deleteJson($this->path($root->id))->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->deleteJson($this->path($root->id))->assertUnauthorized();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('categories', ['id' => $root->id]);
    }

    public function test_disabled_admin_is_rejected_without_revoking_member(): void
    {
        $root = $this->category();
        $user = User::factory()->create();
        $this->actingAs($user, 'web')->actingAs(Admin::factory()->disabled()->create(), 'admin')
            ->withSession(['member_marker' => 'preserve'])
            ->deleteJson($this->path($root->id))->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('preserve', session('member_marker'));
        $this->assertDatabaseHas('categories', ['id' => $root->id]);
    }

    public function test_admin_delete_does_not_trigger_disabled_member_c06(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);
        $cart = $user->cart()->create();
        $root = $this->category();
        $this->actingAs($user, 'web');
        $this->login();
        $this->withSession(['member_marker' => 'preserve'])->deleteJson($this->path($root->id))->assertOk()->assertExactJson(['message' => '分類已刪除。']);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame('preserve', session('member_marker'));
        $this->assertDatabaseHas('carts', ['id' => $cart->id, 'user_id' => $user->id]);
    }

    public static function emptyCategories(): array
    {
        return [[false, 'active'], [false, 'inactive'], [true, 'active'], [true, 'inactive']];
    }

    #[DataProvider('emptyCategories')]
    public function test_empty_category_can_be_deleted_regardless_of_status(bool $child, string $status): void
    {
        $parent = $child ? $this->category(['name' => 'parent']) : null;
        $target = $this->category(['parent_id' => $parent?->id, 'status' => $status]);
        $other = $this->category(['name' => 'unrelated', 'status' => 'inactive', 'sort_order' => 8]);
        $before = $other->fresh()->getAttributes();
        $this->login();
        $this->deleteJson($this->path($target->id))->assertOk()->assertExactJson(['message' => '分類已刪除。']);
        $this->assertDatabaseMissing('categories', ['id' => $target->id]);
        $this->assertSame($before, $other->fresh()->getAttributes());
        $this->assertDatabaseCount('categories', $child ? 2 : 1);
        if ($parent) $this->assertDatabaseHas('categories', ['id' => $parent->id, 'status' => 'active']);
    }

    public static function childStates(): array { return [['active'], ['inactive']]; }

    #[DataProvider('childStates')]
    public function test_any_child_blocks_delete_without_changing_either_row(string $status): void
    {
        $root = $this->category();
        $child = $this->category(['parent_id' => $root->id, 'status' => $status]);
        $before = [$root->fresh()->getAttributes(), $child->fresh()->getAttributes()];
        $this->login();
        $this->deleteJson($this->path($root->id))->assertUnprocessable()->assertJsonPath('errors.category.0', '此分類仍有子分類，無法刪除。');
        $this->assertSame($before, [$root->fresh()->getAttributes(), $child->fresh()->getAttributes()]);
    }

    public static function productStates(): array
    {
        return [[false, 'active'], [false, 'inactive'], [false, 'disabled'], [true, 'active'], [true, 'inactive'], [true, 'disabled']];
    }

    #[DataProvider('productStates')]
    public function test_any_product_blocks_even_a_malformed_root_reference(bool $rootProduct, string $status): void
    {
        $root = $this->category();
        $target = $rootProduct ? $root : $this->category(['parent_id' => $root->id]);
        $product = Product::factory()->create(['category_id' => $target->id, 'status' => $status, 'stock' => 10]);
        $before = [$target->fresh()->getAttributes(), $product->fresh()->getAttributes()];
        $this->login();
        $this->deleteJson($this->path($target->id))->assertUnprocessable()->assertJsonPath('errors.category.0', '此分類仍有商品使用，無法刪除。');
        $this->assertSame($before, [$target->fresh()->getAttributes(), $product->fresh()->getAttributes()]);
    }

    public function test_child_with_grandchild_is_not_deleted_or_repaired(): void
    {
        $root = $this->category();
        $child = $this->category(['parent_id' => $root->id]);
        $grandchild = $this->category(['parent_id' => $child->id, 'status' => 'inactive']);
        $before = DB::table('categories')->orderBy('id')->get()->toArray();
        $this->login();
        $this->deleteJson($this->path($child->id))->assertUnprocessable()->assertJsonPath('errors.category.0', '此分類仍有子分類，無法刪除。');
        $this->assertEquals($before, DB::table('categories')->orderBy('id')->get()->toArray());
        $this->assertDatabaseHas('categories', ['id' => $grandchild->id, 'parent_id' => $child->id]);
    }

    public function test_failed_delete_leaves_product_children_stock_cart_and_order_snapshots_untouched(): void
    {
        $root = $this->category();
        $child = $this->category(['parent_id' => $root->id]);
        $product = Product::factory()->create(['category_id' => $child->id, 'status' => 'disabled', 'stock' => null]);
        $variant = $product->variants()->create(['option_name' => '色', 'option_value' => '黑', 'stock' => 7, 'status' => 'inactive']);
        $product->specifications()->create(['spec_name' => '材質', 'spec_value' => '鋼', 'sort_order' => 0]);
        $product->images()->create(['image_path' => 'products/test.jpg', 'image_type' => 'gallery', 'is_primary' => true, 'sort_order' => 0]);
        $user = User::factory()->create();
        $user->cart()->create()->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id, 'quantity' => 2]);
        $order = $user->orders()->create([
            'order_no'=>'CATEGORY-DELETE-HISTORY', 'purchaser_name'=>'歷史', 'purchaser_phone'=>'0912345678', 'purchaser_email'=>'fake@example.test',
            'recipient_name'=>'歷史', 'recipient_phone'=>'0912345678', 'postal_code'=>'100', 'city'=>'市', 'district'=>'區', 'address'=>'地址',
            'shipping_method'=>'home_delivery', 'shipping_fee'=>100, 'subtotal'=>200, 'total_amount'=>300, 'payment_method'=>'cod', 'payment_status'=>'unpaid', 'order_status'=>'cancelled',
        ]);
        $order->items()->create(['product_id'=>$product->id, 'product_variant_id'=>$variant->id, 'product_code_snapshot'=>$product->product_code,
            'product_name_snapshot'=>'歷史品名', 'variant_snapshot'=>'色：黑', 'unit_price'=>100, 'quantity'=>2, 'subtotal'=>200]);
        $tables = ['categories', 'products', 'product_variants', 'product_specifications', 'product_images', 'carts', 'cart_items', 'orders', 'order_items'];
        $before = [];
        foreach ($tables as $table) $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        $this->login();
        foreach ([$root, $child] as $target) $this->deleteJson($this->path($target->id))->assertUnprocessable();
        foreach ($tables as $table) $this->assertEquals($before[$table], DB::table($table)->orderBy('id')->get()->toArray(), $table);
    }

    public function test_missing_and_nonnumeric_ids_are_404(): void
    {
        $this->login();
        $this->deleteJson($this->path(99999))->assertNotFound();
        $this->deleteJson($this->path('not-a-number'))->assertNotFound();
    }

    public function test_failure_after_delete_sql_rolls_back_the_category(): void
    {
        $category = $this->category();
        $event = 'eloquent.deleted: '.Category::class;
        $deletedSqlObserved = false;
        Event::listen($event, function ($deleted) use ($category, &$deletedSqlObserved) {
            if ($deleted->id !== $category->id) return;
            $deletedSqlObserved = ! DB::table('categories')->where('id', $category->id)->exists();
            throw new RuntimeException('late DELETE failure fixture');
        });
        try {
            $this->login();
            $this->deleteJson($this->path($category->id))->assertStatus(500);
        } finally { Event::forget($event); }
        $this->assertTrue($deletedSqlObserved, 'deleted event runs after SQL DELETE');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'status' => 'active']);
    }

    public static function referenceErrors(): array
    {
        return [
            'parent disappeared' => ['23000', 1452, 'categories_parent_id_foreign', 'categories_parent_id_foreign', true],
            'product category disappeared' => ['23000', 1452, 'products_category_id_foreign', 'products_category_id_foreign', true],
            'other FK' => ['23000', 1452, 'product_variants_product_id_foreign', 'products_category_id_foreign', false],
            'FK name prefix' => ['23000', 1452, 'products_category_id_foreign_other', 'products_category_id_foreign', false],
            'restrict' => ['23000', 1451, 'products_category_id_foreign', 'products_category_id_foreign', false],
            'unique' => ['23000', 1062, 'products_category_id_foreign', 'products_category_id_foreign', false],
            'deadlock' => ['40001', 1213, 'products_category_id_foreign', 'products_category_id_foreign', false],
            'connection error' => ['HY000', 2006, 'products_category_id_foreign', 'products_category_id_foreign', false],
            'syntax error' => ['42000', 1064, 'products_category_id_foreign', 'products_category_id_foreign', false],
        ];
    }

    #[DataProvider('referenceErrors')]
    public function test_fk_translation_is_narrow_and_preserves_other_exceptions(string $state, int $code, string $actual, string $expected, bool $translated): void
    {
        $previous = new \PDOException('constraint fixture');
        $previous->errorInfo = [$state, $code, 'CONSTRAINT `'.$actual.'` FOREIGN KEY'];
        $exception = new \Illuminate\Database\QueryException('mysql', 'insert fixture', [], $previous);
        try {
            \App\Support\CategoryReferenceError::rethrow($exception, $expected, 'category_id', '分類已不存在。');
        } catch (\Illuminate\Validation\ValidationException $result) {
            $this->assertTrue($translated);
            $this->assertSame(['category_id' => ['分類已不存在。']], $result->errors());
            return;
        } catch (\Illuminate\Database\QueryException $result) {
            $this->assertFalse($translated);
            $this->assertSame($exception, $result);
            return;
        }
    }
}
