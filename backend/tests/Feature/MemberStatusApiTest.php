<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MemberStatusApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public static function nonActiveStatuses(): array
    {
        return [
            'inactive' => ['inactive'],
            'disabled' => ['disabled'],
        ];
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_members_cannot_access_or_mutate_member_data(string $status): void
    {
        $user = User::factory()->create(['status' => $status, 'password' => 'OldPassword123']);
        $requests = $this->memberRequests($user);
        $before = $this->businessSnapshot();

        foreach ($requests as [$method, $uri, $payload]) {
            // 每個端點各自模擬一個仍持有登入身分的停用會員請求。
            Auth::forgetGuards();
            $this->actingAs($user, 'web');
            $this->json($method, $uri, $payload)
                ->assertForbidden()
                ->assertExactJson([
                    'code' => 'ACCOUNT_DISABLED',
                    'message' => '此會員帳號已停用，請聯絡管理員',
                ]);

            $this->assertSame($before, $this->businessSnapshot(), "$method $uri must not mutate business data");
        }
    }

    public function test_guests_remain_unauthenticated_on_all_member_endpoints(): void
    {
        $user = User::factory()->create();
        $requests = $this->memberRequests($user);
        $requests[] = ['POST', '/api/logout', []];
        $before = $this->businessSnapshot();

        foreach ($requests as [$method, $uri, $payload]) {
            $this->json($method, $uri, $payload)
                ->assertUnauthorized()
                ->assertJsonMissingPath('code');
        }

        $this->assertSame($before, $this->businessSnapshot());
    }

    public function test_active_member_can_still_read_and_update_profile(): void
    {
        $user = User::factory()->create(['status' => 'active']);

        $this->actingAs($user, 'web')->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $this->patchJson('/api/me', [
            'name' => '正常會員',
            'email' => $user->email,
            'phone' => '0912345678',
        ])->assertOk()->assertJsonPath('data.name', '正常會員');
    }

    public function test_non_active_member_can_still_access_public_products(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);
        $this->memberRequests($user);

        $this->actingAs($user, 'web')->getJson('/api/products')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_non_active_member_can_explicitly_logout(): void
    {
        $user = User::factory()->create(['status' => 'disabled']);

        $this->actingAs($user, 'web')->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('message', '會員登出成功');

        $this->assertGuest('web');
    }

    public function test_disabling_a_logged_in_member_revokes_the_current_session(): void
    {
        $user = User::factory()->create(['status' => 'active', 'password' => 'OldPassword123']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword123'])->assertOk();
        /** @var SessionGuard $guard */
        $guard = Auth::guard('web');
        $sessionKey = $guard->getName();
        $session = app('session.store');
        $oldSessionId = $session->getId();
        $oldToken = $session->token();
        $this->withSession(['private_marker' => 'remove']);
        $this->withCookie(config('session.cookie'), $oldSessionId)->withCredentials();

        Auth::forgetGuards();
        $this->getJson('/api/me')->assertOk();

        // 模擬另一次操作停用帳號，讓下一個請求從 DB 重新取得會員。
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        Auth::forgetGuards();

        $this->getJson('/api/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DISABLED')
            ->assertSessionMissing($sessionKey)
            ->assertSessionMissing('private_marker');

        $this->assertGuest('web');
        $this->assertNotSame($oldSessionId, $session->getId());
        $this->assertNotSame($oldToken, $session->token());
        $this->assertSame('', $session->getHandler()->read($oldSessionId));

        // 重播舊 cookie，停用中或恢復 active 後都不能復活舊登入。
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
        Auth::forgetGuards();
        $this->postJson('/api/logout')->assertUnauthorized();
        DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();

        // 透過正常 CSRF 取得流程重新建立登入；不假設上一個回應已更新瀏覽器 cookie。
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withCookie(config('session.cookie'), $session->getId());
        Auth::forgetGuards();
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword123'])->assertOk();
        $this->withCookie(config('session.cookie'), $session->getId());
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_login_with_correct_password_uses_disabled_contract(string $status): void
    {
        $user = User::factory()->create(['status' => $status, 'password' => 'OldPassword123']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword123'])
            ->assertForbidden()
            ->assertExactJson(['code' => 'ACCOUNT_DISABLED', 'message' => '此會員帳號已停用，請聯絡管理員']);

        $this->assertGuest('web');
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_login_with_wrong_password_remains_unauthorized(string $status): void
    {
        $user = User::factory()->create(['status' => $status, 'password' => 'OldPassword123']);

        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertUnauthorized()
            ->assertExactJson(['message' => '電子郵件或密碼錯誤']);

        $this->assertGuest('web');
    }

    public function test_unknown_email_remains_unauthorized(): void
    {
        $this->postJson('/api/login', ['email' => 'unknown@example.com', 'password' => 'OldPassword123'])
            ->assertUnauthorized()
            ->assertJsonMissingPath('code');
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_logged_in_member_can_logout_before_active_middleware_rejects_them(string $status): void
    {
        $user = User::factory()->create(['status' => 'active', 'password' => 'OldPassword123']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword123'])->assertOk();
        $session = app('session.store');
        $oldSessionId = $session->getId();
        $oldToken = $session->token();
        $this->withCookie(config('session.cookie'), $oldSessionId)->withCredentials();
        DB::table('users')->where('id', $user->id)->update(['status' => $status]);
        Auth::forgetGuards();

        $this->postJson('/api/logout')->assertOk()->assertJsonPath('message', '會員登出成功');

        $this->assertGuest('web');
        $this->assertNotSame($oldSessionId, $session->getId());
        $this->assertNotSame($oldToken, $session->token());
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertUnauthorized();
    }

    public function test_active_member_can_login_and_logout_normally(): void
    {
        $user = User::factory()->create(['password' => 'OldPassword123']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'OldPassword123'])->assertOk();
        $this->withCookie(config('session.cookie'), app('session.store')->getId())->withCredentials();
        Auth::forgetGuards();

        $this->postJson('/api/logout')->assertOk();
        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->postJson('/api/logout')->assertUnauthorized();
    }

    public function test_login_after_revocation_requires_a_fresh_valid_csrf_state(): void
    {
        // Feature Tests 預設跳過 CSRF；本案例明確開啟框架的 token 驗證。
        $csrf = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $csrf);
        config(['sanctum.middleware.validate_csrf_token' => PreventRequestForgery::class]);

        $user = User::factory()->create(['password' => 'OldPassword123']);
        $credentials = ['email' => $user->email, 'password' => 'OldPassword123'];
        $cookieResponse = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withCredentials()->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->withHeader('X-XSRF-TOKEN', $cookieResponse->getCookie('XSRF-TOKEN', false)->getValue());

        $loginResponse = $this->postJson('/api/login', $credentials)->assertOk();
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $oldXsrfCookie = $loginResponse->getCookie('XSRF-TOKEN', false)->getValue();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        Auth::forgetGuards();
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');

        // 刻意不使用拒絕回應的新 XSRF cookie，驗證舊 token 不能直接重登。
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->withHeader('X-XSRF-TOKEN', $oldXsrfCookie);
        DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
        Auth::forgetGuards();
        $this->postJson('/api/login', $credentials)->assertStatus(419);
        $this->assertGuest('web');

        // 明確重新取得 CSRF cookie 後，用該回應的 token 發出新的登入請求。
        $cookieResponse = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->withHeader('X-XSRF-TOKEN', $cookieResponse->getCookie('XSRF-TOKEN', false)->getValue());
        Auth::forgetGuards();
        $this->postJson('/api/login', $credentials)->assertOk();
        $this->assertAuthenticatedAs($user, 'web');
    }

    /** @return list<array{string, string, array}> */
    private function memberRequests(User $user): array
    {
        $city = City::query()->create(['name' => '臺中市']);
        $district = $city->districts()->create(['name' => '西屯區', 'postal_code' => '407']);
        $addressPayload = [
            'district_id' => $district->id,
            'label' => '住家',
            'recipient_name' => '王小明',
            'recipient_phone' => '0912345678',
            'address' => '臺灣大道三段100號',
        ];
        $address = $user->userAddresses()->create($addressPayload + ['is_default' => true]);
        $category = Category::query()->create(['name' => '測試分類', 'status' => 'active', 'sort_order' => 0]);
        $product = Product::factory()->create(['category_id' => $category->id, 'stock' => 10]);
        $variantProduct = Product::factory()->create(['category_id' => $category->id, 'stock' => null]);
        $variant = $variantProduct->variants()->create([
            'option_name' => '重量', 'option_value' => '10kg', 'stock' => 10, 'status' => 'active',
        ]);
        $cart = $user->cart()->create();
        $itemPayload = ['product_id' => $product->id, 'product_variant_id' => null, 'quantity' => 2];
        $item = $cart->items()->create($itemPayload);
        $cart->items()->create(['product_id' => $variantProduct->id, 'product_variant_id' => $variant->id, 'quantity' => 1]);

        $requests = [
            ['GET', '/api/me', []],
            ['PATCH', '/api/me', ['name' => '修改姓名', 'email' => $user->email, 'phone' => '0987654321']],
            ['PATCH', '/api/me/password', [
                'current_password' => 'OldPassword123', 'password' => 'NewPassword123', 'password_confirmation' => 'NewPassword123',
            ]],
            ['GET', '/api/addresses', []],
            ['POST', '/api/addresses', $addressPayload],
            ['PATCH', "/api/addresses/{$address->id}", ['label' => '修改地址']],
            ['DELETE', "/api/addresses/{$address->id}", []],
            ['PATCH', "/api/addresses/{$address->id}/default", []],
            ['GET', '/api/cart', []],
            ['POST', '/api/cart/items', $itemPayload],
            ['POST', '/api/cart/merge', ['items' => [$itemPayload]]],
            ['PATCH', "/api/cart/items/{$item->id}", ['quantity' => 3]],
            ['DELETE', "/api/cart/items/{$item->id}", []],
            ['DELETE', '/api/cart', []],
            ['POST', '/api/checkout', [
                'purchaser' => ['name' => '王小明', 'phone' => '0912345678', 'email' => $user->email],
                'recipient' => [
                    'name' => '王小華', 'phone' => '0987654321', 'district_id' => $district->id, 'address' => '臺灣大道三段100號',
                ],
                'shipping_method' => 'home_delivery', 'payment_method' => 'cod',
            ]],
        ];

        // 具驗證規則的異動端點也用非法內容測試：應先回 403，而非 422。
        foreach ([
            ['PATCH', '/api/me'], ['PATCH', '/api/me/password'],
            ['POST', '/api/addresses'], ['PATCH', "/api/addresses/{$address->id}"],
            ['POST', '/api/cart/items'], ['POST', '/api/cart/merge'],
            ['PATCH', "/api/cart/items/{$item->id}"], ['POST', '/api/checkout'],
        ] as [$method, $uri]) {
            $requests[] = [$method, $uri, ['quantity' => 0, 'recipient_phone' => 'invalid']];
        }

        return $requests;
    }

    private function businessSnapshot(): array
    {
        $snapshot = [];

        foreach (['users', 'user_addresses', 'carts', 'cart_items', 'products', 'product_variants', 'orders', 'order_items'] as $table) {
            $rows = DB::table($table)->orderBy('id')->get();

            if ($table === 'users') {
                // Laravel logout 會更新 remember_token；業務欄位仍須保持原樣。
                $rows->each(function ($row): void {
                    unset($row->remember_token);
                });
            }

            $snapshot[$table] = $rows->toJson();
        }

        return $snapshot;
    }
}
