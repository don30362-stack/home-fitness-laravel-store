<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminAuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['sanctum.stateful' => ['localhost']]);
        $this->withHeader('Origin', 'http://localhost');
    }

    public function test_login_rotates_session_and_me_restores_exact_identity_contract(): void
    {
        $admin = $this->admin();
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $oldId = app('session.store')->getId();
        $this->nextRequest();

        $this->postJson('/api/admin/login', $this->credentials($admin))
            ->assertOk()->assertExactJson([
                'data' => $this->identity($admin),
                'message' => '管理員登入成功',
            ]);

        $this->assertNotSame($oldId, app('session.store')->getId());
        $this->assertSame('', app('session.store')->getHandler()->read($oldId));
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertOk()->assertExactJson(['data' => $this->identity($admin)]);
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertGuest('web');
    }

    public function test_unknown_email_and_wrong_password_have_identical_unauthorized_response(): void
    {
        $admin = $this->admin();
        foreach ([['email' => 'unknown@example.test', 'password' => 'wrong'], ['email' => $admin->email, 'password' => 'wrong']] as $payload) {
            $this->postJson('/api/admin/login', $payload)->assertUnauthorized()
                ->assertExactJson(['message' => '管理員電子郵件或密碼錯誤']);
            $this->assertGuest('admin');
        }
    }

    public static function nonActiveStatuses(): array
    {
        return ['disabled' => ['disabled'], 'inactive' => ['inactive'], 'unknown' => ['unknown']];
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_non_active_login_only_discloses_disabled_after_correct_password(string $status): void
    {
        $admin = $this->admin(['status' => $status]);
        $this->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'wrong'])
            ->assertUnauthorized()->assertJsonMissingPath('code');
        $this->postJson('/api/admin/login', $this->credentials($admin))
            ->assertForbidden()->assertExactJson($this->disabledResponse());
        $this->assertGuest('admin');
    }

    public static function invalidLoginPayloads(): array
    {
        return [
            'empty' => [[], ['email', 'password']],
            'bad email' => [['email' => 'invalid', 'password' => 'x'], ['email']],
            'email array' => [['email' => ['invalid'], 'password' => 'x'], ['email']],
            'long email' => [['email' => str_repeat('a', 245).'@example.test', 'password' => 'x'], ['email']],
            'password array' => [['email' => 'test@example.test', 'password' => ['x']], ['password']],
            'empty password' => [['email' => 'test@example.test', 'password' => ''], ['password']],
        ];
    }

    #[DataProvider('invalidLoginPayloads')]
    public function test_login_validation_returns_laravel_422(array $payload, array $fields): void
    {
        $this->postJson('/api/admin/login', $payload)->assertUnprocessable()
            ->assertJsonValidationErrors($fields)->assertJsonMissingPath('success');
        $this->assertGuest('admin');
    }

    public function test_guest_get_and_post_are_json_401_even_without_accept_header(): void
    {
        $this->get('/api/admin/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->post('/api/admin/logout')->assertUnauthorized()->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'Unauthenticated.']);
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->postJson('/api/admin/logout')->assertUnauthorized();
    }

    public function test_member_only_is_not_admin_even_with_matching_id_and_credentials(): void
    {
        $admin = $this->admin(['id' => 41]);
        $user = User::factory()->create(['id' => 41, 'password' => 'member-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'member-secret'])->assertOk();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->nextRequest();
        $this->postJson('/api/admin/logout')->assertUnauthorized();
        $this->nextRequest();
        $this->postJson('/api/admin/login', ['email' => $user->email, 'password' => 'member-secret'])->assertUnauthorized();
        $this->nextRequest();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertNotSame($admin->email, $user->email);
    }

    public function test_admin_only_session_is_rejected_by_member_me(): void
    {
        $admin = $this->admin(['id' => 41]);
        User::factory()->create(['id' => 41]);
        $this->login($admin);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertOk();
    }

    public function test_http_admin_logout_preserves_member_data_and_csrf_destroys_old_id(): void
    {
        $user = $this->loginBoth();
        $cart = $user->cart()->create();
        $session = app('session.store');
        $session->put('member_marker', ['cart' => 'preserve']);
        $session->save();
        $oldId = $session->getId();
        $oldToken = $session->token();
        $this->nextRequest();

        $this->postJson('/api/admin/logout')->assertOk()->assertExactJson(['message' => '管理員登出成功'])
            ->assertSessionHas('member_marker', ['cart' => 'preserve']);
        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame('', $session->getHandler()->read($oldId));
        $this->assertSame($oldToken, $session->token());
        $newId = $session->getId();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->nextRequest();
        $this->postJson('/api/admin/logout')->assertUnauthorized();
        $this->nextRequest();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->assertDatabaseHas('carts', ['id' => $cart->id, 'user_id' => $user->id]);

        // 銷毀舊 ID 後，重播舊 cookie 不能取得 Admin 或會員。
        $this->nextRequest($oldId);
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->nextRequest($oldId);
        $this->getJson('/api/me')->assertUnauthorized();
        $this->nextRequest($newId);
        $this->getJson('/api/me')->assertOk();
    }

    #[DataProvider('nonActiveStatuses')]
    public function test_existing_disabled_session_is_revoked_without_revoking_member_then_requires_login(string $status): void
    {
        $admin = $this->admin();
        $user = $this->loginBoth($admin);
        $session = app('session.store');
        $session->put('member_marker', 'preserve');
        $session->save();
        $oldId = $session->getId();
        $oldToken = $session->token();
        DB::table('admins')->where('id', $admin->id)->update(['status' => $status]);
        $this->nextRequest();

        $this->getJson('/api/admin/me')->assertForbidden()->assertExactJson($this->disabledResponse())
            ->assertSessionHas('member_marker', 'preserve');
        $this->assertGuest('admin');
        $this->assertNotSame($oldId, $session->getId());
        $this->assertSame($oldToken, $session->token());
        $this->assertSame('', $session->getHandler()->read($oldId));
        DB::table('admins')->where('id', $admin->id)->update(['status' => 'active']);
        $currentId = $session->getId();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->nextRequest();
        $this->postJson('/api/admin/logout')->assertUnauthorized();
        $this->nextRequest();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->nextRequest($oldId);
        $this->getJson('/api/admin/me')->assertUnauthorized();
        $this->nextRequest($currentId);
        $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->login($admin);
        $this->getJson('/api/admin/me')->assertOk();
    }

    public function test_active_middleware_refreshes_db_even_if_guard_has_cached_active_object(): void
    {
        $admin = $this->admin();
        $this->login($admin);
        $this->getJson('/api/admin/me')->assertOk();
        // 故意不清 guard cache，證明 middleware 不信先前物件的 active。
        DB::table('admins')->where('id', $admin->id)->update(['status' => 'disabled']);
        $this->withCookie(config('session.cookie'), app('session.store')->getId());
        $this->getJson('/api/admin/me')->assertForbidden()->assertExactJson($this->disabledResponse());
    }

    public function test_disabled_admin_can_logout_before_active_middleware_revokes_them(): void
    {
        $admin = $this->admin();
        $this->login($admin);
        DB::table('admins')->where('id', $admin->id)->update(['status' => 'disabled']);
        $this->postJson('/api/admin/logout')->assertOk();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_member_logout_invalidates_admin_in_shared_session(): void
    {
        $this->loginBoth();
        $this->nextRequest();
        $this->postJson('/api/logout')->assertOk();
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_member_c06_invalidates_admin_in_shared_session(): void
    {
        $user = $this->loginBoth();
        DB::table('users')->where('id', $user->id)->update(['status' => 'disabled']);
        $this->nextRequest();
        $this->getJson('/api/me')->assertForbidden()->assertJsonPath('code', 'ACCOUNT_DISABLED');
        $this->nextRequest();
        $this->getJson('/api/admin/me')->assertUnauthorized();
    }

    public function test_real_csrf_validation_login_rejects_invalid_token_and_logout_preserves_member_csrf(): void
    {
        $csrf = new class($this->app, $this->app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        };
        $this->app->instance(PreventRequestForgery::class, $csrf);
        config(['sanctum.middleware.validate_csrf_token' => PreventRequestForgery::class]);
        $admin = $this->admin();
        $user = User::factory()->create(['password' => 'member-secret']);
        $cookieResponse = $this->get('/sanctum/csrf-cookie')->assertNoContent();
        $this->nextRequest();
        $this->withHeader('X-XSRF-TOKEN', 'invalid-token');
        $this->postJson('/api/admin/login', $this->credentials($admin))->assertStatus(419);
        $this->assertGuest('admin');
        $this->nextRequest();
        $this->withHeader('X-XSRF-TOKEN', $cookieResponse->getCookie('XSRF-TOKEN', false)->getValue());
        $memberLogin = $this->postJson('/api/login', ['email' => $user->email, 'password' => 'member-secret'])->assertOk();
        $this->nextRequest();
        $this->withHeader('X-XSRF-TOKEN', $memberLogin->getCookie('XSRF-TOKEN', false)->getValue());
        $adminLogin = $this->postJson('/api/admin/login', $this->credentials($admin))->assertOk();
        $tokenBeforeLogout = app('session.store')->token();
        $this->nextRequest();
        $this->withHeader('X-XSRF-TOKEN', $adminLogin->getCookie('XSRF-TOKEN', false)->getValue());
        $this->postJson('/api/admin/logout')->assertOk();
        $afterLogoutToken = app('session.store')->token();
        $this->assertSame($tokenBeforeLogout, $afterLogoutToken);
        $this->nextRequest();
        // 沿用 logout 前的 XSRF header，而非重新取 token，會員異動仍通過。
        $this->patchJson('/api/me', ['name' => '仍可修改會員', 'email' => $user->email, 'phone' => '0912345678'])
            ->assertOk()->assertJsonPath('data.name', '仍可修改會員');
        $this->assertSame($afterLogoutToken, app('session.store')->token());
    }

    private function admin(array $attributes = []): Admin
    {
        return Admin::factory()->create($attributes + ['password' => 'admin-secret']);
    }

    private function credentials(Admin $admin): array
    {
        return ['email' => $admin->email, 'password' => 'admin-secret'];
    }

    private function identity(Admin $admin): array
    {
        return ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email, 'status' => $admin->status, 'permissions' => []];
    }

    private function disabledResponse(): array
    {
        return ['code' => 'ADMIN_ACCOUNT_DISABLED', 'message' => '此管理員帳號已停用，請聯絡管理員'];
    }

    private function nextRequest(?string $sessionId = null): void
    {
        $this->withCredentials()->withCookie(config('session.cookie'), $sessionId ?? app('session.store')->getId());
        app('session.store')->flush();
        Auth::forgetGuards();
        // Feature Test 共用 app；模擬新 HTTP request 從原始 default web 開始。
        // auth:admin 的 shouldUse 不應洩漏到下一個模擬請求。
        Auth::shouldUse('web');
    }

    private function login(Admin $admin): void
    {
        $this->postJson('/api/admin/login', $this->credentials($admin))->assertOk();
        $this->nextRequest();
    }

    private function loginBoth(?Admin $admin = null): User
    {
        $user = User::factory()->create(['password' => 'member-secret']);
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'member-secret'])->assertOk();
        $this->nextRequest();
        $this->login($admin ?? $this->admin());
        $this->getJson('/api/admin/me')->assertOk();
        $this->nextRequest();
        $this->getJson('/api/me')->assertOk()->assertJsonPath('data.id', $user->id);

        return $user;
    }
}
