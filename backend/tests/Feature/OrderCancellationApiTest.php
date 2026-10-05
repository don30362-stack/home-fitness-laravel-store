<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class OrderCancellationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_disabled_member_cannot_cancel_or_change_data(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);
        $order = $this->order($user);
        $product = $this->product();
        $this->item($order, $product);
        $before = $this->snapshot();

        $this->postJson($this->url($order))->assertUnauthorized();
        Auth::forgetGuards();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost')->actingAs($user, 'web')
            ->postJson($this->url($order))->assertForbidden()->assertExactJson([
                'code' => 'ACCOUNT_DISABLED',
                'message' => '此會員帳號已停用，請聯絡管理員',
            ]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_ownership_missing_and_non_numeric_ids_are_404_even_when_cancelled(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $order = $this->order($other, ['order_status' => 'cancelled']);
        $this->item($order, $this->product());
        $before = $this->snapshot();
        $this->actingAs($user, 'web');
        foreach ([$order->id, 999999, 'abc', '-1', '1.5'] as $id) {
            $this->postJson('/api/orders/'.$id.'/cancel', ['user_id' => $other->id])
                ->assertNotFound()->assertJsonMissingPath('data');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function allowedStatesAndPayments(): array
    {
        return [
            'pending COD' => ['pending', 'cod', 'unpaid'],
            'processing COD' => ['processing', 'cod', 'unpaid'],
            'pending paid mock' => ['pending', 'mock_credit_card', 'paid'],
            'processing paid mock' => ['processing', 'mock_credit_card', 'paid'],
        ];
    }

    #[DataProvider('allowedStatesAndPayments')]
    public function test_cancel_and_repeat_preserve_snapshots_payment_and_cart(
        string $status, string $method, string $payment
    ): void {
        $user = User::factory()->create();
        $order = $this->order($user, ['order_status' => $status, 'payment_method' => $method, 'payment_status' => $payment]);
        $product = $this->product();
        $this->item($order, $product, null, 3);
        $this->actingAs($user, 'web');
        $before = $this->getJson('/api/orders/'.$order->id)->assertOk()->json('data');
        $itemsBefore = OrderItem::query()->get()->toArray();
        $orderBefore = $order->fresh()->getAttributes();

        $response = $this->postJson($this->url($order), ['user_id' => 999999, 'quantity' => 100, 'payment_status' => 'refunded'])
            ->assertOk()->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('data.payment_status', $payment)->assertJsonPath('message', '訂單已取消。')
            ->assertJsonMissingPath('success')->assertJsonMissingPath('data.can_cancel');
        $before['order_status'] = 'cancelled';
        $this->assertSame($before, $response->json('data'));
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertSame($itemsBefore, OrderItem::query()->get()->toArray());
        $afterAttributes = $order->fresh()->getAttributes();
        unset($orderBefore['order_status'], $orderBefore['updated_at'], $afterAttributes['order_status'], $afterAttributes['updated_at']);
        $this->assertSame($orderBefore, $afterAttributes);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('order_items', 1);
        $this->assertDatabaseCount('carts', 0);
        $this->assertDatabaseCount('cart_items', 0);

        $state = $this->snapshot();
        $this->postJson($this->url($order))->assertOk()->assertJsonPath('data.order_status', 'cancelled')
            ->assertJsonPath('data.payment_status', $payment);
        $this->assertSame($state, $this->snapshot());
    }

    public static function rejectedStates(): array
    {
        return [['shipped'], ['completed'], ['unknown']];
    }

    #[DataProvider('rejectedStates')]
    public function test_disallowed_status_has_422_and_no_changes(string $status): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['order_status' => $status]);
        $this->item($order, $this->product());
        $before = $this->snapshot();
        $this->actingAs($user, 'web')->postJson($this->url($order))->assertUnprocessable()
            ->assertJsonPath('message', '此訂單目前的狀態不允許取消。');
        $this->assertSame($before, $this->snapshot());
    }

    public static function inventoryCases(): array
    {
        return [
            'one product' => [[['plain', 2]]],
            'multiple products' => [[['plain', 2], ['plain', 4]]],
            'one variant' => [[['variant', 3]]],
            'multiple variants same product' => [[['variant', 2], ['variant', 4]]],
            'mixed' => [[['plain', 2], ['variant', 3], ['variant', 4]]],
            'inactive product' => [[['inactive-product', 2]]],
            'inactive variant and product' => [[['inactive-variant', 4]]],
        ];
    }

    #[DataProvider('inventoryCases')]
    public function test_restores_original_inventory_location_including_inactive(array $lines): void
    {
        $user = User::factory()->create();
        $order = $this->order($user);
        $variantProduct = $this->product(['stock' => count($lines) === 1 && $lines[0][0] === 'variant' ? 37 : null]);
        $parentStockBefore = $variantProduct->stock;
        $expected = [];
        foreach ($lines as [$kind, $quantity]) {
            $isVariant = in_array($kind, ['variant', 'inactive-variant'], true);
            $product = $isVariant ? $variantProduct : $this->product();
            $variant = $isVariant ? $this->variant($product) : null;
            if (str_starts_with($kind, 'inactive')) {
                $product->update(['status' => 'inactive']);
                $variant?->update(['status' => 'inactive']);
            }
            $this->item($order, $product, $variant, $quantity);
            $expected[] = [$variant ?? $product, 5 + $quantity];
        }
        $this->actingAs($user, 'web')->postJson($this->url($order))->assertOk();
        foreach ($expected as [$stockOwner, $stock]) {
            $this->assertSame($stock, (int) $stockOwner->fresh()->stock);
        }
        $this->assertSame($parentStockBefore, $variantProduct->fresh()->stock);
        $this->assertDatabaseCount('order_items', count($lines));
    }

    public static function missingReferences(): array
    {
        return [['product'], ['variant']];
    }

    #[DataProvider('missingReferences')]
    public function test_missing_reference_rolls_back_earlier_inventory(string $missing): void
    {
        // Deferred FK violations exist only inside this SQLite test transaction.
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $user = User::factory()->create();
        $order = $this->order($user, ['payment_method' => 'mock_credit_card', 'payment_status' => 'paid']);
        $first = $this->product();
        $last = $this->product();
        $this->item($order, $first, null, 2);
        $broken = $this->item($order, $last, null, 3);
        $broken->update($missing === 'product' ? ['product_id' => 999999] : ['product_variant_id' => 999999]);
        $before = $this->snapshot();
        $this->actingAs($user, 'web')->postJson($this->url($order))->assertUnprocessable()
            ->assertJsonValidationErrors('order');
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(5, (int) $first->fresh()->stock);
        $this->assertSame('pending', $order->fresh()->order_status);
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_late_inventory_write_failure_rolls_back_earlier_write(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user, ['payment_status' => 'paid']);
        $first = $this->product();
        $last = $this->product();
        $this->item($order, $first, null, 2);
        $this->item($order, $last, null, 3);
        $before = $this->snapshot();
        $dispatcher = Product::getEventDispatcher();
        Product::setEventDispatcher(clone $dispatcher);
        $reachedLateWrite = false;
        Product::updating(function (Product $product) use ($last, $first, &$reachedLateWrite) {
            if ($product->id === $last->id) {
                $this->assertSame(7, (int) $first->fresh()->stock);
                $reachedLateWrite = true;
                throw new RuntimeException('Simulated late inventory failure');
            }
        });
        try {
            $this->actingAs($user, 'web')->postJson($this->url($order))->assertStatus(500);
        } finally {
            Product::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($reachedLateWrite);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_failed_order_write_rolls_back_inventory_and_keeps_existing_cart(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user);
        $product = $this->product();
        $this->item($order, $product);
        $user->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 1]);
        $before = $this->snapshot();
        $dispatcher = Order::getEventDispatcher();
        Order::setEventDispatcher(clone $dispatcher);
        Order::updating(fn () => false);
        try {
            $this->actingAs($user, 'web')->postJson($this->url($order))->assertStatus(500);
        } finally {
            Order::setEventDispatcher($dispatcher);
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_core_rereads_status_instead_of_using_a_stale_order(): void
    {
        $order = $this->order(User::factory()->create());
        $product = $this->product();
        $this->item($order, $product);
        Order::query()->whereKey($order->id)->update(['order_status' => 'shipped']);
        $this->assertSame('pending', $order->order_status);
        try {
            app(OrderCancellationService::class)->cancel($order->id);
            $this->fail('The current shipped status must reject cancellation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('order', $exception->errors());
        }
        $this->assertSame('shipped', $order->fresh()->order_status);
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_inventory_lock_queries_follow_checkout_order_and_existing_cart_is_untouched(): void
    {
        $user = User::factory()->create();
        $order = $this->order($user);
        $first = $this->product(['stock' => null]);
        $last = $this->product();
        $variantA = $this->variant($first);
        $variantB = $this->variant($first);
        // Reverse insertion must not determine lock acquisition order.
        $this->item($order, $last);
        $this->item($order, $first, $variantB);
        $this->item($order, $first, $variantA);
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $last->id, 'quantity' => 4]);
        $cartBefore = DB::table('cart_items')->get()->map(fn ($row) => (array) $row)->all();
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $this->actingAs($user, 'web')->postJson($this->url($order))->assertOk();
            $locks = [];
            foreach ($connection->getQueryLog() as $query) {
                if (str_starts_with($query['query'], 'select * from "products"')) {
                    $locks[] = ['product', $query['bindings'][0]];
                } elseif (str_starts_with($query['query'], 'select * from "product_variants"')) {
                    $locks[] = ['variant', $query['bindings'][0]];
                }
            }
        } finally {
            $connection->disableQueryLog();
        }
        // SQLite strips FOR UPDATE: this verifies sequence, not real row locks.
        $this->assertSame([
            ['product', $first->id], ['variant', $variantA->id],
            ['product', $first->id], ['variant', $variantB->id], ['product', $last->id],
        ], $locks);
        $this->assertSame($cartBefore, DB::table('cart_items')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertDatabaseCount('carts', 1);
    }

    public function test_cod_and_paid_mock_checkout_then_cancel_restore_exact_original_mixed_stock(): void
    {
        config(['services.mock_credit_card.should_fail' => false]);
        $district = City::query()->create(['name' => '臺北市'])->districts()->create(['name' => '中正區', 'postal_code' => '100']);
        foreach (['cod', 'mock_credit_card'] as $method) {
            Auth::forgetGuards();
            $user = User::factory()->create();
            $plain = $this->product();
            $parent = $this->product(['stock' => null]);
            $variant = $this->variant($parent);
            $cart = $user->cart()->create();
            $cart->items()->create(['product_id' => $plain->id, 'quantity' => 2]);
            $cart->items()->create(['product_id' => $parent->id, 'product_variant_id' => $variant->id, 'quantity' => 3]);
            $this->actingAs($user, 'web');
            $created = $this->postJson('/api/checkout', [
                'purchaser' => ['name' => '原訂購人', 'phone' => '0912345678', 'email' => 'fixture@example.test'],
                'recipient' => ['name' => '原收件人', 'phone' => '0987654321', 'district_id' => $district->id, 'address' => '原地址'],
                'shipping_method' => 'home_delivery', 'payment_method' => $method,
            ])->assertCreated();
            $this->assertSame(3, (int) $plain->fresh()->stock);
            $this->assertSame(2, (int) $variant->fresh()->stock);
            $expected = $created->json('data');
            $expected['order_status'] = 'cancelled';
            $cancelled = $this->postJson('/api/orders/'.$expected['id'].'/cancel')->assertOk();
            $this->assertSame($expected, $cancelled->json('data'));
            $this->assertSame(5, (int) $plain->fresh()->stock);
            $this->assertSame(5, (int) $variant->fresh()->stock);
            $this->assertNull($parent->fresh()->stock);
            $this->assertSame(0, $cart->items()->count());
        }
    }

    private function url(Order $order): string
    {
        return '/api/orders/'.$order->id.'/cancel';
    }

    private function product(array $attributes = []): Product
    {
        $root = Category::query()->firstOrCreate(['name' => '取消測試主分類'], ['status' => 'active']);
        $category = Category::query()->firstOrCreate(['parent_id' => $root->id, 'name' => '取消測試分類'], ['status' => 'active']);
        return Product::factory()->create(array_merge(['category_id' => $category->id, 'stock' => 5], $attributes));
    }

    private function variant(Product $product): ProductVariant
    {
        return $product->variants()->create([
            'option_name' => '重量', 'option_value' => (string) Str::ulid(), 'stock' => 5, 'status' => 'active',
        ]);
    }

    private function item(Order $order, Product $product, ?ProductVariant $variant = null, int $quantity = 2): OrderItem
    {
        return $order->items()->create([
            'product_id' => $product->id, 'product_variant_id' => $variant?->id,
            'product_code_snapshot' => 'SNAP-001', 'product_name_snapshot' => '歷史商品',
            'variant_snapshot' => $variant ? '重量：原規格' : null,
            'unit_price' => '600.00', 'quantity' => $quantity, 'subtotal' => (string) (600 * $quantity),
        ]);
    }

    private function order(User $user, array $attributes = []): Order
    {
        return $user->orders()->create(array_merge([
            'order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '原訂購人', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'fixture@example.test', 'recipient_name' => '原收件人', 'recipient_phone' => '0987654321',
            'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '原地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => '100.00',
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'pending',
            'subtotal' => '1200.00', 'total_amount' => '1300.00',
        ], $attributes));
    }

    private function snapshot(): array
    {
        $snapshot = [];
        foreach (['orders', 'order_items', 'products', 'product_variants', 'carts', 'cart_items'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }
        return $snapshot;
    }
}
