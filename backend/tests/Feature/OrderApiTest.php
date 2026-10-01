<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
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
            DB::enableQueryLog();
            DB::flushQueryLog();
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
            foreach (DB::getQueryLog() as $query) {
                $this->assertStringNotContainsString('order_items', $query['query']);
            }
            DB::disableQueryLog();
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
}
