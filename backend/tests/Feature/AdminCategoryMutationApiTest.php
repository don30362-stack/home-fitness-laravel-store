<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AdminCategoryMutationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public static function endpoints(): array
    {
        return ['create' => ['post'], 'update' => ['patch']];
    }

    #[DataProvider('endpoints')]
    public function test_guest_and_member_only_cannot_mutate(string $method): void
    {
        $category = $this->category();
        $url = '/api/admin/categories'.($method === 'patch' ? '/'.$category->id : '');
        $this->{$method.'Json'}($url, ['name' => 'new'])->assertUnauthorized();
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->{$method.'Json'}($url, ['name' => 'new'])->assertUnauthorized();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => $category->name]);
    }

    #[DataProvider('endpoints')]
    public function test_disabled_admin_mutation_preserves_member(string $method): void
    {
        $category = $this->category();
        $url = '/api/admin/categories'.($method === 'patch' ? '/'.$category->id : '');
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->{$method.'Json'}($url, ['name' => 'new'])->assertForbidden()->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED');
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertGuest('admin');
        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => $category->name]);
    }

    public static function statuses(): array
    {
        return ['default' => [[] , 'active'], 'active' => [['status' => 'active'], 'active'], 'inactive' => [['status' => 'inactive'], 'inactive']];
    }

    public function test_active_admin_mutations_do_not_trigger_disabled_member_c06(): void
    {
        $user = User::factory()->create(['password' => 'member-test-secret']);
        $admin = Admin::factory()->create(['password' => 'admin-test-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'member-test-secret'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'admin-test-secret'])->assertOk();
        $this->nextRequest();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        $id = $this->postJson('/api/admin/categories', ['name' => 'root'])->assertCreated()->json('data.id');
        $this->nextRequest();
        $this->patchJson('/api/admin/categories/'.$id, ['name' => 'renamed'])->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAuthenticated('admin');
    }

    #[DataProvider('statuses')]
    public function test_create_root_defaults_trim_and_initial_status(array $extra, string $status): void
    {
        $this->login();
        $response = $this->postJson('/api/admin/categories', ['name' => '  root  ', 'parent_id' => null] + $extra)
            ->assertCreated()->assertJsonPath('data.name', 'root')->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.sort_order', 0)->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.children_count', 0)->assertJsonPath('data.children', [])
            ->assertJsonPath('data.product_count', 0)->assertJsonStructure(['data', 'message'])->assertJsonMissingPath('success');
        $this->assertDatabaseHas('categories', ['id' => $response->json('data.id'), 'name' => 'root', 'status' => $status]);
    }

    #[DataProvider('statuses')]
    public function test_create_child_under_active_root_and_initial_status(array $extra, string $status): void
    {
        $this->login();
        $root = $this->category();
        $this->postJson('/api/admin/categories', ['name' => 'child', 'parent_id' => $root->id, 'sort_order' => 5] + $extra)
            ->assertCreated()->assertJsonPath('data.parent_id', $root->id)->assertJsonPath('data.sort_order', 5)
            ->assertJsonPath('data.status', $status)->assertJsonPath('data.children', []);
    }

    public static function invalidCreate(): array
    {
        return [
            'missing name' => [[], 'name'], 'blank' => [['name' => '   '], 'name'],
            'null name' => [['name' => null], 'name'], 'array name' => [['name' => []], 'name'],
            'long name' => [['name' => str_repeat('x', 101)], 'name'],
            'status disabled' => [['name' => 'ok', 'status' => 'disabled'], 'status'],
            'status null' => [['name' => 'ok', 'status' => null], 'status'],
            'negative sort' => [['name' => 'ok', 'sort_order' => -1], 'sort_order'],
            'decimal sort' => [['name' => 'ok', 'sort_order' => 1.5], 'sort_order'],
            'null sort' => [['name' => 'ok', 'sort_order' => null], 'sort_order'],
            'overflow sort' => [['name' => 'ok', 'sort_order' => 4294967296], 'sort_order'],
            'array parent' => [['name' => 'ok', 'parent_id' => []], 'parent_id'],
            'unknown field' => [['name' => 'ok', 'products' => []], 'products'],
        ];
    }

    #[DataProvider('invalidCreate')]
    public function test_create_validation(array $data, string $field): void
    {
        $this->login();
        $this->postJson('/api/admin/categories', $data)->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('categories', 0);
    }

    public function test_create_parent_and_sibling_rules(): void
    {
        $this->login();
        $root = $this->category();
        $other = $this->category(['name' => 'other']);
        $inactive = $this->category(['name' => 'inactive', 'status' => 'inactive']);
        $child = $this->category(['name' => 'child', 'parent_id' => $root->id]);
        foreach ([$inactive->id, $child->id, 99999] as $id) {
            $this->postJson('/api/admin/categories', ['name' => 'new', 'parent_id' => $id])
                ->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        $this->postJson('/api/admin/categories', ['name' => '  '.$root->name.'  '])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/categories', ['name' => 'child', 'parent_id' => $root->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/admin/categories', ['name' => 'child', 'parent_id' => $other->id])->assertCreated();
        // Root 名稱與 child 名稱的 scope 不同。
        $this->postJson('/api/admin/categories', ['name' => 'child'])->assertCreated();
    }

    public function test_update_root_partial_omitted_null_and_management_response(): void
    {
        $this->login();
        $root = $this->category(['status' => 'inactive', 'sort_order' => 7]);
        $child = $this->category(['parent_id' => $root->id]);
        Product::factory()->create(['category_id' => $child->id]);
        $this->patchJson('/api/admin/categories/'.$root->id, ['name' => '  renamed  '])->assertOk()
            ->assertJsonPath('data.name', 'renamed')->assertJsonPath('data.sort_order', 7)
            ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.parent_id', null)
            ->assertJsonPath('data.children_count', 1)->assertJsonPath('data.children.0.product_count', 1)
            ->assertJsonStructure(['message'])->assertJsonMissingPath('success');
        $this->patchJson('/api/admin/categories/'.$root->id, ['parent_id' => null, 'sort_order' => 0])->assertOk()->assertJsonPath('data.name', 'renamed');
        $this->patchJson('/api/admin/categories/'.$root->id, [])->assertOk()->assertJsonPath('data.sort_order', 0);
        $other = $this->category(['name' => 'taken']);
        $this->patchJson('/api/admin/categories/'.$root->id, ['name' => $other->name])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_update_child_retains_inactive_parent_for_omitted_and_explicit_same_id(): void
    {
        $this->login();
        $root = $this->category(['status' => 'inactive']);
        $child = $this->category(['parent_id' => $root->id, 'status' => 'inactive', 'sort_order' => 9]);
        $this->patchJson('/api/admin/categories/'.$child->id, ['name' => 'renamed'])->assertOk()
            ->assertJsonPath('data.parent_id', $root->id)->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.sort_order', 9);
        $this->patchJson('/api/admin/categories/'.$child->id, ['parent_id' => $root->id, 'sort_order' => 1])->assertOk()
            ->assertJsonPath('data.name', 'renamed')->assertJsonPath('data.sort_order', 1);
    }

    public function test_reparent_validates_target_scope_and_preserves_identity_products_and_status(): void
    {
        $this->login();
        $root = $this->category();
        $other = $this->category(['name' => 'other']);
        $child = $this->category(['name' => 'same', 'parent_id' => $root->id, 'status' => 'inactive']);
        $targetChild = $this->category(['name' => 'same', 'parent_id' => $other->id]);
        $product = Product::factory()->create(['category_id' => $child->id, 'status' => 'disabled']);
        $this->patchJson('/api/admin/categories/'.$child->id, ['parent_id' => $other->id])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $root->id]);
        $this->patchJson('/api/admin/categories/'.$child->id, ['name' => 'different', 'parent_id' => $other->id])->assertOk()
            ->assertJsonPath('data.id', $child->id)->assertJsonPath('data.parent_id', $other->id)
            ->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.product_count', 1)->assertJsonPath('data.children', []);
        $this->assertDatabaseHas('products', ['id' => $product->id, 'category_id' => $child->id, 'status' => 'disabled']);
        $this->patchJson('/api/admin/categories/'.$targetChild->id, ['name' => 'same'])->assertOk();
    }

    public function test_hierarchy_invariants_and_invalid_reparents_are_rejected(): void
    {
        $this->login();
        $root = $this->category();
        $inactive = $this->category(['status' => 'inactive']);
        $child = $this->category(['parent_id' => $root->id]);
        $otherChild = $this->category(['name' => 'other child', 'parent_id' => $root->id]);
        foreach ([$root->id, $child->id] as $id) {
            $this->patchJson('/api/admin/categories/'.$root->id, ['parent_id' => $id])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        foreach ([null, $child->id, $otherChild->id, $inactive->id, 99999] as $parent) {
            $this->patchJson('/api/admin/categories/'.$child->id, ['parent_id' => $parent])->assertUnprocessable()->assertJsonValidationErrors('parent_id');
        }
        // A root 指向自己的 child／child 指向兄弟的 cycle 嘗試均被角色或 root-only 規則擋住。
        $this->assertDatabaseHas('categories', ['id' => $root->id, 'parent_id' => null]);
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'parent_id' => $root->id]);
        $this->patchJson('/api/admin/categories/'.$child->id, ['parent_id' => $root->id])->assertOk();
    }

    public function test_patch_rejects_status_even_null_unknown_fields_and_invalid_basic_values(): void
    {
        $this->login();
        $category = $this->category();
        foreach ([['status' => 'active'], ['status' => 'inactive'], ['status' => null]] as $payload) {
            $this->patchJson('/api/admin/categories/'.$category->id, $payload)->assertUnprocessable()->assertJsonValidationErrors('status');
        }
        foreach ([['name' => ' '], ['name' => null], ['sort_order' => -1], ['sort_order' => []], ['parent_id' => []], ['id' => 99]] as $payload) {
            $this->patchJson('/api/admin/categories/'.$category->id, $payload)->assertUnprocessable()->assertJsonValidationErrors(array_key_first($payload));
        }
        $this->patchJson('/api/admin/categories/99999', ['name' => 'ok'])->assertNotFound();
        $this->patchJson('/api/admin/categories/not-numeric', ['name' => 'ok'])->assertNotFound();
    }

    public function test_create_update_ordering_and_public_lightweight_contract(): void
    {
        $this->login();
        $a = $this->postJson('/api/admin/categories', ['name' => 'a', 'sort_order' => 3])->assertCreated()->json('data.id');
        $b = $this->postJson('/api/admin/categories', ['name' => 'b', 'sort_order' => 1])->assertCreated()->json('data.id');
        $x = $this->postJson('/api/admin/categories', ['name' => 'x', 'parent_id' => $a, 'sort_order' => 4])->assertCreated()->json('data.id');
        $y = $this->postJson('/api/admin/categories', ['name' => 'y', 'parent_id' => $a])->assertCreated()->json('data.id');
        $this->patchJson('/api/admin/categories/'.$a, ['sort_order' => 1])->assertOk();
        $this->patchJson('/api/admin/categories/'.$x, ['sort_order' => 0])->assertOk();
        $data = $this->getJson('/api/admin/categories')->assertOk()->json('data');
        $this->assertSame([$a, $b], array_column($data, 'id'));
        $this->assertSame([$x, $y], array_column($data[0]['children'], 'id'));
        $public = $this->getJson('/api/categories')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['id', 'name', 'children'], array_keys($public[0]));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($public[0]['children'][0]));
    }

    public function test_saved_row_rolls_back_if_updated_event_fails(): void
    {
        $this->login();
        $root = $this->category();
        $other = $this->category();
        $child = $this->category(['name' => 'before', 'parent_id' => $root->id]);
        $event = 'eloquent.updated: '.Category::class;
        $observed = false;
        Event::listen($event, function (Category $saved) use (&$observed) {
            $observed = $saved->name === 'after' && $saved->getConnection()->table('categories')->where('id', $saved->id)->value('name') === 'after';
            throw new RuntimeException('isolated late failure');
        });
        $this->withoutExceptionHandling();
        try {
            $this->patchJson('/api/admin/categories/'.$child->id, ['name' => 'after', 'parent_id' => $other->id, 'sort_order' => 5]);
            $this->fail('Expected late event failure');
        } catch (RuntimeException $exception) {
            $this->assertSame('isolated late failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }
        $this->assertTrue($observed, '失敗發生在 UPDATE SQL 已執行之後');
        $this->assertDatabaseHas('categories', ['id' => $child->id, 'name' => 'before', 'parent_id' => $root->id, 'sort_order' => 0]);
    }

    public function test_no_status_or_delete_routes_and_no_c07_implementation(): void
    {
        $this->login();
        $root = $this->category();
        $child = $this->category(['parent_id' => $root->id, 'status' => 'inactive']);
        $product = Product::factory()->create(['category_id' => $child->id, 'status' => 'active']);
        $this->patchJson('/api/admin/categories/'.$child->id.'/status', ['status' => 'active'])->assertNotFound();
        $this->deleteJson('/api/admin/categories/'.$child->id)->assertStatus(405);
        // 保留本步前公開 Product 僅檢查 product.status 的現況；C07 留 Step 3。
        $this->getJson('/api/products/'.$product->id)->assertOk();
    }

    private function login(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    private function nextRequest(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        app('session.store')->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }

    private function category(array $attributes = []): Category
    {
        return Category::create(array_merge(['name' => 'fixture', 'status' => 'active', 'sort_order' => 0], $attributes));
    }
}
