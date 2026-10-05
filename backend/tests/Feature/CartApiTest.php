<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CartApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_cart_endpoints(): void
    {
        $this->getJson('/api/cart')
            ->assertUnauthorized();

        $this->postJson('/api/cart/items', [])
            ->assertUnauthorized();

        $this->patchJson('/api/cart/items/1', [])
            ->assertUnauthorized();

        $this->deleteJson('/api/cart/items/1')
            ->assertUnauthorized();

        $this->deleteJson('/api/cart')
            ->assertUnauthorized();

        $this->postJson('/api/cart/merge', [])
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_view_an_empty_cart(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user, 'web')
            ->getJson('/api/cart');

        $response
            ->assertOk()
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.item_count', 0)
            ->assertJsonPath('data.has_unavailable_items', false);

        $this->assertDatabaseHas('carts', [
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_add_a_product_to_their_cart(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'name' => '可調式啞鈴',
            'price' => 3000,
            'stock' => 10,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', '商品已加入購物車。')
            ->assertJsonPath(
                'data.items.0.product_id',
                $product->id
            )
            ->assertJsonPath('data.items.0.quantity', 2)
            ->assertJsonPath('data.item_count', 2);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);
    }

    public function test_adding_the_same_product_increases_its_quantity(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => 10,
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 3,
            ]);

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', 5)
            ->assertJsonPath('data.item_count', 5);

        $this->assertDatabaseCount('cart_items', 1);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 5,
        ]);
    }

    public function test_user_can_add_a_product_variant_to_their_cart(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => null,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'option_name' => '顏色',
            'option_value' => '黑色',
            'stock' => 5,
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 2,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.items.0.product_id',
                $product->id
            )
            ->assertJsonPath(
                'data.items.0.product_variant_id',
                $variant->id
            )
            ->assertJsonPath(
                'data.items.0.variant.option_value',
                '黑色'
            )
            ->assertJsonPath('data.items.0.quantity', 2);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    public function test_product_with_variants_requires_a_variant(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        ProductVariant::query()->create([
            'product_id' => $product->id,
            'option_name' => '顏色',
            'option_value' => '黑色',
            'stock' => 5,
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'product_variant_id',
            ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_variant_must_belong_to_the_selected_product(): void
    {
        $user = User::factory()->create();

        $selectedProduct = $this->createProduct();
        $otherProduct = $this->createProduct();

        $otherVariant = ProductVariant::query()->create([
            'product_id' => $otherProduct->id,
            'option_name' => '顏色',
            'option_value' => '黑色',
            'stock' => 5,
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $selectedProduct->id,
                'product_variant_id' => $otherVariant->id,
                'quantity' => 1,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'product_variant_id',
            ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_inactive_product_cannot_be_added_to_cart(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'status' => 'inactive',
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 1,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'product_id',
            ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_inactive_variant_cannot_be_added_to_cart(): void
    {
        $user = User::factory()->create();
        $product = $this->createProduct();

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'option_name' => '顏色',
            'option_value' => '黑色',
            'stock' => 5,
            'status' => 'inactive',
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'quantity' => 1,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'product_variant_id',
            ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_quantity_cannot_exceed_product_stock(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => 3,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 4,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'quantity',
            ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_accumulated_quantity_cannot_exceed_stock(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => 3,
        ]);

        $this->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ])
            ->assertOk();

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/items', [
                'product_id' => $product->id,
                'quantity' => 2,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'quantity',
            ]);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_user_can_update_cart_item_quantity(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => 10,
        ]);

        $cart = $user->cart()->create();

        $cartItem = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->patchJson("/api/cart/items/{$cartItem->id}", [
                'quantity' => 4,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                '購物車商品數量已更新。'
            )
            ->assertJsonPath('data.items.0.quantity', 4)
            ->assertJsonPath('data.item_count', 4);

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'quantity' => 4,
        ]);
    }

    public function test_updated_quantity_cannot_exceed_stock(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => 3,
        ]);

        $cart = $user->cart()->create();

        $cartItem = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->patchJson("/api/cart/items/{$cartItem->id}", [
                'quantity' => 4,
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'quantity',
            ]);

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'quantity' => 2,
        ]);
    }

    public function test_user_cannot_manage_another_users_cart_item(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $product = $this->createProduct();

        $ownerCart = $owner->cart()->create();

        $cartItem = $ownerCart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $this->actingAs($otherUser, 'web')
            ->patchJson("/api/cart/items/{$cartItem->id}", [
                'quantity' => 5,
            ])
            ->assertNotFound();

        $this->actingAs($otherUser, 'web')
            ->deleteJson("/api/cart/items/{$cartItem->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'cart_id' => $ownerCart->id,
            'quantity' => 2,
        ]);
    }

    public function test_user_can_remove_an_item_from_their_cart(): void
    {
        $user = User::factory()->create();

        $firstProduct = $this->createProduct();
        $secondProduct = $this->createProduct();

        $cart = $user->cart()->create();

        $removedItem = $cart->items()->create([
            'product_id' => $firstProduct->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $remainingItem = $cart->items()->create([
            'product_id' => $secondProduct->id,
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->deleteJson("/api/cart/items/{$removedItem->id}");

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                '商品已從購物車移除。'
            )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.id',
                $remainingItem->id
            )
            ->assertJsonPath('data.item_count', 1);

        $this->assertDatabaseMissing('cart_items', [
            'id' => $removedItem->id,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'id' => $remainingItem->id,
        ]);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_clear_their_cart(): void
    {
        $user = User::factory()->create();

        $firstProduct = $this->createProduct();
        $secondProduct = $this->createProduct();

        $cart = $user->cart()->create();

        $cart->items()->create([
            'product_id' => $firstProduct->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $cart->items()->create([
            'product_id' => $secondProduct->id,
            'product_variant_id' => null,
            'quantity' => 3,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->deleteJson('/api/cart');

        $response
            ->assertOk()
            ->assertJsonPath('message', '購物車已清空。')
            ->assertJsonCount(0, 'data.items')
            ->assertJsonPath('data.item_count', 0);

        $this->assertDatabaseCount('cart_items', 0);

        // 清空只刪除項目，會員的購物車本身仍然保留。
        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_merge_new_and_existing_cart_items(): void
    {
        $user = User::factory()->create();

        $existingProduct = $this->createProduct([
            'stock' => 10,
        ]);

        $newProduct = $this->createProduct([
            'stock' => 10,
        ]);

        $cart = $user->cart()->create();

        $cart->items()->create([
            'product_id' => $existingProduct->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/merge', [
                'items' => [
                    [
                        'product_id' => $existingProduct->id,
                        'product_variant_id' => null,
                        'quantity' => 3,
                    ],
                    [
                        'product_id' => $newProduct->id,
                        'product_variant_id' => null,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                '訪客購物車已合併。'
            )
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.item_count', 4)
            ->assertJsonFragment([
                'product_id' => $existingProduct->id,
                'quantity' => 3,
            ])
            ->assertJsonFragment([
                'product_id' => $newProduct->id,
                'quantity' => 1,
            ]);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $existingProduct->id,
            'product_variant_id' => null,
            'quantity' => 3,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $newProduct->id,
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $this->assertDatabaseCount('cart_items', 2);
    }

    public function test_guest_cart_variants_are_merged_separately(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => null,
        ]);

        $blackVariant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'option_name' => '顏色',
            'option_value' => '黑色',
            'stock' => 5,
            'status' => 'active',
        ]);

        $whiteVariant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'option_name' => '顏色',
            'option_value' => '白色',
            'stock' => 5,
            'status' => 'active',
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/merge', [
                'items' => [
                    [
                        'product_id' => $product->id,
                        'product_variant_id' => $blackVariant->id,
                        'quantity' => 2,
                    ],
                    [
                        'product_id' => $product->id,
                        'product_variant_id' => $whiteVariant->id,
                        'quantity' => 1,
                    ],
                ],
            ]);

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.item_count', 3)
            ->assertJsonFragment([
                'product_variant_id' => $blackVariant->id,
                'quantity' => 2,
            ])
            ->assertJsonFragment([
                'product_variant_id' => $whiteVariant->id,
                'quantity' => 1,
            ]);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'product_variant_id' => $blackVariant->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'product_id' => $product->id,
            'product_variant_id' => $whiteVariant->id,
            'quantity' => 1,
        ]);
    }

    public function test_failed_merge_rolls_back_all_database_changes(): void
    {
        $user = User::factory()->create();

        $existingProduct = $this->createProduct([
            'stock' => 10,
        ]);

        $validGuestProduct = $this->createProduct([
            'stock' => 10,
        ]);

        $insufficientProduct = $this->createProduct([
            'stock' => 2,
        ]);

        $cart = $user->cart()->create();

        $existingItem = $cart->items()->create([
            'product_id' => $existingProduct->id,
            'product_variant_id' => null,
            'quantity' => 1,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/cart/merge', [
                'items' => [
                    [
                        'product_id' => $validGuestProduct->id,
                        'product_variant_id' => null,
                        'quantity' => 2,
                    ],
                    [
                        'product_id' => $insufficientProduct->id,
                        'product_variant_id' => null,
                        'quantity' => 3,
                    ],
                ],
            ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'quantity',
            ]);

        // 原本的會員購物車資料仍然存在。
        $this->assertDatabaseHas('cart_items', [
            'id' => $existingItem->id,
            'cart_id' => $cart->id,
            'quantity' => 1,
        ]);

        // 第一筆商品曾在交易中寫入會員購物車，
        // 但第二筆失敗後，整個資料庫交易必須復原。
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $validGuestProduct->id,
        ]);

        // 庫存不足的第二筆商品也不能寫入會員購物車。
        $this->assertDatabaseMissing('cart_items', [
            'cart_id' => $cart->id,
            'product_id' => $insufficientProduct->id,
        ]);

        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_cart_reports_when_it_contains_an_unavailable_item(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'stock' => 5,
        ]);

        $cart = $user->cart()->create();

        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 3,
        ]);

        // 模擬商品加入購物車後，庫存下降。
        $product->update([
            'stock' => 1,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->getJson('/api/cart');

        $response
            ->assertOk()
            ->assertJsonPath(
                'data.has_unavailable_items',
                true
            )
            ->assertJsonPath(
                'data.items.0.is_available',
                false
            )
            ->assertJsonPath(
                'data.items.0.unavailable_reason',
                '商品庫存不足'
            );
    }

    private function createProduct(
        array $attributes = []
    ): Product {
        $root = Category::query()->create(['name' => '測試主分類', 'status' => 'active']);
        $category = Category::query()->create([
            'parent_id' => $root->id,
            'name' => '測試分類',
            'status' => 'active',
            'sort_order' => 0,
        ]);

        return Product::factory()->create(
            array_merge([
                'category_id' => $category->id,
                'stock' => 10,
                'status' => 'active',
            ], $attributes)
        );
    }
}
