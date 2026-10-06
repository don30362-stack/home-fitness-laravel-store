<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderCancellationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminOrderCancellationApiTest extends TestCase
{
    use RefreshDatabase;

    private function permissionAdmin(array $attributes = []): \App\Models\Admin
    {
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $admin = \App\Models\Admin::factory()->create($attributes);
        $admin->permissions()->attach(\App\Models\Permission::query()->where('code', 'order_manage')->value('id'));
        return $admin;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    private function fixture(string $state = 'pending', string $payment = 'paid', string $mode = 'mixed'): array
    {
        $user = User::factory()->create();
        $order = $user->orders()->create([
            'order_no' => 'HF-'.Str::ulid(), 'purchaser_name' => '歷史訂購人', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'fixture@example.test', 'recipient_name' => '歷史收件人', 'recipient_phone' => '0987654321',
            'postal_code' => '100', 'city' => '臺北市', 'district' => '中正區', 'address' => '歷史地址',
            'shipping_method' => 'home_delivery', 'shipping_fee' => '100.00', 'payment_method' => 'cod',
            'payment_status' => $payment, 'order_status' => $state, 'subtotal' => '1200.00', 'total_amount' => '1300.00',
            'logistics_company' => '既有物流', 'tracking_number' => 'OLD-TRACK',
        ]);
        $root = Category::query()->create(['name' => '取消主分類', 'status' => 'inactive']);
        $child = $root->children()->create(['name' => '取消子分類', 'status' => 'inactive']);
        $plain = Product::factory()->create(['category_id' => $child->id, 'stock' => 5, 'status' => 'inactive']);
        $parent = Product::factory()->create(['category_id' => $child->id, 'stock' => null, 'status' => 'disabled']);
        $variant = $parent->variants()->create(['option_name' => '顏色', 'option_value' => '黑色', 'stock' => 7, 'status' => 'inactive']);
        foreach ([[$plain, null, 2, 'plain'], [$parent, $variant, 3, 'variant']] as [$product, $option, $quantity, $type]) {
            if ($mode !== 'mixed' && $mode !== $type) continue;
            $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $option?->id,
                'product_name_snapshot' => '下單商品', 'product_code_snapshot' => 'SNAP-001',
                'variant_snapshot' => $option ? '顏色：黑色' : null, 'unit_price' => '600.00', 'quantity' => $quantity,
                'subtotal' => (string) (600 * $quantity)]);
        }
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $plain->id, 'quantity' => 1]);
        return compact('user', 'order', 'plain', 'parent', 'variant', 'cart');
    }

    private function snapshot(array $tables): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toArray(), $tables);
    }

    public function test_guest_and_member_only_preserve_data(): void
    {
        $fixture = $this->fixture();
        ['user' => $user, 'order' => $order] = $fixture;
        $before = $this->snapshot(['orders', 'products', 'product_variants', 'carts', 'cart_items']);
        $url = '/api/admin/orders/'.$order->id.'/cancel';
        $this->postJson($url)->assertUnauthorized();
        $this->actingAs($user, 'web')->postJson($url)->assertUnauthorized();
        $this->assertEquals($before, $this->snapshot(['orders', 'products', 'product_variants', 'carts', 'cart_items']));
    }

    public function test_disabled_admin_preserves_member_and_cart(): void
    {
        ['user' => $user, 'order' => $order] = $this->fixture();
        $before = $this->snapshot(['orders', 'products', 'product_variants', 'carts', 'cart_items']);
        $this->actingAs($user, 'web')->actingAs(Admin::factory()->disabled()->create(), 'admin')
            ->postJson('/api/admin/orders/'.$order->id.'/cancel')->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
        $this->assertEquals($before, $this->snapshot(['orders', 'products', 'product_variants', 'carts', 'cart_items']));
    }

    public static function cancellationMatrix(): array
    {
        $cases = [];
        foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $state) {
            foreach (['paid', 'unpaid'] as $payment) $cases[] = [$state, $payment];
        }
        return $cases;
    }

    #[DataProvider('cancellationMatrix')]
    public function test_state_payment_stock_and_snapshot_contract(string $state, string $payment): void
    {
        ['order' => $order, 'user' => $owner, 'plain' => $plain, 'parent' => $parent, 'variant' => $variant] = $this->fixture($state, $payment);
        $this->actingAs($this->permissionAdmin(), 'admin');
        $unchanged = ['categories', 'order_items', 'carts', 'cart_items'];
        $before = $this->snapshot($unchanged);
        $orderBefore = $order->fresh()->getAttributes();
        $productBefore = $plain->fresh()->getAttributes();
        $variantBefore = $variant->fresh()->getAttributes();
        $url = '/api/admin/orders/'.$order->id.'/cancel';
        $response = $this->postJson($url);
        if (in_array($state, ['shipped', 'completed'], true)) {
            $response->assertUnprocessable()->assertJsonValidationErrors('order');
            $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        } else {
            $response->assertOk()->assertJsonPath('data.order_status', 'cancelled')->assertJsonPath('data.payment_status', $payment)
                ->assertJsonPath('data.user.id', $owner->id)->assertJsonPath('message', '訂單已取消。')
                ->assertJsonMissingPath('success')->assertJsonMissingPath('data.refunded')->assertJsonMissingPath('data.refund_status');
            $first = $this->snapshot(['orders', 'products', 'product_variants']);
            $this->postJson($url)->assertOk();
            $this->assertEquals($first, $this->snapshot(['orders', 'products', 'product_variants']));
        }
        $restore = in_array($state, ['pending', 'processing'], true);
        $this->assertSame($restore ? 7 : 5, $plain->fresh()->stock);
        $this->assertSame($restore ? 10 : 7, $variant->fresh()->stock);
        $this->assertNull($parent->fresh()->stock);
        $this->assertEquals($before, $this->snapshot($unchanged));
        $allowed = array_flip(['order_status', 'updated_at']);
        $this->assertSame(array_diff_key($orderBefore, $allowed), array_diff_key($order->fresh()->getAttributes(), $allowed));
        $stockAllowed = array_flip(['stock', 'updated_at']);
        $this->assertSame(array_diff_key($productBefore, $stockAllowed), array_diff_key($plain->fresh()->getAttributes(), $stockAllowed));
        $this->assertSame(array_diff_key($variantBefore, $stockAllowed), array_diff_key($variant->fresh()->getAttributes(), $stockAllowed));
    }

    public static function stockModes(): array { return [['plain'], ['variant']]; }

    #[DataProvider('stockModes')]
    public function test_single_stock_owner_restoration(string $mode): void
    {
        ['order' => $order, 'plain' => $plain, 'variant' => $variant] = $this->fixture(mode: $mode);
        $this->actingAs($this->permissionAdmin(), 'admin')->postJson('/api/admin/orders/'.$order->id.'/cancel')->assertOk();
        $this->assertSame($mode === 'plain' ? 7 : 5, $plain->fresh()->stock);
        $this->assertSame($mode === 'variant' ? 10 : 7, $variant->fresh()->stock);
    }

    public function test_cancel_uses_shared_service_and_loads_admin_user_after_transaction(): void
    {
        ['order' => $order] = $this->fixture();
        $fake = Mockery::mock(OrderCancellationService::class);
        $fake->shouldReceive('cancel')->once()->with($order->id)->andReturn($order->fresh()->load('items'));
        $this->app->instance(OrderCancellationService::class, $fake);
        $this->actingAs($this->permissionAdmin(), 'admin')->postJson('/api/admin/orders/'.$order->id.'/cancel')->assertOk()
            ->assertJsonPath('data.user.id', $order->user_id);
        $this->assertSame('pending', $order->fresh()->order_status, 'Controller contains no duplicate cancellation logic.');
    }

    public function test_missing_and_nonnumeric_ids(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
        foreach (['999999', 'abc', '-1', '1.5'] as $id) $this->postJson('/api/admin/orders/'.$id.'/cancel')->assertNotFound();
    }

    public function test_admin_cancel_bypasses_member_c06_in_real_shared_test_session(): void
    {
        ['order' => $order, 'user' => $user, 'cart' => $cart] = $this->fixture();
        $user->update(['password' => 'test-member-secret']);
        $admin = $this->permissionAdmin(['password' => 'test-admin-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'test-member-secret'])->assertOk();
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        Auth::forgetGuards(); Auth::shouldUse('web');
        $before = $this->snapshot(['carts', 'cart_items']);
        $this->postJson('/api/admin/orders/'.$order->id.'/cancel')->assertOk();
        $this->assertAuthenticatedAs($user, 'web'); $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertEquals($before, $this->snapshot(['carts', 'cart_items']));
        $this->assertDatabaseHas('carts', ['id' => $cart->id]);
    }

    public function test_late_order_write_failure_rolls_back_restored_stocks_through_admin_entry(): void
    {
        ['order' => $order, 'plain' => $plain, 'variant' => $variant] = $this->fixture();
        $before = $this->snapshot(['orders', 'products', 'product_variants', 'order_items', 'carts', 'cart_items']);
        $this->actingAs($this->permissionAdmin(), 'admin');
        $dispatcher = Order::getEventDispatcher(); Order::setEventDispatcher(clone $dispatcher);
        $reached = false;
        Order::updating(function () use ($plain, $variant, &$reached): void {
            $this->assertSame(7, $plain->fresh()->stock);
            $this->assertSame(10, $variant->fresh()->stock);
            $reached = true;
            throw new RuntimeException('isolated late order write failure');
        });
        try { $this->postJson('/api/admin/orders/'.$order->id.'/cancel')->assertStatus(500); }
        finally { Order::setEventDispatcher($dispatcher); }
        $this->assertTrue($reached);
        $this->assertEquals($before, $this->snapshot(['orders', 'products', 'product_variants', 'order_items', 'carts', 'cart_items']));
    }

    public function test_variant_reference_belonging_to_another_product_rolls_back_earlier_plain_restore(): void
    {
        ['order' => $order, 'plain' => $plain, 'parent' => $parent, 'variant' => $variant] = $this->fixture();
        $other = Product::factory()->create(['category_id' => $parent->category_id, 'stock' => null]);
        // All FKs remain valid, but the item's product and variant no longer refer to the same owner.
        $variant->update(['product_id' => $other->id]);
        $before = $this->snapshot(['orders', 'products', 'product_variants', 'order_items', 'carts', 'cart_items']);
        $this->actingAs($this->permissionAdmin(), 'admin')->postJson('/api/admin/orders/'.$order->id.'/cancel')
            ->assertUnprocessable()->assertJsonValidationErrors('order');
        $this->assertEquals($before, $this->snapshot(['orders', 'products', 'product_variants', 'order_items', 'carts', 'cart_items']));
        $this->assertSame(5, $plain->fresh()->stock);
    }
}
