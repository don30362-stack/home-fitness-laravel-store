<?php
namespace Tests\Feature;

use App\Models\{Admin, Category, Product, ProductVariant, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminCategoryStatusApiTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp(): void { parent::setUp(); config(['sanctum.stateful'=>['localhost']]); $this->withHeader('Origin','http://localhost'); }
    private function path(int|string $id): string { return '/api/admin/categories/'.$id.'/status'; }
    public static function invalid(): array
    {
        return array_map(fn ($payload) => [$payload], [['status'=>'disabled'],[],['status'=>null],['status'=>'active','name'=>'overwrite'],['status'=>'active','parent_id'=>null],['status'=>'active','sort_order'=>1],['status'=>'active','extra'=>1]]);
    }
    #[DataProvider('invalid')]
    public function test_rejects_invalid_or_extra_fields(array $payload): void
    {
        $category=Category::create(['name'=>'root']);
        $this->actingAs(Admin::factory()->create(),'admin')->patchJson($this->path($category->id),$payload)->assertUnprocessable();
        $this->assertDatabaseHas('categories',['id'=>$category->id,'status'=>'active','name'=>'root']);
    }
    public function test_guest_and_member_cannot_mutate(): void
    {
        $root=Category::create(['name'=>'root']);
        $this->patchJson($this->path($root->id),['status'=>'inactive'])->assertUnauthorized();
        $this->actingAs(User::factory()->create(),'web')->patchJson($this->path($root->id),['status'=>'inactive'])->assertUnauthorized();
        $this->assertDatabaseHas('categories',['id'=>$root->id,'status'=>'active']);
    }
    public function test_disabled_admin_does_not_clear_member_session(): void
    {
        $root=Category::create(['name'=>'root']);
        $user=User::factory()->create();
        $this->actingAs($user,'web')->actingAs(Admin::factory()->disabled()->create(),'admin')
            ->withSession(['member_marker'=>'preserve'])
            ->patchJson($this->path($root->id),['status'=>'inactive'])->assertForbidden()->assertJsonPath('code','ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user,'web');
        $this->assertSame('preserve',session('member_marker'));
    }
    public function test_status_only_changes_target_and_restore_is_not_cascade(): void
    {
        $root=Category::create(['name'=>'root']);
        $child=Category::create(['name'=>'child','parent_id'=>$root->id]);
        $inactive=Category::create(['name'=>'off','parent_id'=>$root->id,'status'=>'inactive']);
        $products=[];
        foreach (['active','inactive','disabled'] as $status) $products[]=Product::factory()->create(['category_id'=>$child->id,'status'=>$status,'stock'=>10]);
        $variant=ProductVariant::create(['product_id'=>$products[0]->id,'option_name'=>'色','option_value'=>'黑','stock'=>4,'status'=>'inactive']);
        $this->actingAs(Admin::factory()->create(),'admin');
        foreach ([$root,$child] as $target) {
            foreach (['inactive','inactive','active'] as $status) $this->patchJson($this->path($target->id),['status'=>$status])->assertOk()->assertJsonPath('data.status',$status)->assertJsonStructure(['data','message'])->assertJsonMissingPath('success');
        }
        foreach ($products as $i=>$product) {
            $this->assertSame(['active','inactive','disabled'][$i],$product->fresh()->status);
            $this->assertEquals(10,$product->fresh()->stock);
        }
        $this->assertSame('inactive',$inactive->fresh()->status);
        $this->assertSame('inactive',$variant->fresh()->status);
        $this->assertEquals(4,$variant->fresh()->stock);
    }
    public function test_numeric_missing_and_malformed_hierarchy(): void
    {
        $this->actingAs(Admin::factory()->create(),'admin');
        $this->patchJson($this->path('abc'),['status'=>'active'])->assertNotFound();
        $this->patchJson($this->path(999),['status'=>'active'])->assertNotFound();
        $root=Category::create(['name'=>'root']);
        $child=Category::create(['name'=>'child','parent_id'=>$root->id]);
        $third=Category::create(['name'=>'third','parent_id'=>$child->id]);
        $this->patchJson($this->path($third->id),['status'=>'inactive'])->assertUnprocessable();
        $this->assertSame('active',$third->fresh()->status);
    }
}
