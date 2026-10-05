<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_guest_and_member_only_are_unauthorized_even_without_accept_header(): void
    {
        $this->get('/api/admin/categories')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->getJson('/api/admin/categories')->assertUnauthorized();
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_disabled_admin_is_revoked_without_revoking_member(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user, 'web');
        $this->actingAs(Admin::factory()->disabled()->create(), 'admin');
        $this->getJson('/api/admin/categories')->assertForbidden()
            ->assertJsonPath('code', 'ADMIN_ACCOUNT_DISABLED')->assertJsonMissingPath('success');
        $this->assertGuest('admin');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_active_admin_gets_empty_data_without_pagination(): void
    {
        $this->login();
        $this->getJson('/api/admin/categories')->assertOk()->assertExactJson(['data' => []]);
    }

    public function test_admin_tree_includes_both_statuses_and_exact_management_fields_and_counts(): void
    {
        $this->login();
        $root = $this->category(['status' => 'inactive']);
        $child = $this->category(['parent_id' => $root->id]);
        $inactive = $this->category(['parent_id' => $root->id, 'status' => 'inactive']);
        $emptyRoot = $this->category();
        foreach (['active', 'inactive', 'disabled'] as $status) {
            Product::factory()->create(['category_id' => $child->id, 'status' => $status]);
        }
        $response = $this->getJson('/api/admin/categories')->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('data.0.status', 'inactive')
            ->assertJsonPath('data.0.children_count', 2)
            ->assertJsonPath('data.0.children.0.product_count', 3)
            ->assertJsonPath('data.0.children.1.status', 'inactive')
            ->assertJsonPath('data.0.children.1.product_count', 0)
            ->assertJsonPath('data.1.id', $emptyRoot->id)
            ->assertJsonPath('data.1.children_count', 0)->assertJsonPath('data.1.children', []);
        $this->assertEqualsCanonicalizing([
            'id', 'name', 'status', 'sort_order', 'children_count', 'children', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0')));
        $this->assertEqualsCanonicalizing([
            'id', 'parent_id', 'name', 'status', 'sort_order', 'product_count', 'created_at', 'updated_at',
        ], array_keys($response->json('data.0.children.0')));
        $response->assertJsonPath('data.0.children.0.parent_id', $root->id)
            ->assertJsonPath('data.0.created_at', $root->created_at->toISOString())
            ->assertJsonPath('data.0.updated_at', $root->updated_at->toISOString())
            ->assertJsonPath('data.0.children.0.created_at', $child->created_at->toISOString())
            ->assertJsonPath('data.0.children.1.updated_at', $inactive->updated_at->toISOString());
        foreach (['success', 'links', 'meta', 'data.0.products', 'data.0.permissions', 'data.0.can_delete',
            'data.0.can_edit', 'data.0.effective_status', 'data.0.sellability',
            'data.0.children.0.products', 'data.0.children.0.children'] as $path) {
            $response->assertJsonMissingPath($path);
        }
    }

    public function test_roots_and_direct_children_have_stable_sort_order_then_id(): void
    {
        $this->login();
        $last = $this->category(['sort_order' => 9]);
        $first = $this->category(['sort_order' => 1]);
        $second = $this->category(['sort_order' => 1]);
        $childLast = $this->category(['parent_id' => $first->id, 'sort_order' => 3]);
        $childFirst = $this->category(['parent_id' => $first->id, 'sort_order' => 0]);
        $childSecond = $this->category(['parent_id' => $first->id, 'sort_order' => 0]);
        $otherChild = $this->category(['parent_id' => $second->id]);
        $data = $this->getJson('/api/admin/categories')->assertOk()->json('data');
        $this->assertSame([$first->id, $second->id, $last->id], array_column($data, 'id'));
        $this->assertSame([$childFirst->id, $childSecond->id, $childLast->id], array_column($data[0]['children'], 'id'));
        $this->assertSame([$otherChild->id], array_column($data[1]['children'], 'id'));
    }

    public function test_third_level_fixture_is_not_returned_or_rewritten(): void
    {
        $this->login();
        $root = $this->category();
        $child = $this->category(['parent_id' => $root->id]);
        // 僅隔離 SQLite 製造既有 schema 可容納的異常層級，不修改 migration。
        $grandchild = $this->category(['parent_id' => $child->id]);
        Product::factory()->create(['category_id' => $grandchild->id]);
        $this->getJson('/api/admin/categories')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonCount(1, 'data.0.children')->assertJsonPath('data.0.children_count', 1)
            ->assertJsonPath('data.0.children.0.id', $child->id)
            ->assertJsonPath('data.0.children.0.product_count', 0)
            ->assertJsonMissingPath('data.0.children.0.children');
        $this->assertDatabaseHas('categories', ['id' => $grandchild->id, 'parent_id' => $child->id]);
        $this->assertDatabaseCount('categories', 3);
    }

    public function test_full_tree_does_not_accept_search_or_pagination_as_a_query_feature(): void
    {
        $this->login();
        for ($i = 0; $i < 12; $i++) {
            $this->category(['name' => 'root '.$i]);
        }
        $this->getJson('/api/admin/categories?per_page=1&page=2&search=nonexistent')
            ->assertOk()->assertJsonCount(12, 'data')->assertJsonMissingPath('meta')->assertJsonMissingPath('links');
    }

    public function test_public_category_tree_remains_active_only_and_lightweight(): void
    {
        $root = $this->category(['sort_order' => 1]);
        $rootTie = $this->category(['sort_order' => 1]);
        $inactiveRoot = $this->category(['status' => 'inactive']);
        $this->category(['parent_id' => $inactiveRoot->id]);
        $childLast = $this->category(['parent_id' => $root->id, 'sort_order' => 3]);
        $childFirst = $this->category(['parent_id' => $root->id, 'sort_order' => 0]);
        $childTie = $this->category(['parent_id' => $root->id, 'sort_order' => 0]);
        $this->category(['parent_id' => $root->id, 'status' => 'inactive']);
        $data = $this->getJson('/api/categories')->assertOk()->json('data');
        $this->assertSame([$root->id, $rootTie->id], array_column($data, 'id'));
        $this->assertSame([$childFirst->id, $childTie->id, $childLast->id], array_column($data[0]['children'], 'id'));
        $this->assertEqualsCanonicalizing(['id', 'name', 'children'], array_keys($data[0]));
        $this->assertEqualsCanonicalizing(['id', 'name'], array_keys($data[0]['children'][0]));
    }

    public function test_admin_read_bypasses_member_c06_with_actual_dual_session(): void
    {
        $user = User::factory()->create(['password' => 'member-test-secret']);
        $admin = Admin::factory()->create(['password' => 'admin-test-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'member-test-secret'])->assertOk();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'admin-test-secret'])->assertOk();
        $this->nextRequest();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        $this->getJson('/api/admin/categories')->assertOk()->assertExactJson(['data' => []]);
        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_query_count_does_not_grow_with_roots_children_or_products(): void
    {
        $this->login();
        $root = $this->category();
        $child = $this->category(['parent_id' => $root->id]);
        Product::factory()->create(['category_id' => $child->id]);
        $small = $this->categoryQueryCount();
        for ($i = 0; $i < 10; $i++) {
            $parent = $this->category();
            for ($j = 0; $j < 3; $j++) {
                $sub = $this->category(['parent_id' => $parent->id]);
                Product::factory()->count(2)->create(['category_id' => $sub->id]);
            }
        }
        $large = $this->categoryQueryCount();
        $this->assertSame(2, $small);
        $this->assertSame($small, $large, '管理樹關聯查詢數不應隨分類筆數線性增加');
    }

    private function categoryQueryCount(): int
    {
        $connection = DB::connection();
        $connection->enableQueryLog();
        $connection->flushQueryLog();
        try {
            $this->getJson('/api/admin/categories')->assertOk();
            $queries = $connection->getQueryLog();
            return count(array_filter($queries, fn ($entry) =>
                str_contains(strtolower($entry['query']), 'from "categories"')
                || str_contains(strtolower($entry['query']), 'from "products"')));
        } finally {
            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }
    }

    private function login(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');
    }

    private function category(array $attributes = []): Category
    {
        return Category::create(array_merge(['name' => '分類', 'status' => 'active', 'sort_order' => 0], $attributes));
    }

    private function nextRequest(): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        app('session.store')->flush();
        Auth::forgetGuards();
        Auth::shouldUse('web');
    }
}
