<?php
namespace Tests\Feature;

use App\Models\{Admin, Category, Product, ProductVariant, User, City, District};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategorySaleEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $variant = false): array
    {
        $root = Category::create(['name'=>'root', 'status'=>'active']);
        $child = Category::create(['name'=>'child', 'parent_id'=>$root->id, 'status'=>'active']);
        $product = Product::factory()->create(['category_id'=>$child->id,'status'=>'active','stock'=>$variant ? null : 10]);
        $option = $variant ? ProductVariant::create(['product_id'=>$product->id,'option_name'=>'色','option_value'=>'黑','stock'=>10,'status'=>'active']) : null;
        $user = User::factory()->create();
        $cart = $user->cart()->create();
        $item = $cart->items()->create(['product_id'=>$product->id,'product_variant_id'=>$option?->id,'quantity'=>2]);
        return compact('root','child','product','option','user','cart','item');
    }

    public static function unavailable(): array
    {
        return ['parent'=>['root'], 'child'=>['child'], 'root product'=>['root_product'], 'third layer'=>['third_layer']];
    }

    #[DataProvider('unavailable')]
    public function test_public_endpoints_fail_closed(string $path): void
    {
        extract($this->fixture());
        $other = Product::factory()->create(['category_id'=>$child->id,'status'=>'active']);
        if ($path === 'root' || $path === 'child') ${$path}->update(['status'=>'inactive']);
        if ($path === 'root_product') $product->update(['category_id'=>$root->id]);
        if ($path === 'third_layer') {
            $top = Category::create(['name'=>'top','status'=>'active']);
            $root->update(['parent_id'=>$top->id]);
        }
        $this->getJson('/api/products')->assertOk()->assertJsonMissing(['id'=>$product->id,'product_code'=>$product->product_code]);
        $this->getJson('/api/products/'.$product->id)->assertNotFound();
        $this->getJson('/api/products/'.$product->id.'/related')->assertNotFound();
        if ($path !== 'root_product') $this->getJson('/api/products?category_id='.$child->id)->assertNotFound();
        if ($path === 'root' || $path === 'third_layer') $this->getJson('/api/products?parent_category_id='.$root->id)->assertNotFound();
        if ($path === 'child') $this->getJson('/api/products?parent_category_id='.$root->id)->assertOk()->assertJsonCount(0,'data');
    }

    public function test_public_filter_sort_and_related_keep_the_contract(): void
    {
        extract($this->fixture());
        $product->update(['name'=>'find me','price'=>120]);
        Product::factory()->create(['category_id'=>$child->id,'status'=>'active','price'=>200]);
        Product::factory()->create(['category_id'=>$child->id,'status'=>'inactive']);
        Product::factory()->create(['category_id'=>$child->id,'status'=>'disabled']);
        $this->getJson('/api/products?search=find&min_price=100&max_price=150&sort=price_desc')->assertOk()->assertJsonCount(1,'data')->assertJsonPath('meta.per_page',8);
        $this->getJson('/api/products/'.$product->id)->assertOk();
        $this->getJson('/api/products/'.$product->id.'/related')->assertOk()->assertJsonCount(1,'data');
        $this->getJson('/api/products?category_id='.$root->id)->assertNotFound();
        $this->getJson('/api/products?category_id=invalid')->assertUnprocessable();
    }

    public static function transactionPaths(): array
    {
        return ['plain parent'=>[false,'root'],'plain child'=>[false,'child'],'variant parent'=>[true,'root'],'variant child'=>[true,'child']];
    }

    #[DataProvider('transactionPaths')]
    public function test_cart_and_checkout_reject_category_then_restore(bool $variant, string $target): void
    {
        extract($this->fixture($variant));
        ${$target}->update(['status'=>'inactive']);
        $this->actingAs($user,'web');
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.items.0.is_available',false)->assertJsonPath('data.items.0.unavailable_reason','商品分類目前無法購買')->assertJsonPath('data.has_unavailable_items',true);
        $payload = ['product_id'=>$product->id,'product_variant_id'=>$option?->id,'quantity'=>1];
        $this->postJson('/api/cart/items',$payload)->assertUnprocessable();
        $this->patchJson('/api/cart/items/'.$item->id,['quantity'=>1])->assertUnprocessable();
        $this->postJson('/api/cart/merge',['items'=>[$payload]])->assertUnprocessable();
        $city = City::create(['name'=>'測試市']);
        $district = District::create(['city_id'=>$city->id,'name'=>'測試區','postal_code'=>'100']);
        $checkout = ['purchaser'=>['name'=>'驗收','phone'=>'0912345678','email'=>'test@example.test'],
            'recipient'=>['name'=>'驗收','phone'=>'0912345678','district_id'=>$district->id,'address'=>'測試地址'],
            'shipping_method'=>'home_delivery','payment_method'=>'cod'];
        $this->postJson('/api/checkout',$checkout)->assertUnprocessable()->assertJsonValidationErrors('cart');
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('order_items',0);
        $this->assertDatabaseHas('cart_items',['id'=>$item->id,'quantity'=>2]);
        $this->assertEquals(10,$option?->fresh()->stock ?? $product->fresh()->stock);
        ${$target}->update(['status'=>'active']);
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.items.0.is_available',true)->assertJsonPath('data.has_unavailable_items',false);
        $this->postJson('/api/checkout',$checkout)->assertCreated();
        $this->assertEquals(8,$option?->fresh()->stock ?? $product->fresh()->stock);
    }

    public function test_restore_does_not_override_product_variant_or_stock_and_removal_is_allowed(): void
    {
        extract($this->fixture(true));
        $child->update(['status'=>'inactive']);
        $option->update(['status'=>'inactive']);
        $child->update(['status'=>'active']);
        $this->actingAs($user,'web');
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.unavailable_reason','商品規格已停用');
        $option->update(['status'=>'active','stock'=>1]);
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.unavailable_reason','商品庫存不足');
        $product->update(['status'=>'inactive']);
        $this->getJson('/api/cart')->assertJsonPath('data.items.0.unavailable_reason','商品已下架');
        $child->update(['status'=>'inactive']);
        $this->deleteJson('/api/cart/items/'.$item->id)->assertOk();
        $this->assertDatabaseCount('cart_items',0);
        $this->deleteJson('/api/cart')->assertOk();
    }


    #[DataProvider('unavailable')]
    public function test_invalid_paths_cannot_buy_or_make_cart_items_available(string $path): void
    {
        extract($this->fixture());
        if ($path === 'root' || $path === 'child') ${$path}->update(['status'=>'inactive']);
        if ($path === 'root_product') $product->update(['category_id'=>$root->id]);
        if ($path === 'third_layer') {
            $top=Category::create(['name'=>'top']);
            $root->update(['parent_id'=>$top->id]);
        }
        $this->actingAs($user,'web');
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.items.0.is_available',false)->assertJsonPath('data.has_unavailable_items',true);
        $this->postJson('/api/cart/items',['product_id'=>$product->id,'quantity'=>1])->assertUnprocessable();
        $city=City::create(['name'=>'city']);
        $district=District::create(['city_id'=>$city->id,'name'=>'district','postal_code'=>'100']);
        $this->postJson('/api/checkout',[
            'purchaser'=>['name'=>'驗收','phone'=>'0912345678','email'=>'test@example.test'],
            'recipient'=>['name'=>'驗收','phone'=>'0912345678','district_id'=>$district->id,'address'=>'測試地址'],
            'shipping_method'=>'home_delivery','payment_method'=>'cod',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('orders',0);
        $this->assertDatabaseCount('order_items',0);
        $this->assertDatabaseHas('cart_items',['id'=>$item->id,'quantity'=>2]);
        $this->assertEquals(10,$product->fresh()->stock);
    }

    public function test_cart_categories_are_eager_loaded_without_per_item_queries(): void
    {
        extract($this->fixture());
        for ($i=0;$i<5;$i++) {
            $another = Product::factory()->create(['category_id'=>$child->id,'stock'=>5]);
            $cart->items()->create(['product_id'=>$another->id,'quantity'=>1]);
        }
        $this->actingAs($user,'web');
        $queries=[];
        \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) { if (str_contains($query->sql,'"categories"')) $queries[]=$query->sql; });
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.has_unavailable_items',false);
        $this->assertCount(2,$queries);
    }
}
