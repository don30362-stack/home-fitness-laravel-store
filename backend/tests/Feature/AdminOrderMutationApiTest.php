<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminOrderMutationApiTest extends TestCase
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

    private function order(array $attributes = [], ?User $user = null): Order
    {
        return Order::query()->forceCreate(array_merge([
            'user_id' => ($user ?? User::factory()->create())->id, 'order_no' => 'HF-'.Str::ulid(),
            'purchaser_name' => '訂購快照', 'purchaser_phone' => '0912345678', 'purchaser_email' => 'snapshot@example.test',
            'recipient_name' => '收件快照', 'recipient_phone' => '0987654321', 'postal_code' => '100',
            'city' => '臺北市', 'district' => '中正區', 'address' => '歷史地址', 'shipping_method' => 'home_delivery',
            'shipping_fee' => '100.00', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'order_status' => 'pending',
            'subtotal' => '1200.00', 'total_amount' => '1300.00', 'updated_at' => '2026-09-01 00:00:00',
        ], $attributes));
    }

    private function login(): void
    {
        $this->actingAs($this->permissionAdmin(), 'admin');
    }

    public static function endpoints(): array
    {
        return [
            ['status', ['order_status' => 'processing']],
            ['payment-status', ['payment_status' => 'paid']],
            ['shipment', ['logistics_company' => '物流', 'tracking_number' => 'TRACK-01']],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_guest_and_member_only_cannot_mutate(string $endpoint, array $payload): void
    {
        $this->patchJson('/api/admin/orders/1/'.$endpoint, $payload)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'web')->patchJson('/api/admin/orders/1/'.$endpoint, $payload)->assertUnauthorized();
    }

    #[DataProvider('endpoints')]
    public function test_disabled_admin_rejected_and_member_preserved(string $endpoint, array $payload): void
    {
        $member = User::factory()->create();
        $this->actingAs($member, 'web')->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->patchJson('/api/admin/orders/1/'.$endpoint, $payload)->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($member, 'web');
        $this->assertGuest('admin');
    }

    public function test_real_shared_session_mutations_bypass_disabled_member_c06_and_preserve_cart(): void
    {
        $member = User::factory()->create(['password' => 'test-member-secret']);
        $admin = $this->permissionAdmin(['password' => 'test-admin-secret']);
        $this->postJson('/api/login', ['email' => $member->email, 'password' => 'test-member-secret'])->assertOk();
        Auth::forgetGuards(); Auth::shouldUse('web');
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'test-admin-secret'])->assertOk();
        DB::table('users')->where('id', $member->id)->update(['status' => 'disabled']);
        $cart = $member->cart()->create();
        foreach (self::endpoints() as [$endpoint, $payload]) {
            Auth::forgetGuards(); Auth::shouldUse('web');
            $order = $this->order(['order_status' => $endpoint === 'shipment' ? 'processing' : 'pending'], $member);
            $this->patchJson('/api/admin/orders/'.$order->id.'/'.$endpoint, $payload)->assertOk();
            $this->assertAuthenticatedAs($member, 'web');
            $this->assertAuthenticatedAs($admin, 'admin');
            $this->assertDatabaseHas('carts', ['id' => $cart->id]);
        }
    }

    public static function statusMatrix(): array
    {
        $cases = [];
        foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $from) {
            foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $to) {
                $ok = $from !== 'cancelled' && ($from === $to || ($from === 'pending' && $to === 'processing') || ($from === 'shipped' && $to === 'completed'));
                $cases[$from.' to '.$to] = [$from, $to, 'paid', $ok];
            }
        }
        $cases['unpaid shipped cannot complete'] = ['shipped', 'completed', 'unpaid', false];
        $cases['unpaid shipped same-state allowed'] = ['shipped', 'shipped', 'unpaid', true];
        return $cases;
    }

    #[DataProvider('statusMatrix')]
    public function test_status_matrix(string $from, string $to, string $payment, bool $ok): void
    {
        $this->login();
        $order = $this->order(['order_status' => $from, 'payment_status' => $payment]);
        $before = $order->fresh()->getAttributes();
        $response = $this->patchJson('/api/admin/orders/'.$order->id.'/status', ['order_status' => $to]);
        if ($ok) {
            $response->assertOk()->assertJsonPath('data.order_status', $to)->assertJsonPath('data.payment_status', $payment)
                ->assertJsonStructure(['data', 'message'])->assertJsonMissingPath('success');
            $this->assertSame($to, $order->fresh()->order_status);
            if ($from === $to) $this->assertSame($before, $order->fresh()->getAttributes());
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('order_status');
            $this->assertSame($before, $order->fresh()->getAttributes());
        }
    }

    public static function paymentMatrix(): array
    {
        $cases = [];
        foreach (['pending', 'processing', 'shipped', 'completed', 'cancelled'] as $state) {
            foreach ([['unpaid', 'paid'], ['unpaid', 'unpaid'], ['paid', 'paid'], ['paid', 'unpaid']] as [$from, $to]) {
                $cases[$state.' '.$from.' to '.$to] = [$state, $from, $to, 'cod', $state !== 'cancelled' && ! ($from === 'paid' && $to === 'unpaid')];
            }
        }
        $cases['mock card mark paid'] = ['processing', 'unpaid', 'paid', 'mock_credit_card', true];
        return $cases;
    }

    #[DataProvider('paymentMatrix')]
    public function test_payment_matrix_and_legacy_completion(string $state, string $from, string $to, string $method, bool $ok): void
    {
        $this->login();
        $order = $this->order(['order_status' => $state, 'payment_status' => $from, 'payment_method' => $method]);
        $before = $order->fresh()->getAttributes();
        $response = $this->patchJson('/api/admin/orders/'.$order->id.'/payment-status', ['payment_status' => $to]);
        if ($ok) {
            $response->assertOk()->assertJsonPath('data.payment_status', $to)->assertJsonPath('data.order_status', $state);
            if ($from === $to) $this->assertSame($before, $order->fresh()->getAttributes());
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('payment_status');
            $this->assertSame($before, $order->fresh()->getAttributes());
        }
    }

    public static function shipmentMatrix(): array
    {
        return [['pending', false], ['processing', true], ['shipped', true], ['completed', false], ['cancelled', false]];
    }

    #[DataProvider('shipmentMatrix')]
    public function test_shipment_matrix_with_unpaid_cod_and_corrections(string $state, bool $ok): void
    {
        $this->login();
        $order = $this->order(['order_status' => $state, 'logistics_company' => '舊物流', 'tracking_number' => 'OLD']);
        $before = $order->fresh()->getAttributes();
        $response = $this->patchJson('/api/admin/orders/'.$order->id.'/shipment', ['logistics_company' => ' 新物流 ', 'tracking_number' => ' NEW ']);
        if ($ok) {
            $response->assertOk()->assertJsonPath('data.order_status', 'shipped')->assertJsonPath('data.payment_status', 'unpaid')
                ->assertJsonPath('data.logistics_company', '新物流')->assertJsonPath('data.tracking_number', 'NEW');
        } else {
            $response->assertUnprocessable()->assertJsonValidationErrors('logistics_company');
            $this->assertSame($before, $order->fresh()->getAttributes());
        }
    }

    public static function invalidPayloads(): array
    {
        $cases = [];
        foreach (['status' => 'order_status', 'payment-status' => 'payment_status'] as $endpoint => $field) {
            foreach ([null, [], 'bad', 123] as $value) $cases[] = [$endpoint, [$field => $value], $field];
            $cases[] = [$endpoint, [], $field];
            $cases[] = [$endpoint, [$field => $endpoint === 'status' ? 'processing' : 'paid', 'extra' => null], 'extra'];
        }
        foreach (['logistics_company', 'tracking_number'] as $field) {
            foreach ([null, '', '   ', ['bad'], str_repeat('x', 101)] as $value) {
                $cases[] = ['shipment', array_merge(['logistics_company' => '物流', 'tracking_number' => 'TRACK'], [$field => $value]), $field];
            }
            $cases[] = ['shipment', [$field === 'logistics_company' ? 'tracking_number' : 'logistics_company' => 'value'], $field];
        }
        $cases[] = ['shipment', ['logistics_company' => '物流', 'tracking_number' => 'TRACK', 'order_status' => 'completed'], 'order_status'];
        return $cases;
    }

    #[DataProvider('invalidPayloads')]
    public function test_strict_schema_validation(string $endpoint, array $payload, string $field): void
    {
        $this->login();
        $order = $this->order(['order_status' => 'processing']);
        $before = $order->fresh()->getAttributes();
        $this->patchJson('/api/admin/orders/'.$order->id.'/'.$endpoint, $payload)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    #[DataProvider('endpoints')]
    public function test_missing_numeric_and_non_numeric_ids_are_404(string $endpoint, array $payload): void
    {
        $this->login();
        foreach (['999999', 'abc', '-1', '1.5'] as $id) $this->patchJson('/api/admin/orders/'.$id.'/'.$endpoint, $payload)->assertNotFound();
    }

    #[DataProvider('endpoints')]
    public function test_mutation_has_no_stock_history_or_cart_side_effects(string $endpoint, array $payload): void
    {
        $this->login();
        $order = $this->order(['order_status' => $endpoint === 'shipment' ? 'processing' : 'pending']);
        $root = Category::query()->create(['name' => '主分類']);
        $child = $root->children()->create(['name' => '子分類']);
        $plain = Product::factory()->create(['category_id' => $child->id, 'stock' => 17]);
        $product = Product::factory()->create(['category_id' => $child->id, 'stock' => null]);
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'option_name' => '顏色', 'option_value' => '黑', 'stock' => 13]);
        $order->items()->create(['product_id' => $product->id, 'product_variant_id' => $variant->id,
            'product_name_snapshot' => '歷史名稱', 'product_code_snapshot' => 'SNAP-001', 'variant_snapshot' => '顏色：黑',
            'quantity' => 2, 'unit_price' => '600.00', 'subtotal' => '1200.00']);
        $order->user->cart()->create()->items()->create(['product_id' => $plain->id, 'quantity' => 1]);
        $before = [];
        foreach (['products', 'product_variants', 'order_items', 'carts', 'cart_items'] as $table) $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
        $orderBefore = $order->fresh()->getAttributes();
        $queries = []; $connection = DB::connection(); $connection->enableQueryLog(); $connection->flushQueryLog();
        $this->patchJson('/api/admin/orders/'.$order->id.'/'.$endpoint, $payload)->assertOk()->assertJsonStructure(['data' => ['user', 'items'], 'message']);
        $queries = array_column($connection->getQueryLog(), 'query'); $connection->disableQueryLog();
        foreach ($before as $table => $rows) $this->assertEquals($rows, DB::table($table)->orderBy('id')->get()->toArray());
        $allowed = match ($endpoint) {
            'status' => ['order_status', 'updated_at'], 'payment-status' => ['payment_status', 'updated_at'],
            'shipment' => ['order_status', 'logistics_company', 'tracking_number', 'updated_at'],
        };
        $this->assertSame(array_diff_key($orderBefore, array_flip($allowed)), array_diff_key($order->fresh()->getAttributes(), array_flip($allowed)));
        foreach ($queries as $sql) {
            foreach (['products', 'product_variants', 'carts', 'cart_items'] as $table) $this->assertStringNotContainsString('from "'.$table.'"', $sql);
        }
    }

    public static function rollbackCases(): array
    {
        return [['status', ['order_status' => 'processing']], ['shipment', ['logistics_company' => '物流', 'tracking_number' => 'TRACK']]];
    }

    #[DataProvider('rollbackCases')]
    public function test_after_sql_failure_rolls_back_order_without_production_test_seam(string $endpoint, array $payload): void
    {
        $this->login();
        $order = $this->order(['order_status' => $endpoint === 'shipment' ? 'processing' : 'pending']);
        $before = $order->fresh()->getAttributes();
        $dispatcher = Order::getEventDispatcher(); Order::setEventDispatcher(clone $dispatcher);
        $afterSql = false;
        Order::updated(function (Order $updated) use (&$afterSql, $payload): void {
            $this->assertGreaterThan(0, DB::transactionLevel());
            foreach ($payload as $key => $value) $this->assertSame($value, $updated->fresh()->getAttribute($key));
            $afterSql = true;
            throw new RuntimeException('isolated after-SQL failure');
        });
        try {
            $this->patchJson('/api/admin/orders/'.$order->id.'/'.$endpoint, $payload)->assertStatus(500);
        } finally {
            Order::setEventDispatcher($dispatcher);
        }
        $this->assertTrue($afterSql);
        $this->assertSame($before, $order->fresh()->getAttributes());
    }
}
