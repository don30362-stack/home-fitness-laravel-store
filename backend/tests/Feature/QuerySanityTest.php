<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QuerySanityTest extends TestCase
{
    use RefreshDatabase;

    private function measured(string $path): int
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $this->getJson($path)->assertOk();
            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    private function product(): Product
    {
        $root = Category::create(['name' => 'Root', 'status' => 'active']);
        $child = $root->children()->create(['name' => 'Child', 'status' => 'active']);
        $product = Product::factory()->create(['category_id' => $child->id, 'status' => 'active', 'stock' => 10]);
        $product->images()->create(['image_path' => 'fixture.png', 'image_type' => 'gallery', 'is_primary' => true, 'sort_order' => 0]);
        return $product;
    }

    public function test_public_list_detail_and_related_queries_are_bounded(): void
    {
        $product = $this->product();
        $small = $this->measured('/api/products');
        $detail = $this->measured('/api/products/'.$product->id);
        $product->specifications()->create(['spec_name' => 'A', 'spec_value' => 'B', 'sort_order' => 0]);
        for ($i = 0; $i < 9; $i++) {
            $this->product();
            $product->specifications()->create(['spec_name' => 'A', 'spec_value' => 'B', 'sort_order' => $i]);
        }
        $large = $this->measured('/api/products');
        $largeDetail = $this->measured('/api/products/'.$product->id);
        $this->assertSame($small, $large);
        $this->assertSame($detail, $largeDetail);
        Product::factory()->create(['category_id' => $product->category_id, 'status' => 'active']);
        $relatedSmall = $this->measured('/api/products/'.$product->id.'/related');
        Product::factory()->count(8)->create(['category_id' => $product->category_id, 'status' => 'active']);
        $relatedLarge = $this->measured('/api/products/'.$product->id.'/related');
        $this->assertSame($relatedSmall, $relatedLarge);
        $this->evidence('public', compact('small', 'large', 'detail', 'largeDetail', 'relatedSmall', 'relatedLarge'));
    }

    public function test_cart_distinct_category_paths_do_not_add_per_item_queries(): void
    {
        $user = User::factory()->create();
        $cart = $user->cart()->create();
        $cart->items()->create(['product_id' => $this->product()->id, 'quantity' => 1]);
        $this->actingAs($user, 'web');
        $small = $this->measured('/api/cart');
        for ($i = 0; $i < 9; $i++) $cart->items()->create(['product_id' => $this->product()->id, 'quantity' => 1]);
        $large = $this->measured('/api/cart');
        $this->assertSame($small, $large);
        $this->evidence('cart', compact('small', 'large'));
    }

    public function test_member_order_list_and_snapshot_detail_queries_are_bounded(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $product = $this->product();
        $makeOrder = fn ($number) => $user->orders()->create([
            'order_no' => 'HF-QUERY-'.$number, 'purchaser_name' => 'Fixture', 'purchaser_phone' => '0912345678',
            'purchaser_email' => 'fixture@example.test', 'recipient_name' => 'Fixture', 'recipient_phone' => '0912345678',
            'postal_code' => '100', 'city' => 'Fixture', 'district' => 'Fixture', 'address' => 'Fixture',
            'shipping_method' => 'home_delivery', 'shipping_fee' => '100.00', 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'order_status' => 'pending', 'subtotal' => '10.00', 'total_amount' => '110.00',
        ]);
        $order = $makeOrder(1);
        $item = ['product_id' => $product->id, 'product_variant_id' => null, 'product_code_snapshot' => 'FIXTURE',
            'product_name_snapshot' => 'Fixture', 'variant_snapshot' => null, 'unit_price' => '10.00', 'quantity' => 1, 'subtotal' => '10.00'];
        $order->items()->create($item);
        $small = $this->measured('/api/orders');
        $detail = $this->measured('/api/orders/'.$order->id);
        for ($i = 2; $i <= 10; $i++) {
            $makeOrder($i);
            $order->items()->create($item);
        }
        $large = $this->measured('/api/orders');
        $largeDetail = $this->measured('/api/orders/'.$order->id);
        $this->assertSame($small, $large);
        $this->assertSame($detail, $largeDetail);
        $this->evidence('member-orders', compact('small', 'large', 'detail', 'largeDetail'));
    }

    private function evidence(string $name, array $counts): void
    {
        // Optional local acceptance output; assertions never depend on its existence.
        if ($directory = getenv('STAGE28_QUERY_EVIDENCE')) {
            file_put_contents($directory.'/'.$name.'.json', json_encode($counts, JSON_PRETTY_PRINT));
        }
    }
}
