<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_list_orders(): void
    {
        $this->getJson('/api/orders')->assertUnauthorized();
    }

    public function test_disabled_member_is_rejected_with_c06_contract(): void
    {
        config(['sanctum.stateful' => ['localhost']]);
        $user = User::factory()->create(['status' => 'disabled']);
        $this->withHeader('Origin', 'http://localhost')->actingAs($user, 'web')
            ->getJson('/api/orders')->assertForbidden()->assertExactJson([
                'code' => 'ACCOUNT_DISABLED',
                'message' => '此會員帳號已停用，請聯絡管理員',
            ]);
    }

    public function test_empty_list_has_laravel_pagination_contract(): void
    {
        $response = $this->actingAs(User::factory()->create(), 'web')->getJson('/api/orders');
        $response->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.current_page', 1)
            ->assertJsonStructure(['data', 'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $this->assertArrayNotHasKey('success', $response->json());
    }

    public function test_members_only_see_their_own_orders_with_exact_nine_fields(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $own = $this->createOrder($a);
        $other = $this->createOrder($b);
        foreach ([[$a, $own], [$b, $other]] as [$user, $order]) {
            Auth::forgetGuards();
            $connection = DB::connection();
            $connection->enableQueryLog();
            $connection->flushQueryLog();
            $response = $this->actingAs($user, 'web')->getJson('/api/orders?user_id='.$other->user_id.'&per_page=100&search=missing&order_status=cancelled');
            $response->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $order->id)
                ->assertJsonPath('data.0.subtotal', '1200.00')->assertJsonPath('data.0.shipping_fee', '100.00')
                ->assertJsonPath('data.0.total_amount', '1300.00');
            $this->assertSame([
                'id', 'order_no', 'created_at', 'subtotal', 'shipping_fee', 'total_amount',
                'payment_method', 'payment_status', 'order_status',
            ], array_keys($response->json('data.0')));
            $this->assertSame($order->created_at->toISOString(), $response->json('data.0.created_at'));
            $this->assertStringNotContainsString('秘密收件地址', $response->getContent());
            foreach ($connection->getQueryLog() as $query) {
                $this->assertStringNotContainsString('order_items', $query['query']);
            }
            $connection->disableQueryLog();
        }
    }

    public function test_ten_per_page_sorted_by_created_at_then_id_descending(): void
    {
        $user = User::factory()->create();
        $ids = [];
        for ($i = 0; $i < 11; $i++) {
            $ids[] = $this->createOrder($user, ['created_at' => '2026-09-20 12:00:00'])->id;
        }
        // 較大的 id 但較早時間仍應排最後；較新時間的小 id 必須排前面。
        $old = $this->createOrder($user, ['created_at' => '2026-09-01 12:00:00']);
        Order::findOrFail($ids[0])->forceFill(['created_at' => '2026-09-30 12:00:00'])->save();
        $expected = [$ids[0], ...array_reverse(array_slice($ids, 1)), $old->id];
        $first = $this->actingAs($user, 'web')->getJson('/api/orders');
        $first->assertOk()->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 12)
            ->assertJsonPath('meta.per_page', 10)->assertJsonPath('meta.last_page', 2);
        $this->assertSame(array_slice($expected, 0, 10), array_column($first->json('data'), 'id'));
        $second = $this->getJson('/api/orders?page=2');
        $second->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.current_page', 2);
        $this->assertSame(array_slice($expected, 10), array_column($second->json('data'), 'id'));
        $this->assertStringContainsString('page=2', $first->json('links.next'));
    }

    public function test_page_uses_framework_defaults_without_forced_422(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        foreach (['0', '-1', 'abc'] as $page) {
            $this->getJson('/api/orders?page='.$page)->assertOk()->assertJsonPath('meta.current_page', 1);
        }
        $this->getJson('/api/orders?page=99')->assertOk()->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.current_page', 99);
    }

    private function createOrder(User $user, array $attributes = []): Order
    {
        $order = $user->orders()->create(array_merge([
            'order_no' => 'HF-'.Str::ulid(),
            'purchaser_name' => '測試訂購人', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'fixture@example.test', 'recipient_name' => '秘密收件人',
            'recipient_phone' => '0987654321', 'postal_code' => '100',
            'city' => '臺北市', 'district' => '中正區', 'address' => '秘密收件地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => '100.00',
            'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'pending',
            'subtotal' => '1200.00', 'total_amount' => '1300.00',
        ], $attributes));
        if (isset($attributes['created_at'])) {
            $order->forceFill(['created_at' => $attributes['created_at']])->save();
        }
        return $order;
    }

    public function test_detail_requires_login_and_active_member(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);
        $order = $this->createOrder($user);
        $this->getJson('/api/orders/'.$order->id)->assertUnauthorized();
        config(['sanctum.stateful' => ['localhost']]);
        Auth::forgetGuards();
        $this->withHeader('Origin', 'http://localhost')->actingAs($user, 'web')
            ->getJson('/api/orders/'.$order->id)->assertForbidden()->assertExactJson([
                'code' => 'ACCOUNT_DISABLED', 'message' => '此會員帳號已停用，請聯絡管理員',
            ]);
    }

    public function test_other_members_missing_and_non_numeric_orders_return_404(): void
    {
        $own = User::factory()->create();
        $otherOrder = $this->createOrder(User::factory()->create());
        $this->actingAs($own, 'web');
        foreach ([$otherOrder->id, 999999, 'abc', '-1', '1.5'] as $id) {
            $this->getJson('/api/orders/'.$id)->assertNotFound()->assertJsonMissingPath('data');
        }
    }

    public function test_detail_keeps_snapshots_after_products_variants_and_address_book_change(): void
    {
        $user = User::factory()->create();
        $root = Category::query()->create(['name' => '測試主分類', 'status' => 'active']);
        $category = Category::query()->create(['parent_id' => $root->id, 'name' => '測試分類', 'status' => 'active']);
        $product = Product::factory()->create(['category_id' => $category->id, 'name' => '現售商品', 'price' => '600.00']);
        $variant = ProductVariant::query()->create(['product_id' => $product->id,
            'option_name' => '重量', 'option_value' => '10kg', 'stock' => 5, 'status' => 'active']);
        $district = City::query()->create(['name' => '臺北市'])->districts()->create(['name' => '中正區', 'postal_code' => '100']);
        $address = $user->userAddresses()->create(['district_id' => $district->id, 'label' => '測試地址',
            'recipient_name' => '秘密收件人', 'recipient_phone' => '0987654321', 'address' => '秘密收件地址', 'is_default' => true]);
        $order = $this->createOrder($user);
        foreach ([null, $variant->id] as $variantId) {
            $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variantId,
                'product_code_snapshot' => 'SNAP-001', 'product_name_snapshot' => '當時的商品名稱',
                'variant_snapshot' => $variantId ? '重量：10kg' : null,
                'unit_price' => '600.00', 'quantity' => 1, 'subtotal' => '600.00']);
        }
        $this->actingAs($user, 'web');
        $before = $this->getJson('/api/orders/'.$order->id)->assertOk();
        $before->assertJsonCount(2, 'data.items')->assertJsonPath('data.items.0.product_name', '當時的商品名稱')
            ->assertJsonPath('data.items.0.variant', null)->assertJsonPath('data.items.1.variant', '重量：10kg')
            ->assertJsonPath('data.items.1.unit_price', '600.00')->assertJsonPath('data.items.1.quantity', 1)
            ->assertJsonPath('data.subtotal', '1200.00')->assertJsonPath('data.shipping_fee', '100.00')
            ->assertJsonPath('data.total_amount', '1300.00')->assertJsonPath('data.logistics_company', null)
            ->assertJsonPath('data.tracking_number', null)->assertJsonPath('data.recipient.address', '秘密收件地址')
            ->assertJsonMissingPath('data.can_cancel')->assertJsonMissingPath('success');
        $product->update(['name' => '後來的商品名稱', 'price' => '9999.00', 'status' => 'inactive']);
        $variant->update(['option_value' => '20kg', 'status' => 'inactive']);
        $address->update(['recipient_name' => '新收件人', 'address' => '新地址']);
        $user->update(['name' => '新會員姓名', 'email' => 'new@example.test']);
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        $after = $this->getJson('/api/orders/'.$order->id)->assertOk();
        $this->assertSame($before->json('data'), $after->json('data'));
        $queries = array_column($connection->getQueryLog(), 'query');
        $connection->disableQueryLog();
        $this->assertCount(1, array_filter($queries, fn ($query) => str_contains($query, 'order_items')));
        foreach (['products', 'product_variants', 'user_addresses'] as $table) {
            foreach ($queries as $query) $this->assertStringNotContainsString('from "'.$table.'"', $query);
        }
        $order->update(['logistics_company' => '測試物流', 'tracking_number' => 'TRACK-001']);
        $this->getJson('/api/orders/'.$order->id)->assertOk()
            ->assertJsonPath('data.logistics_company', '測試物流')->assertJsonPath('data.tracking_number', 'TRACK-001');
    }

    public function test_cod_and_mock_checkout_results_can_be_read_back_with_identical_contract(): void
    {
        config(['services.mock_credit_card.should_fail' => false]);
        $district = City::query()->create(['name' => '臺北市'])->districts()->create(['name' => '中正區', 'postal_code' => '100']);
        $root = Category::query()->create(['name' => '測試主分類', 'status' => 'active']);
        $category = Category::query()->create(['parent_id' => $root->id, 'name' => '測試分類', 'status' => 'active']);
        foreach (['cod', 'mock_credit_card'] as $method) {
            Auth::forgetGuards();
            $user = User::factory()->create();
            $product = Product::factory()->create(['category_id' => $category->id, 'stock' => 10, 'price' => '600.00']);
            $user->cart()->create()->items()->create(['product_id' => $product->id, 'quantity' => 2]);
            $this->actingAs($user, 'web');
            $created = $this->postJson('/api/checkout', [
                'purchaser' => ['name' => '原訂購人', 'phone' => '0912345678', 'email' => 'fixture@example.test'],
                'recipient' => ['name' => '原收件人', 'phone' => '0987654321', 'district_id' => $district->id, 'address' => '原地址'],
                'shipping_method' => 'home_delivery', 'payment_method' => $method,
            ])->assertCreated();
            $detail = $this->getJson('/api/orders/'.$created->json('data.id'))->assertOk();
            $this->assertSame($created->json('data'), $detail->json('data'));
            $detail->assertJsonPath('data.payment_status', $method === 'cod' ? 'unpaid' : 'paid');
        }
    }
}
