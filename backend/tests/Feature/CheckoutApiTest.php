<?php

namespace Tests\Feature;

use App\Models\CartItem;
use App\Models\Category;
use App\Models\City;
use App\Models\District;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class CheckoutApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_checkout(): void
    {
        $this->postJson('/api/checkout')
            ->assertUnauthorized();
    }

    public function test_checkout_requires_main_payload_fields(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $this->postJson('/api/checkout', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'purchaser',
                'recipient',
                'shipping_method',
                'payment_method',
            ]);
    }

    public function test_checkout_rejects_invalid_payload_values(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->postJson('/api/checkout', [
            'purchaser' => [
                'name' => '王小明',
                'phone' => '12345',
                'email' => 'invalid-email',
            ],
            'recipient' => [
                'name' => '',
                'phone' => '0912',
                'district_id' => 999999,
                'address' => '',
            ],
            'shipping_method' => 'store_pickup',
            'payment_method' => 'bank_transfer',
        ]);

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'purchaser.phone',
                'purchaser.email',
                'recipient.name',
                'recipient.phone',
                'recipient.district_id',
                'recipient.address',
                'shipping_method',
                'payment_method',
            ]);
    }

    public function test_user_can_checkout_with_cod(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'name' => '可調式啞鈴',
            'price' => '3000.00',
            'stock' => 10,
        ]);

        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $cartItem = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson('/api/checkout', $this->validCheckoutPayload($district));

        $response
            ->assertCreated()
            ->assertJsonPath(
                'message',
                '訂單建立成功。'
            )
            ->assertJsonPath(
                'data.purchaser.name',
                '王小明'
            )
            ->assertJsonPath(
                'data.recipient.postal_code',
                '407'
            )
            ->assertJsonPath(
                'data.recipient.city',
                '臺中市'
            )
            ->assertJsonPath(
                'data.recipient.district',
                '西屯區'
            )
            ->assertJsonPath(
                'data.payment_method',
                'cod'
            )
            ->assertJsonPath(
                'data.payment_status',
                'unpaid'
            )
            ->assertJsonPath(
                'data.order_status',
                'pending'
            )
            ->assertJsonPath(
                'data.shipping_fee',
                '100.00'
            )
            ->assertJsonPath(
                'data.subtotal',
                '6000.00'
            )
            ->assertJsonPath(
                'data.total_amount',
                '6100.00'
            )
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath(
                'data.items.0.product_name',
                '可調式啞鈴'
            )
            ->assertJsonPath(
                'data.items.0.unit_price',
                '3000.00'
            )
            ->assertJsonPath(
                'data.items.0.quantity',
                2
            )
            ->assertJsonPath(
                'data.items.0.subtotal',
                '6000.00'
            )
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'order_no',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'purchaser_name' => '王小明',
            'recipient_name' => '王小華',
            'city' => '臺中市',
            'district' => '西屯區',
            'payment_method' => 'cod',
            'payment_status' => 'unpaid',
            'order_status' => 'pending',
            'subtotal' => '6000.00',
            'shipping_fee' => '100.00',
            'total_amount' => '6100.00',
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_variant_id' => null,
            'product_name_snapshot' => '可調式啞鈴',
            'unit_price' => '3000.00',
            'quantity' => 2,
            'subtotal' => '6000.00',
        ]);

        $this->assertSame(
            8,
            (int) $product->fresh()->stock
        );

        $this->assertDatabaseMissing('cart_items', [
            'id' => $cartItem->id,
        ]);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_checkout_variant_with_mock_credit_card(): void
    {
        config()->set(
            'services.mock_credit_card.should_fail',
            false
        );

        $user = User::factory()->create();

        $product = $this->createProduct([
            'name' => '競技跳繩',
            'price' => '1250.00',
            'stock' => null,
        ]);

        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'option_name' => '顏色',
            'option_value' => '黑色',
            'stock' => 5,
            'status' => 'active',
        ]);

        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $cartItem = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson(
                '/api/checkout',
                $this->validCheckoutPayload(
                    $district,
                    'mock_credit_card'
                )
            );

        $response
            ->assertCreated()
            ->assertJsonPath(
                'data.payment_method',
                'mock_credit_card'
            )
            ->assertJsonPath(
                'data.payment_status',
                'paid'
            )
            ->assertJsonPath(
                'data.order_status',
                'pending'
            )
            ->assertJsonPath(
                'data.subtotal',
                '2500.00'
            )
            ->assertJsonPath(
                'data.total_amount',
                '2600.00'
            )
            ->assertJsonPath(
                'data.items.0.product_variant_id',
                $variant->id
            )
            ->assertJsonPath(
                'data.items.0.variant',
                '顏色：黑色'
            )
            ->assertJsonPath(
                'data.items.0.quantity',
                2
            );

        $this->assertDatabaseHas('orders', [
            'user_id' => $user->id,
            'payment_method' => 'mock_credit_card',
            'payment_status' => 'paid',
            'order_status' => 'pending',
            'subtotal' => '2500.00',
            'shipping_fee' => '100.00',
            'total_amount' => '2600.00',
        ]);

        $this->assertDatabaseHas('order_items', [
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'product_name_snapshot' => '競技跳繩',
            'variant_snapshot' => '顏色：黑色',
            'unit_price' => '1250.00',
            'quantity' => 2,
            'subtotal' => '2500.00',
        ]);

        $this->assertSame(
            3,
            (int) $variant->fresh()->stock
        );

        $this->assertDatabaseMissing('cart_items', [
            'id' => $cartItem->id,
        ]);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_checkout_fails_when_product_stock_is_insufficient(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'name' => '可調式訓練椅',
            'price' => '4500.00',
            'stock' => 5,
        ]);

        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $cartItem = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 3,
        ]);

        // 模擬商品加入購物車後，
        // 其他訂單先消耗了庫存。
        $product->update([
            'stock' => 1,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson(
                '/api/checkout',
                $this->validCheckoutPayload($district)
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cart',
            ])
            ->assertJsonPath(
                'errors.cart.0',
                '商品「可調式訓練椅」庫存不足，目前可購買數量為 1。'
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        $this->assertSame(
            1,
            (int) $product->fresh()->stock
        );

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 3,
        ]);
    }

    public function test_failed_mock_credit_card_checkout_changes_nothing(): void
    {
        config()->set(
            'services.mock_credit_card.should_fail',
            true
        );

        $user = User::factory()->create();

        $product = $this->createProduct([
            'name' => '六角啞鈴',
            'price' => '1800.00',
            'stock' => 5,
        ]);

        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $cartItem = $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $response = $this
            ->actingAs($user, 'web')
            ->postJson(
                '/api/checkout',
                $this->validCheckoutPayload(
                    $district,
                    'mock_credit_card'
                )
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'payment_method',
            ])
            ->assertJsonPath(
                'errors.payment_method.0',
                '模擬信用卡付款失敗，請重新嘗試或更換付款方式。'
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        $this->assertSame(
            5,
            (int) $product->fresh()->stock
        );

        $this->assertDatabaseHas('cart_items', [
            'id' => $cartItem->id,
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => 2,
        ]);
    }

    public function test_user_cannot_checkout_an_empty_cart(): void
    {
        $user = User::factory()->create();
        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $response = $this
            ->actingAs($user, 'web')
            ->postJson(
                '/api/checkout',
                $this->validCheckoutPayload($district)
            );

        $response
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cart',
            ])
            ->assertJsonPath(
                'errors.cart.0',
                '購物車目前沒有商品，請先加入商品後再結帳。'
            );

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        $this->assertDatabaseHas('carts', [
            'id' => $cart->id,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_repeated_checkout_creates_only_one_order(): void
    {
        $user = User::factory()->create();

        $product = $this->createProduct([
            'name' => '奧林匹克槓鈴',
            'price' => '5000.00',
            'stock' => 5,
        ]);

        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $cart->items()->create([
            'product_id' => $product->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $payload = $this->validCheckoutPayload(
            $district
        );

        $firstResponse = $this
            ->actingAs($user, 'web')
            ->postJson('/api/checkout', $payload);

        $firstResponse->assertCreated();

        $secondResponse = $this
            ->actingAs($user, 'web')
            ->postJson('/api/checkout', $payload);

        $secondResponse
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'cart',
            ])
            ->assertJsonPath(
                'errors.cart.0',
                '購物車目前沒有商品，請先加入商品後再結帳。'
            );

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('cart_items', 0);

        $this->assertSame(
            3,
            (int) $product->fresh()->stock
        );
    }

    public function test_checkout_rolls_back_when_a_late_step_fails(): void
    {
        $user = User::factory()->create();

        $firstProduct = $this->createProduct([
            'name' => '壺鈴',
            'price' => '1200.00',
            'stock' => 10,
        ]);

        $secondProduct = $this->createProduct([
            'name' => '瑜珈墊',
            'price' => '800.00',
            'stock' => 10,
        ]);

        $district = $this->createDistrict();

        $cart = $user->cart()->create();

        $firstCartItem = $cart->items()->create([
            'product_id' => $firstProduct->id,
            'product_variant_id' => null,
            'quantity' => 2,
        ]);

        $secondCartItem = $cart->items()->create([
            'product_id' => $secondProduct->id,
            'product_variant_id' => null,
            'quantity' => 3,
        ]);

        $deletingCount = 0;

        CartItem::deleting(
            function () use (&$deletingCount): void {
                $deletingCount++;

                if ($deletingCount === 2) {
                    throw new RuntimeException(
                        '模擬清除購物車時發生錯誤。'
                    );
                }
            }
        );

        $response = $this
            ->actingAs($user, 'web')
            ->postJson(
                '/api/checkout',
                $this->validCheckoutPayload($district)
            );

        $response->assertStatus(500);

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);

        $this->assertSame(
            10,
            (int) $firstProduct->fresh()->stock
        );

        $this->assertSame(
            10,
            (int) $secondProduct->fresh()->stock
        );

        $this->assertDatabaseHas('cart_items', [
            'id' => $firstCartItem->id,
            'cart_id' => $cart->id,
            'quantity' => 2,
        ]);

        $this->assertDatabaseHas('cart_items', [
            'id' => $secondCartItem->id,
            'cart_id' => $cart->id,
            'quantity' => 3,
        ]);

        $this->assertDatabaseCount('cart_items', 2);
    }

    private function createDistrict(): District
    {
        $city = City::query()->create([
            'name' => '臺中市',
        ]);

        return District::query()->create([
            'city_id' => $city->id,
            'name' => '西屯區',
            'postal_code' => '407',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validCheckoutPayload(
        District $district,
        string $paymentMethod = 'cod'
    ): array {
        return [
            'purchaser' => [
                'name' => '王小明',
                'phone' => '0912345678',
                'email' => 'user@example.com',
            ],
            'recipient' => [
                'name' => '王小華',
                'phone' => '0987654321',
                'district_id' => $district->id,
                'address' => '臺灣大道三段 100 號',
            ],
            'shipping_method' => 'home_delivery',
            'payment_method' => $paymentMethod,
        ];
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
