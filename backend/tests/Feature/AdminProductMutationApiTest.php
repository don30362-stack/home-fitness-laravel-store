<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\ProductCodeGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminProductMutationApiTest extends TestCase
{
    use RefreshDatabase;

    private function permissionAdmin(array $attributes = []): \App\Models\Admin
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = \App\Models\Admin::factory()->create($attributes);
        $admin->permissions()->attach(\App\Models\Permission::query()->where('code', 'product_manage')->value('id'));
        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function category(array $data = []): Category
    {
        $parent = Category::query()->firstOrCreate(['name' => '測試主分類'], ['status' => 'active']);

        return Category::query()->create($data + ['parent_id' => $parent->id, 'name' => '子分類', 'status' => 'active']);
    }

    private function product(array $data = []): Product
    {
        return Product::factory()->create($data + ['category_id' => $this->category()->id, 'stock' => 10]);
    }

    private function payload(array $data = []): array
    {
        return $data + ['category_id' => $this->category()->id, 'name' => '新啞鈴', 'price' => '123.50', 'stock' => 7, 'low_stock_threshold' => 5, 'status' => 'active'];
    }

    private function login(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
    }

    private function variant(Product $product, string $value = '黑'): ProductVariant
    {
        return $product->variants()->create(['option_name' => '顏色', 'option_value' => $value, 'stock' => 4, 'status' => 'active']);
    }

    private function row(ProductVariant $variant, array $data = []): array
    {
        return $data + ['id' => $variant->id, 'option_name' => $variant->option_name, 'option_value' => $variant->option_value, 'status' => $variant->status];
    }

    private function reference(Product $product, ?ProductVariant $variant, string $kind, string $status = 'pending'): void
    {
        $user = User::factory()->create();
        if ($kind === 'cart') {
            $user->cart()->create()->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => 1]);

            return;
        }
        $order = $user->orders()->create([
            'order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '原訂購人', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'test@example.test', 'recipient_name' => '原收件人', 'recipient_phone' => '0912345678',
            'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '原地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => 100, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'order_status' => $status, 'subtotal' => 123, 'total_amount' => 223,
        ]);
        $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id,
            'product_code_snapshot' => $product->product_code, 'product_name_snapshot' => '歷史名稱',
            'variant_snapshot' => $variant ? '顏色：黑' : null, 'unit_price' => 123, 'quantity' => 1, 'subtotal' => 123]);
    }

    public static function endpoints(): array
    {
        return [['post', ''], ['patch', '/1'], ['patch', '/1/status'], ['delete', '/1']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_member_and_disabled_boundaries(string $method, string $suffix): void
    {
        $url = '/api/admin/products'.$suffix;
        $this->json($method, $url, [])->assertUnauthorized();
        $member = User::factory()->create();
        $this->actingAs($member, 'web');
        $this->json($method, $url, [])->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_admin_only_revokes_admin_identity(string $method, string $suffix): void
    {
        $member = User::factory()->create();
        $this->actingAs($member, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->json($method, '/api/admin/products'.$suffix, [])->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($member, 'web');
    }

    public function test_plain_create_generates_unique_codes_and_keeps_specs_status_and_stock(): void
    {
        $this->login();
        $payload = $this->payload(['status' => 'disabled', 'specifications' => [['spec_name' => '材質', 'spec_value' => '鋼', 'sort_order' => 2]]]);
        $first = $this->postJson('/api/admin/products', $payload)->assertCreated()->assertJsonMissingPath('success')
            ->assertJsonPath('data.stock', 7)->assertJsonPath('data.has_variants', false)->assertJsonPath('data.status', 'disabled')
            ->assertJsonPath('data.specifications.0.spec_value', '鋼')->json('data');
        $second = $this->postJson('/api/admin/products', $payload)->assertCreated()->json('data');
        $this->assertMatchesRegularExpression('/^PRD-[A-Z0-9]{8}$/', $first['product_code']);
        $this->assertNotSame($first['product_code'], $second['product_code']);
        $this->assertDatabaseCount('product_variants', 0);
    }

    public function test_generator_retries_existing_candidate_and_is_bounded(): void
    {
        $this->login();
        $this->product(['product_code' => 'PRD-AAAAAAAA']);
        $generator = new class extends ProductCodeGenerator
        {
            public int $calls = 0;

            protected function candidate(): string
            {
                return ++$this->calls === 1 ? 'PRD-AAAAAAAA' : 'PRD-BBBBBBBB';
            }
        };
        $this->app->instance(ProductCodeGenerator::class, $generator);
        $this->postJson('/api/admin/products', $this->payload())->assertCreated()->assertJsonPath('data.product_code', 'PRD-BBBBBBBB');
        $this->assertSame(2, $generator->calls);
        $this->app->instance(ProductCodeGenerator::class, new class extends ProductCodeGenerator
        {
            protected function candidate(): string
            {
                return 'PRD-AAAAAAAA';
            }
        });
        $this->postJson('/api/admin/products', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('product_code');
        $this->assertDatabaseCount('products', 2);
    }

    public static function invalidCreates(): array
    {
        return [
            'client code' => [['product_code' => 'PRD-CLIENT00'], 'product_code'],
            'negative stock' => [['stock' => -1], 'stock'], 'null plain stock' => [['stock' => null], 'stock'],
            'name length' => [['name' => str_repeat('a', 151)], 'name'], 'price' => [['price' => -1], 'price'],
            'precision' => [['price' => '1.123'], 'price'], 'threshold' => [['low_stock_threshold' => -1], 'low_stock_threshold'],
            'status' => [['status' => 'unknown'], 'status'], 'empty variants' => [['stock' => null, 'variants' => []], 'variants'],
            'axis' => [['stock' => null, 'variants' => [
                ['option_name' => '顏色', 'option_value' => '黑', 'stock' => 1], ['option_name' => '重量', 'option_value' => '20kg', 'stock' => 1],
            ]], 'variants'],
            'duplicate values' => [['stock' => null, 'variants' => [
                ['option_name' => '顏色', 'option_value' => '黑', 'stock' => 1], ['option_name' => '顏色', 'option_value' => '黑', 'stock' => 2],
            ]], 'variants.0.option_value'],
            'variant negative' => [['stock' => null, 'variants' => [['option_name' => '顏色', 'option_value' => '黑', 'stock' => -1]]], 'variants.0.stock'],
            'both stock owners' => [['stock' => 1, 'variants' => [['option_name' => '顏色', 'option_value' => '黑', 'stock' => 1]]], 'stock'],
            'variant disabled' => [['stock' => null, 'variants' => [['option_name' => '顏色', 'option_value' => '黑', 'stock' => 1, 'status' => 'disabled']]], 'variants.0.status'],
            'images' => [['images' => []], 'images'], 'bad spec' => [['specifications' => [['spec_name' => '', 'spec_value' => '值']]], 'specifications.0.spec_name'],
        ];
    }

    #[DataProvider('invalidCreates')]
    public function test_create_validation_does_not_write(array $data, string $field): void
    {
        $this->login();
        $this->postJson('/api/admin/products', $this->payload($data))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('products', 0);
    }

    public function test_variant_create_uses_only_variant_initial_stocks(): void
    {
        $this->login();
        $this->postJson('/api/admin/products', $this->payload(['stock' => null, 'variants' => [
            ['option_name' => '顏色', 'option_value' => '黑', 'stock' => 0], ['option_name' => '顏色', 'option_value' => '白', 'stock' => 9, 'status' => 'inactive'],
        ]]))->assertCreated()->assertJsonPath('data.stock', null)->assertJsonPath('data.has_variants', true)
            ->assertJsonPath('data.variants.0.stock', 0)->assertJsonPath('data.variants.1.stock', 9);
    }

    public function test_category_new_selection_and_existing_inactive_retention(): void
    {
        $this->login();
        $inactive = $this->category(['status' => 'inactive']);
        foreach ([$inactive->id, $inactive->parent_id] as $id) {
            $this->postJson('/api/admin/products', $this->payload(['category_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('category_id');
        }
        $parentDisabled = $this->category();
        $parentDisabled->parent->update(['status' => 'inactive']);
        $this->postJson('/api/admin/products', $this->payload(['category_id' => $parentDisabled->id]))->assertUnprocessable();
        $product = $this->product(['category_id' => $inactive->id]);
        $this->patchJson('/api/admin/products/'.$product->id, ['category_id' => $inactive->id, 'name' => '保留分類'])->assertOk();
        $different = $this->category(['status' => 'inactive']);
        $this->patchJson('/api/admin/products/'.$product->id, ['category_id' => $different->id])->assertUnprocessable();
        $this->assertSame($inactive->id, $product->fresh()->category_id);
    }

    public function test_partial_update_specs_sync_null_and_legacy_code(): void
    {
        $this->login();
        $product = $this->product(['product_code' => 'OLD-001', 'description' => '原說明']);
        $spec = $product->specifications()->create(['spec_name' => '原', 'spec_value' => '值']);
        $url = '/api/admin/products/'.$product->id;
        $this->patchJson($url, ['name' => '改名'])->assertOk()->assertJsonPath('data.product_code', 'OLD-001')->assertJsonPath('data.description', '原說明');
        $this->assertDatabaseHas('product_specifications', ['id' => $spec->id]);
        $this->patchJson($url, ['description' => null, 'specifications' => [['spec_name' => '新', 'spec_value' => '值', 'sort_order' => 3]]])->assertOk()->assertJsonPath('data.description', null);
        $this->assertDatabaseMissing('product_specifications', ['id' => $spec->id]);
        $this->patchJson($url, ['specifications' => []])->assertOk()->assertJsonPath('data.specifications', []);
        $this->assertSame(10, (int) $product->fresh()->stock);
    }

    public static function forbiddenUpdates(): array
    {
        return [[['product_code' => 'CHANGED'], 'product_code'], [['stock' => 10], 'stock'], [['status' => 'active'], 'status'], [['images' => []], 'images']];
    }

    #[DataProvider('forbiddenUpdates')]
    public function test_basic_update_rejects_protected_fields(array $data, string $field): void
    {
        $this->login();
        $product = $this->product();
        $this->patchJson('/api/admin/products/'.$product->id, $data)->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    public function test_variant_identity_sync_preserves_id_and_stock_and_adds_deletes_unreferenced(): void
    {
        $this->login();
        $product = $this->product(['stock' => null]);
        $kept = $this->variant($product);
        $removed = $this->variant($product, '白');
        $url = '/api/admin/products/'.$product->id;
        $this->patchJson($url, ['name' => '只改名稱'])->assertOk();
        $this->assertSame(2, $product->variants()->count());
        $this->patchJson($url, ['variants' => [$this->row($kept, ['option_value' => '黑色']),
            ['option_name' => '顏色', 'option_value' => '灰色', 'stock' => 8, 'status' => 'inactive'],
        ]])->assertOk()->assertJsonPath('data.variants.0.id', $kept->id)->assertJsonPath('data.variants.0.stock', 4);
        $this->assertDatabaseMissing('product_variants', ['id' => $removed->id]);
        $this->assertDatabaseHas('product_variants', ['product_id' => $product->id, 'option_value' => '灰色', 'stock' => 8]);
        $this->patchJson($url, ['variants' => [$this->row($kept, ['stock' => 99])]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.stock');
        $this->assertSame(4, (int) $kept->fresh()->stock);
    }

    public function test_mode_conversion_foreign_variant_and_new_stock_requirements(): void
    {
        $this->login();
        $plain = $this->product();
        $variantProduct = $this->product(['stock' => null]);
        $variant = $this->variant($variantProduct);
        $new = ['option_name' => '顏色', 'option_value' => '白', 'stock' => 2];
        $this->patchJson('/api/admin/products/'.$plain->id, ['variants' => [$new]])->assertUnprocessable();
        $this->patchJson('/api/admin/products/'.$variantProduct->id, ['variants' => []])->assertUnprocessable();
        $other = $this->variant($this->product(['stock' => null]));
        $this->patchJson('/api/admin/products/'.$variantProduct->id, ['variants' => [$this->row($other)]])->assertUnprocessable()->assertJsonValidationErrors('variants.0.id');
        unset($new['stock']);
        $this->patchJson('/api/admin/products/'.$variantProduct->id, ['variants' => [$this->row($variant), $new]])->assertUnprocessable()->assertJsonValidationErrors('variants.1.stock');
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id]);
    }

    public function test_update_axis_duplicate_values_and_duplicate_ids_are_rejected(): void
    {
        $this->login();
        $product = $this->product(['stock' => null]);
        $first = $this->variant($product);
        $second = $this->variant($product, '白');
        foreach ([[$this->row($first), $this->row($second, ['option_name' => '重量'])],
            [$this->row($first), $this->row($second, ['option_value' => '黑'])],
            [$this->row($first), $this->row($first)]] as $rows) {
            $this->patchJson('/api/admin/products/'.$product->id, ['variants' => $rows])->assertUnprocessable();
        }
        $this->assertSame('顏色', $second->fresh()->option_name);
        $this->assertSame('白', $second->fresh()->option_value);
        $this->assertDatabaseCount('product_variants', 2);
    }

    private function nextRequest(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        app('session.store')->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    public static function unavailableProducts(): array
    {
        return [['inactive', false], ['disabled', false], ['inactive', true], ['disabled', true]];
    }

    #[DataProvider('unavailableProducts')]
    public function test_admin_status_change_blocks_member_cart_and_checkout_without_deducting_stock(string $status, bool $hasVariant): void
    {
        $product = $this->product(['stock' => $hasVariant ? null : 10]);
        $variant = $hasVariant ? $this->variant($product) : null;
        $user = User::factory()->create(['password' => 'test-member-secret']);
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => 1]);
        $admin = $this->permissionAdmin(['password' => 'test-admin-secret']);
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        $this->nextRequest();
        $this->patchJson('/api/admin/products/'.$product->id.'/status', ['status' => $status])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/cart/items', ['product_id' => $product->id, 'product_variant_id' => $variant?->id, 'quantity' => 1])->assertUnprocessable();
        $this->nextRequest();
        $district = City::query()->create(['name' => '臺北市'])->districts()->create(['name' => '中正區', 'postal_code' => '100']);
        $this->postJson('/api/checkout', [
            'purchaser' => ['name' => '訂購人', 'phone' => '0912345678', 'email' => 'test@example.test'],
            'recipient' => ['name' => '收件人', 'phone' => '0912345678', 'district_id' => $district->id, 'address' => '測試地址'],
            'shipping_method' => 'home_delivery', 'payment_method' => 'cod',
        ])->assertUnprocessable();
        $this->assertSame($hasVariant ? null : 10, $product->fresh()->stock === null ? null : (int) $product->fresh()->stock);
        if ($variant) {
            $this->assertSame(4, (int) $variant->fresh()->stock);
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame(1, $cart->items()->count());
    }

    public function test_admin_mutation_does_not_trigger_disabled_member_c06(): void
    {
        $user = User::factory()->create(['password' => 'test-member-secret']);
        $product = $this->product();
        $user->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $admin = $this->permissionAdmin(['password' => 'test-admin-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        $this->nextRequest();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        $this->patchJson('/api/admin/products/'.$product->id, ['name' => 'Admin維護'])->assertOk();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('cart_items', 1);
    }

    public static function references(): array
    {
        return [['order'], ['cart']];
    }

    #[DataProvider('references')]
    public function test_referenced_variant_cannot_change_identity_or_be_deleted_but_can_be_inactive(string $kind): void
    {
        $this->login();
        $product = $this->product(['stock' => null]);
        $variant = $this->variant($product);
        $other = $this->variant($product, '白');
        $this->reference($product, $variant, $kind);
        $url = '/api/admin/products/'.$product->id;
        foreach (['option_name' => '重量', 'option_value' => '20kg'] as $field => $value) {
            $rows = [$this->row($variant, [$field => $value]), $this->row($other, $field === 'option_name' ? [$field => $value] : [])];
            $this->patchJson($url, ['variants' => $rows])->assertUnprocessable();
        }
        $this->patchJson($url, ['variants' => [$this->row($other)]])->assertUnprocessable()->assertJsonValidationErrors('variants');
        $this->patchJson($url, ['variants' => [$this->row($variant, ['status' => 'inactive']), $this->row($other)]])->assertOk();
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'option_value' => '黑', 'stock' => 4, 'status' => 'inactive']);
    }

    public function test_late_variant_failure_rolls_back_basic_specs_and_earlier_variant_update(): void
    {
        $this->login();
        $product = $this->product(['stock' => null, 'name' => '原商品']);
        $first = $this->variant($product);
        $last = $this->variant($product, '白');
        $this->reference($product, $last, 'order');
        $spec = $product->specifications()->create(['spec_name' => '原規格', 'spec_value' => '原值']);
        $this->patchJson('/api/admin/products/'.$product->id, ['name' => '已改商品',
            'specifications' => [['spec_name' => '已改規格', 'spec_value' => '新值']],
            'variants' => [$this->row($first, ['option_value' => '黑色']), $this->row($last, ['option_value' => '20kg'])],
        ])->assertUnprocessable();
        $this->assertSame('原商品', $product->fresh()->name);
        $this->assertDatabaseHas('product_specifications', ['id' => $spec->id, 'spec_value' => '原值']);
        $this->assertSame('黑', $first->fresh()->option_value);
        $this->assertSame('白', $last->fresh()->option_value);
        $this->assertDatabaseCount('product_variants', 2);
    }

    public function test_create_midway_exception_rolls_back_product_specs_and_first_variant(): void
    {
        $this->login();
        ProductVariant::creating(function ($variant) {
            if ($variant->option_value === '失敗') {
                throw new \RuntimeException('isolated fixture failure');
            }
        });
        try {
            $this->postJson('/api/admin/products', $this->payload(['stock' => null,
                'specifications' => [['spec_name' => '材質', 'spec_value' => '鋼']],
                'variants' => [['option_name' => '顏色', 'option_value' => '黑', 'stock' => 1], ['option_name' => '顏色', 'option_value' => '失敗', 'stock' => 2]],
            ]))->assertStatus(500);
            foreach (['products', 'product_specifications', 'product_variants'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
        } finally {
            ProductVariant::flushEventListeners();
        }
    }

    public function test_status_changes_only_product_and_public_active_boundary(): void
    {
        $this->login();
        $product = $this->product(['stock' => null]);
        $variant = $this->variant($product);
        $this->reference($product, $variant, 'order');
        $before = DB::table('order_items')->first();
        foreach (['inactive', 'active', 'disabled', 'active'] as $status) {
            $this->patchJson('/api/admin/products/'.$product->id.'/status', ['status' => $status])->assertOk()->assertJsonPath('data.status', $status);
            $this->assertNull($product->fresh()->stock);
            $this->assertSame('active', $variant->fresh()->status);
            $this->assertEquals($before, DB::table('order_items')->first());
        }
        $this->patchJson('/api/admin/products/'.$product->id.'/status', ['status' => 'invalid'])->assertUnprocessable();
        $this->patchJson('/api/admin/products/'.$product->id.'/status', ['status' => 'disabled', 'stock' => 0])->assertUnprocessable();
        // 公開讀取不需要Admin；重置guard避免跨request快取影響。
        Auth::forgetGuards();
        Auth::shouldUse('web');
        foreach (['inactive', 'disabled'] as $status) {
            $product->update(['status' => $status]);
            $this->getJson('/api/products/'.$product->id)->assertNotFound();
            $this->getJson('/api/products')->assertJsonCount(0, 'data');
        }
    }

    public static function historicalStatuses(): array
    {
        return array_map(fn ($s) => [$s], ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'legacy']);
    }

    #[DataProvider('historicalStatuses')]
    public function test_any_historical_order_blocks_delete_with_business_error(string $status): void
    {
        $this->login();
        $product = $this->product(['stock' => null]);
        $variant = $this->variant($product);
        $this->reference($product, $variant, 'order', $status);
        $this->reference($product, $variant, 'cart');
        $this->deleteJson('/api/admin/products/'.$product->id)->assertUnprocessable()->assertJsonValidationErrors('product');
        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertDatabaseCount('product_variants', 1);
    }

    public function test_cart_only_delete_cascades_database_children(): void
    {
        $this->login();
        $product = $this->product(['stock' => null]);
        $variant = $this->variant($product);
        $this->reference($product, $variant, 'cart');
        $product->specifications()->create(['spec_name' => '材質', 'spec_value' => '鋼']);
        $product->images()->create(['image_path' => 'products/fixture-only.jpg']);
        $this->deleteJson('/api/admin/products/'.$product->id)->assertOk()->assertJsonMissingPath('success');
        foreach (['products', 'product_variants', 'product_specifications', 'product_images', 'cart_items'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        // 只證明DB cascade；本步不測也不宣稱實體圖檔已清理。
        $this->assertDatabaseCount('carts', 1);
        $plain = $this->product();
        $this->deleteJson('/api/admin/products/'.$plain->id)->assertOk();
    }

    public static function invalidIds(): array
    {
        return [['999999'], ['not-number']];
    }

    #[DataProvider('invalidIds')]
    public function test_numeric_routes_and_missing_products(string $id): void
    {
        $this->login();
        $this->patchJson('/api/admin/products/'.$id, [])->assertNotFound();
        $this->patchJson('/api/admin/products/'.$id.'/status', ['status' => 'active'])->assertNotFound();
        $this->deleteJson('/api/admin/products/'.$id)->assertNotFound();
    }
}
